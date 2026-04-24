<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

use CodexAuthProxy\Account\AccountRepository;
use CodexAuthProxy\Account\CodexAccount;
use CodexAuthProxy\Auth\TokenRefresher;
use CodexAuthProxy\Codex\CodexRuntimeProfile;
use CodexAuthProxy\Network\OutboundProxyConfig;
use CodexAuthProxy\Observability\RequestIdFactory;
use CodexAuthProxy\Observability\RequestTraceLogger;
use CodexAuthProxy\Routing\ErrorClassification;
use CodexAuthProxy\Routing\ErrorClassifier;
use CodexAuthProxy\Routing\Scheduler;
use CodexAuthProxy\Routing\StateStore;
use CodexAuthProxy\Usage\AccountUsageRefresher;
use CodexAuthProxy\Usage\CodexUsageClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Timer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Throwable;

final class CodexProxyServer
{
    private readonly LoggerInterface $activeLogger;
    private readonly TokenRefresher $activeTokenRefresher;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $accountsDir,
        private readonly string $stateFile,
        private readonly int $defaultCooldownSeconds,
        private readonly string $upstreamBase,
        private readonly CodexRuntimeProfile $runtimeProfile,
        private readonly string $usageBaseUrl,
        ?LoggerInterface $logger = null,
        ?TokenRefresher $tokenRefresher = null,
        private readonly ?OutboundProxyConfig $outboundProxyConfig = null,
        private readonly ?RequestTraceLogger $requestTraceLogger = null,
        private readonly RequestIdFactory $requestIdFactory = new RequestIdFactory(),
        private readonly bool $traceMutations = true,
        private readonly bool $traceTimings = false,
        private readonly int $websocketSessionIdleTtlSeconds = 300,
        private readonly int $websocketSessionSweepIntervalSeconds = 30,
        private readonly int $usageRefreshIntervalSeconds = 600,
    ) {
        $this->activeLogger = $logger ?? new NullLogger();
        $this->activeTokenRefresher = $tokenRefresher ?? new TokenRefresher(proxy: $outboundProxyConfig?->guzzleProxy() ?? []);
    }

    public function start(): void
    {
        $this->assertSwooleLoaded();

        $repository = new AccountRepository($this->accountsDir);
        $accounts = $repository->load();
        $scheduler = new Scheduler($accounts, StateStore::file($this->stateFile));
        $classifier = new ErrorClassifier($this->defaultCooldownSeconds);
        $extractor = new SessionKeyExtractor();
        $headers = new UpstreamHeaderFactory($this->runtimeProfile);
        $payloadNormalizer = new ResponsesPayloadNormalizer();
        $normalizer = new ResponsesWebSocketNormalizer();
        $retryTracker = new WebSocketRetryTracker();
        $sessionRegistry = new CodexWebSocketSessionRegistry();
        $this->activeLogger->info('Loaded Codex accounts', ['count' => count($accounts)]);

        $server = $this->makeServer();
        $this->registerServerCallbacks(
            $server,
            $scheduler,
            $classifier,
            $repository,
            $extractor,
            $headers,
            $payloadNormalizer,
            $normalizer,
            $retryTracker,
            $sessionRegistry,
        );

        $server->start();
    }

    private function assertSwooleLoaded(): void
    {
        if (!extension_loaded('swoole')) {
            throw new RuntimeException('The swoole extension is required for serve');
        }
    }

    private function makeServer(): Server
    {
        $server = new Server($this->host, $this->port);

        $server->set($this->serverSettings());

        return $server;
    }

    /** @return array<string,bool|int> */
    private function serverSettings(): array
    {
        return [
            'worker_num' => 1,
            'enable_coroutine' => true,
            'http_compression' => false,
            'websocket_compression' => false,
        ];
    }

    private function registerServerCallbacks(
        Server $server,
        Scheduler $scheduler,
        ErrorClassifier $classifier,
        AccountRepository $repository,
        SessionKeyExtractor $extractor,
        UpstreamHeaderFactory $headers,
        ResponsesPayloadNormalizer $payloadNormalizer,
        ResponsesWebSocketNormalizer $normalizer,
        WebSocketRetryTracker $retryTracker,
        CodexWebSocketSessionRegistry $sessionRegistry,
    ): void {
        $sessionSweepTimerId = null;
        $usageRefreshTimerId = null;
        $server->on('open', static function (Server $server, Request $request) use ($sessionRegistry): void {
            $fd = (int) ($request->fd ?? 0);
            if ($fd > 0) {
                $sessionRegistry->rememberRequest($fd, $request);
            }
        });

        $server->on('message', function (Server $server, Frame $frame) use ($sessionRegistry, $scheduler, $classifier, $repository, $extractor, $headers, $normalizer, $retryTracker): void {
            $this->handleWebSocketMessage($server, $frame, $sessionRegistry, $scheduler, $classifier, $repository, $extractor, $headers, $normalizer, $retryTracker);
        });

        $server->on('close', static function (Server $server, int $fd) use ($sessionRegistry, $retryTracker): void {
            $retryTracker->clear($fd);
            $sessionRegistry->clear($fd)?->close();
        });

        $server->on('request', function (Request $request, Response $response) use ($scheduler, $classifier, $repository, $extractor, $headers, $payloadNormalizer): void {
            $this->handleHttp($request, $response, $scheduler, $classifier, $repository, $extractor, $headers, $payloadNormalizer);
        });

        $server->on('workerStart', function () use (&$sessionSweepTimerId, &$usageRefreshTimerId, $sessionRegistry, $repository, $scheduler): void {
            if ($this->websocketSessionIdleTtlSeconds <= 0 || $this->websocketSessionSweepIntervalSeconds <= 0) {
                $this->startUsageRefreshTimer($repository, $scheduler, $usageRefreshTimerId);
                return;
            }

            $sessionSweepTimerId = Timer::tick($this->websocketSessionSweepIntervalSeconds * 1000, function () use ($sessionRegistry): void {
                foreach ($sessionRegistry->sweepIdle($this->websocketSessionIdleTtlSeconds) as $client) {
                    $client->close();
                }
            });
            $this->startUsageRefreshTimer($repository, $scheduler, $usageRefreshTimerId);
        });

        $server->on($this->shutdownEvent(), function () use ($sessionRegistry, &$sessionSweepTimerId, &$usageRefreshTimerId): void {
            if (is_int($sessionSweepTimerId)) {
                Timer::clear($sessionSweepTimerId);
                $sessionSweepTimerId = null;
            }
            if (is_int($usageRefreshTimerId)) {
                Timer::clear($usageRefreshTimerId);
                $usageRefreshTimerId = null;
            }
            foreach ($sessionRegistry->clearAll() as $client) {
                $client->close();
            }
            $this->activeLogger->info('Codex auth proxy stopped');
        });
    }

    private function shutdownEvent(): string
    {
        return 'shutdown';
    }

    private function startUsageRefreshTimer(AccountRepository $repository, Scheduler $scheduler, ?int &$timerId): void
    {
        if ($this->usageRefreshIntervalSeconds <= 0) {
            return;
        }

        $running = false;
        $refresh = function () use ($repository, $scheduler, &$running): void {
            if ($running) {
                return;
            }
            $running = true;
            Coroutine::create(function () use ($repository, $scheduler, &$running): void {
                try {
                    $summary = $this->accountUsageRefresher($repository, $scheduler)
                        ->refreshAll($repository, StateStore::file($this->stateFile), time());
                    $this->syncSchedulerAccounts($repository, $scheduler);
                    $this->activeLogger->info('Refreshed Codex account usage snapshots', $summary);
                } catch (Throwable $throwable) {
                    $this->activeLogger->warning('Failed to refresh Codex account usage snapshots', [
                        'error' => $throwable->getMessage(),
                    ]);
                } finally {
                    $running = false;
                }
            });
        };

        $refresh();
        $timerId = Timer::tick($this->usageRefreshIntervalSeconds * 1000, $refresh);
    }

    private function accountUsageRefresher(AccountRepository $repository, Scheduler $scheduler): AccountUsageRefresher
    {
        return new AccountUsageRefresher(
            new CodexUsageClient(
                baseUrl: $this->usageBaseUrl,
                runtimeProfile: $this->runtimeProfile,
                proxyEnv: $this->outboundProxyConfig?->environment() ?? [],
            ),
            fn (CodexAccount $account): CodexAccount => $this->freshAccount($account, $repository, $scheduler),
            $this->activeLogger,
        );
    }

    private function handleHttp(
        Request $request,
        Response $response,
        Scheduler $scheduler,
        ErrorClassifier $classifier,
        AccountRepository $repository,
        SessionKeyExtractor $extractor,
        UpstreamHeaderFactory $headers,
        ResponsesPayloadNormalizer $payloadNormalizer,
    ): void {
        $path = $request->server['request_uri'] ?? '/';
        if ($path === '/health') {
            $response->header('Content-Type', 'application/json');
            $response->end('{"status":"ok"}');
            return;
        }

        $rawBody = $request->rawContent() ?: '';
        $requestId = $this->requestIdFactory->fromHeaders($request->header ?? []);
        $sessionKey = $extractor->extract($request->header ?? [], $rawBody);
        $normalizedBody = $payloadNormalizer->normalizeHttpWithReport($rawBody);
        $body = $normalizedBody->payload();
        $requestStartedAt = microtime(true);
        $timings = [
            'scheduler_reload' => 0.0,
            'account_prepare' => 0.0,
            'upstream' => 0.0,
        ];
        $attempts = 0;
        $this->tracePayloadMutations($requestId, 'http', $sessionKey, $normalizedBody->mutations());
        try {
            $accountPrepareStartedAt = microtime(true);
            $selectionTimings = [];
            $account = $this->freshAccount($this->accountForSessionWithRecovery($sessionKey, $scheduler, $repository, $selectionTimings), $repository, $scheduler);
            $timings['scheduler_reload'] += (float) ($selectionTimings['scheduler_reload'] ?? 0.0);
            $timings['account_prepare'] += $this->elapsedMs($accountPrepareStartedAt);
            $authRefreshAttempts = [];
            while (true) {
                $result = $this->forward($request, $response, $account, $body, $headers, false);
                $attempts++;
                $attemptUpstreamMs = $result['timings']['upstream'];
                $attemptFirstByteMs = $result['timings']['first_byte'];
                $timings['upstream'] += $attemptUpstreamMs;
                $classification = $classifier->classifyHttpResponse($result['status'], $result['body'], $result['headers']);

                if (!$classification->hardSwitch()) {
                    if ($result['status'] >= 400) {
                        $this->traceUpstreamError($requestId, 'http', 'upstream_response', $sessionKey, $account, $result['status'], $result['body'], $classification->type());
                    }
                    $this->traceRequestTiming(
                        $requestId,
                        'http',
                        'request_completed',
                        $sessionKey,
                        $account,
                        $result['status'],
                        $classification->type(),
                        $attempts,
                        $this->timingPayload(
                            $timings,
                            $requestStartedAt,
                            $attemptFirstByteMs !== null ? ($timings['upstream'] - $attemptUpstreamMs + $attemptFirstByteMs) : null,
                        ),
                    );
                    $this->finishBufferedError($response, $result);
                    return;
                }

                $refreshed = $this->refreshAccountAfterAuthFailure($classification, $account, $repository, $scheduler, $authRefreshAttempts);
                if ($refreshed !== null) {
                    $account = $refreshed;
                    continue;
                }

                $cooldownSeconds = max(1, $classification->cooldownUntil() - time());
                $this->activeLogger->warning('Switching Codex account after hard failure', [
                    'request_id' => $requestId,
                    'session' => $sessionKey->primary,
                    'type' => $classification->type(),
                    'cooldown_seconds' => $cooldownSeconds,
                ]);
                $this->traceUpstreamError($requestId, 'http', 'hard_switch', $sessionKey, $account, $result['status'], $result['body'], $classification->type());
                $accountPrepareStartedAt = microtime(true);
                $selectionTimings = [];
                $account = $this->freshAccount(
                    $this->switchAfterHardFailureWithRecovery($sessionKey, $cooldownSeconds, $classification->type(), $scheduler, $repository, $selectionTimings),
                    $repository,
                    $scheduler,
                );
                $timings['scheduler_reload'] += (float) ($selectionTimings['scheduler_reload'] ?? 0.0);
                $timings['account_prepare'] += $this->elapsedMs($accountPrepareStartedAt);
            }
        } catch (RuntimeException $exception) {
            $this->activeLogger->warning('Codex proxy request unavailable', [
                'request_id' => $requestId,
                'error' => $exception->getMessage(),
            ]);
            $this->traceRequestTiming(
                $requestId,
                'http',
                'request_completed',
                $sessionKey,
                null,
                503,
                'unavailable',
                $attempts,
                $this->timingPayload($timings, $requestStartedAt, null),
                $exception->getMessage(),
            );
            $this->finishProxyUnavailable($response, $exception->getMessage());
        }
    }

    private function handleWebSocketMessage(
        Server $server,
        Frame $frame,
        CodexWebSocketSessionRegistry $sessionRegistry,
        Scheduler $scheduler,
        ErrorClassifier $classifier,
        AccountRepository $repository,
        SessionKeyExtractor $extractor,
        UpstreamHeaderFactory $headers,
        ResponsesWebSocketNormalizer $normalizer,
        WebSocketRetryTracker $retryTracker,
    ): void {
        $fd = (int) $frame->fd;
        $request = $sessionRegistry->request($fd);
        if ($request === null) {
            $this->pushWebSocketError($server, $fd, 400, 'Missing WebSocket handshake request');
            return;
        }

        $rawPayload = (string) $frame->data;
        $requestId = $this->requestIdFactory->fromHeaders($request->header ?? []);
        $sessionKey = $extractor->extract($request->header ?? [], $rawPayload);
        $executionSessionKey = $extractor->extractExecutionSession($request->header ?? [], $rawPayload);
        $session = $sessionRegistry->bindSession($fd, $executionSessionKey);
        $sessionRegistry->abortActiveRequestForNewFd($fd)?->close();
        $sessionId = $session->sessionId();
        $client = $sessionRegistry->client($fd);
        $account = $sessionRegistry->account($fd);
        $normalizedPayload = $normalizer->normalizeWithReport($rawPayload);
        $payload = $normalizedPayload->payload();
        if (!$sessionRegistry->waitForRequestTurn($fd)) {
            return;
        }
        $this->tracePayloadMutations($requestId, 'websocket', $sessionKey, $normalizedPayload->mutations());
        $retryTracker->beginPayload($fd, $payload);
        $sessionRegistry->rememberPayload($fd, $sessionKey, $payload, (int) $frame->opcode);
        $sessionRegistry->markRequestActive($fd, true);
        if ($client === null || !$account instanceof CodexAccount) {
            $account = null;
            $authRefreshAttempts = [];
            $requestStartedAt = microtime(true);
            $timings = [
                'scheduler_reload' => 0.0,
                'account_prepare' => 0.0,
                'upstream_upgrade' => 0.0,
            ];
            try {
                $accountPrepareStartedAt = microtime(true);
                $selectionTimings = [];
                $account = $this->freshAccount($this->accountForSessionWithRecovery($sessionKey, $scheduler, $repository, $selectionTimings), $repository, $scheduler);
                $timings['scheduler_reload'] += (float) ($selectionTimings['scheduler_reload'] ?? 0.0);
                $timings['account_prepare'] += $this->elapsedMs($accountPrepareStartedAt);
                $upgradeStartedAt = microtime(true);
                [$client, $account] = $this->openUpstreamWebSocketWithRecovery(
                    $request,
                    $account,
                    $headers,
                    $classifier,
                    $sessionKey,
                    $scheduler,
                    $repository,
                    $requestId,
                    $authRefreshAttempts,
                );
                $timings['upstream_upgrade'] = $this->elapsedMs($upgradeStartedAt);

                $sessionRegistry->attachUpstream($fd, $client, $account);
                $this->traceRequestTiming(
                    $requestId,
                    'websocket',
                    'websocket_opened',
                    $sessionKey,
                    $account,
                    101,
                    'none',
                    1,
                    $this->timingPayload($timings, $requestStartedAt, null),
                );
                $this->startUpstreamWebSocketReader($server, $sessionId, $client, $sessionRegistry, $scheduler, $classifier, $repository, $headers, $retryTracker);
            } catch (Throwable $throwable) {
                $this->activeLogger->warning('Upstream WebSocket failed', ['request_id' => $requestId, 'error' => $throwable->getMessage()]);
                if ($account instanceof CodexAccount) {
                    $this->traceUpstreamError($requestId, 'websocket', 'upstream_upgrade', $sessionKey, $account, 502, $throwable->getMessage(), 'transport');
                }
                $this->traceRequestTiming(
                    $requestId,
                    'websocket',
                    'websocket_opened',
                    $sessionKey,
                    $account instanceof CodexAccount ? $account : null,
                    502,
                    'transport',
                    1,
                    $this->timingPayload($timings, $requestStartedAt, null),
                    $throwable->getMessage(),
                );
                if ($account instanceof CodexAccount && $this->shouldFallbackWebSocketUpgradeToHttp($throwable, $classifier)) {
                    $fallbackPayload = $normalizer->normalizeForHttpFallbackWithReport($payload);
                    $this->tracePayloadMutations($requestId, 'websocket', $sessionKey, $fallbackPayload->mutations());
                    $this->activeLogger->warning('Falling back WebSocket request to HTTP/SSE upstream', [
                        'request_id' => $requestId,
                        'session' => $sessionKey->primary,
                        'account' => $account->name(),
                        'error' => $throwable->getMessage(),
                    ]);
                    try {
                        $this->forwardWebSocketPayloadOverHttp(
                            $server,
                            $fd,
                            $request,
                            $account,
                            $fallbackPayload->payload(),
                            $headers,
                            $classifier,
                            $sessionKey,
                            $scheduler,
                            $repository,
                            $requestId,
                        );
                    } catch (Throwable $fallbackThrowable) {
                        $this->activeLogger->warning('WebSocket HTTP/SSE fallback failed', [
                            'request_id' => $requestId,
                            'error' => $fallbackThrowable->getMessage(),
                        ]);
                        $sessionRegistry->markRequestActive($fd, false);
                        $sessionRegistry->releaseRequestTurn($fd);
                        $this->pushWebSocketError($server, $fd, 502, $fallbackThrowable->getMessage());
                    }
                    $sessionRegistry->markRequestActive($fd, false);
                    $sessionRegistry->releaseRequestTurn($fd);
                    return;
                }
                $sessionRegistry->markRequestActive($fd, false);
                $sessionRegistry->releaseRequestTurn($fd);
                $this->pushWebSocketError($server, $fd, 502, $throwable->getMessage());
                return;
            }
        }

        if ($this->pushUpstreamWebSocketPayload($client, $payload, (int) $frame->opcode)) {
            return;
        }

        $this->activeLogger->warning('Upstream WebSocket send failed before response payload', [
            'request_id' => $requestId,
            'session' => $sessionKey->primary,
            'account' => $account->name(),
            'error' => $this->upstreamClientError('WebSocket send', $client),
        ]);
        if (!$retryTracker->claimRetry($fd, $payload, $account->accountId(), false)) {
            $sessionRegistry->markRequestActive($fd, false);
            $sessionRegistry->releaseRequestTurn($fd);
            $this->pushWebSocketError($server, $fd, 502, $this->upstreamClientError('WebSocket send', $client));
            return;
        }

        try {
            $authRefreshAttempts = [];
            [$replacementClient, $account] = $this->openUpstreamWebSocketWithRecovery(
                $request,
                $this->freshAccount($account, $repository, $scheduler),
                $headers,
                $classifier,
                $sessionKey,
                $scheduler,
                $repository,
                $requestId,
                $authRefreshAttempts,
            );
            $client->close();
            $sessionRegistry->attachUpstream($fd, $replacementClient, $account);
            $this->startUpstreamWebSocketReader($server, $sessionId, $replacementClient, $sessionRegistry, $scheduler, $classifier, $repository, $headers, $retryTracker);
            if ($this->pushUpstreamWebSocketPayload($replacementClient, $payload, (int) $frame->opcode)) {
                return;
            }
            $sessionRegistry->detachUpstreamFromSession($sessionId, $replacementClient);
            $replacementClient->close();
            $sessionRegistry->markRequestActive($fd, false);
            $sessionRegistry->releaseRequestTurn($fd);
            $this->pushWebSocketError($server, $fd, 502, $this->upstreamClientError('WebSocket send', $replacementClient));
        } catch (Throwable $throwable) {
            $sessionRegistry->markRequestActive($fd, false);
            $sessionRegistry->releaseRequestTurn($fd);
            $this->pushWebSocketError($server, $fd, 502, $throwable->getMessage());
        }
    }

    /** @return array{status:int,body:string,headers:array<string,string>,streamed:bool,timings:array{upstream:float,first_byte:?float}} */
    private function forward(Request $request, Response $response, CodexAccount $account, string $body, UpstreamHeaderFactory $headers, bool $forceBuffer): array
    {
        $target = new UpstreamTarget($this->upstreamBase);
        [$host, $port, $ssl] = $target->endpoint();
        $headersSent = false;
        $bodyBuffer = new UpstreamResponseBodyBuffer($forceBuffer);
        $executeStartedAt = 0.0;
        $firstByteAt = null;

        $client = new Client($host, $port, $ssl);
        $client->set($this->clientOptionsFor($host, [
            'timeout' => -1,
            'write_func' => function (Client $client, string $chunk) use (&$headersSent, $response, $bodyBuffer, &$firstByteAt): int {
                $firstByteAt ??= microtime(true);
                foreach ($bodyBuffer->write((int) $client->statusCode, $client->headers ?? [], $chunk) as $frame) {
                    if (!$headersSent) {
                        $this->copyResponseHeaders($response, $client->headers ?? [], $client->statusCode);
                        $headersSent = true;
                    }
                    $response->write($frame);
                }

                return strlen($chunk);
            },
        ]));
        $requestUri = $this->requestTarget($request, '/v1/responses');
        $client->setHeaders($headers->build($request->header ?? [], $account, $host, false, $this->httpAcceptFor($requestUri)));
        $client->setMethod(strtoupper((string) ($request->server['request_method'] ?? 'GET')));
        if ($body !== '') {
            $client->setData($body);
        }

        $path = $target->pathFor($requestUri);
        $executeStartedAt = microtime(true);
        $client->execute($path);
        $statusCode = (int) $client->statusCode;
        $status = $statusCode > 0 ? $statusCode : 502;
        $responseHeaders = is_array($client->headers ?? null) ? $client->headers : [];
        $buffer = $bodyBuffer->body();
        if ($statusCode <= 0) {
            $buffer = $this->upstreamClientError('HTTP request', $client);
            $responseHeaders = ['Content-Type' => 'text/plain; charset=utf-8'];
        }
        foreach ($bodyBuffer->flush($responseHeaders) as $frame) {
            if (!$headersSent) {
                $this->copyResponseHeaders($response, $responseHeaders, $status);
                $headersSent = true;
            }
            $response->write($frame);
        }
        if (!$bodyBuffer->streamed() && $buffer === '' && is_string($client->body ?? null)) {
            $firstByteAt ??= microtime(true);
            $buffer = $client->body;
        }
        $incompleteStream = $this->httpAcceptFor($requestUri) === 'text/event-stream'
            && $status < 400
            && !$bodyBuffer->completed();
        if ($bodyBuffer->streamed() && $incompleteStream) {
            if (!$headersSent) {
                $this->copyResponseHeaders($response, $responseHeaders, $status);
                $headersSent = true;
            }
            $response->write($this->incompleteHttpStreamErrorFrame());
        }
        if (!$bodyBuffer->streamed() && $incompleteStream) {
            $status = 502;
            $responseHeaders = ['Content-Type' => 'application/json'];
            $buffer = $this->incompleteStreamErrorPayload('upstream_stream_incomplete', 502);
        }
        if ($bodyBuffer->streamed()) {
            $response->end();
        }
        $upstreamMs = $this->elapsedMs($executeStartedAt);
        $firstByteMs = $firstByteAt !== null ? $this->elapsedMs($executeStartedAt, $firstByteAt) : null;
        $client->close();

        return [
            'status' => $status,
            'body' => $buffer,
            'headers' => $responseHeaders,
            'streamed' => $bodyBuffer->streamed(),
            'timings' => [
                'upstream' => $upstreamMs,
                'first_byte' => $firstByteMs,
            ],
        ];
    }

    private function forwardWebSocketPayloadOverHttp(
        Server $server,
        int $fd,
        Request $request,
        CodexAccount $account,
        string $body,
        UpstreamHeaderFactory $headers,
        ErrorClassifier $classifier,
        SessionKey $sessionKey,
        Scheduler $scheduler,
        AccountRepository $repository,
        string $requestId,
    ): void {
        $authRefreshAttempts = [];
        while (true) {
            $result = $this->forwardWebSocketHttpAttempt($server, $fd, $request, $account, $body, $headers);
            $classification = $classifier->classifyHttpResponse($result['status'], $result['body'], $result['headers']);
            if (!$classification->hardSwitch() || $result['forwarded']) {
                if (!$result['forwarded'] && $this->pushBufferedErrorAsWebSocket($server, $fd, $result['body'])) {
                    return;
                }
                if ($result['status'] >= 400 && !$result['forwarded']) {
                    $this->traceUpstreamError($requestId, 'websocket', 'http_fallback_response', $sessionKey, $account, $result['status'], $result['body'], $classification->type());
                    $this->pushWebSocketError($server, $fd, $result['status'], $result['body']);
                }
                return;
            }

            $refreshed = $this->refreshAccountAfterAuthFailure($classification, $account, $repository, $scheduler, $authRefreshAttempts);
            if ($refreshed !== null) {
                $account = $refreshed;
                continue;
            }

            $cooldownSeconds = max(1, $classification->cooldownUntil() - time());
            $this->traceUpstreamError($requestId, 'websocket', 'http_fallback_hard_switch', $sessionKey, $account, $result['status'], $result['body'], $classification->type());
            $account = $this->freshAccount(
                $this->switchAfterHardFailureWithRecovery($sessionKey, $cooldownSeconds, $classification->type(), $scheduler, $repository),
                $repository,
                $scheduler,
            );
        }
    }

    /** @return array{status:int,body:string,headers:array<string,string>,forwarded:bool,completed:bool} */
    private function forwardWebSocketHttpAttempt(Server $server, int $fd, Request $request, CodexAccount $account, string $body, UpstreamHeaderFactory $headers): array
    {
        $target = new UpstreamTarget($this->upstreamBase);
        [$host, $port, $ssl] = $target->endpoint();
        $bodyBuffer = new UpstreamResponseBodyBuffer(false);
        $forwarded = false;
        $completed = false;
        $terminalErrorSent = false;
        $seenRawBody = '';

        $client = new Client($host, $port, $ssl);
        $client->set($this->clientOptionsFor($host, [
            'timeout' => -1,
            'write_func' => function (Client $client, string $chunk) use ($server, $fd, $bodyBuffer, &$forwarded, &$completed, &$terminalErrorSent, &$seenRawBody): int {
                $seenRawBody .= $chunk;
                $responseHeaders = is_array($client->headers ?? null) ? $client->headers : [];
                foreach ($bodyBuffer->write((int) $client->statusCode, $responseHeaders, $chunk) as $frame) {
                    $pushed = $this->pushSseFrameAsWebSocket($server, $fd, $frame);
                    $forwarded = $forwarded || $pushed['forwarded'];
                    $completed = $completed || $pushed['completed'];
                    $terminalErrorSent = $terminalErrorSent || $pushed['error'];
                }

                return strlen($chunk);
            },
        ]));
        $requestUri = $this->requestTarget($request, '/v1/responses');
        $client->setHeaders($headers->build($request->header ?? [], $account, $host, false, 'text/event-stream'));
        $client->setMethod('POST');
        if ($body !== '') {
            $client->setData($body);
        }

        $client->execute($target->pathFor($requestUri));
        $statusCode = (int) $client->statusCode;
        $status = $statusCode > 0 ? $statusCode : 502;
        $responseHeaders = is_array($client->headers ?? null) ? $client->headers : [];
        $buffer = $bodyBuffer->body();
        if ($statusCode <= 0) {
            $buffer = $this->upstreamClientError('HTTP fallback request', $client);
            $responseHeaders = ['Content-Type' => 'text/plain; charset=utf-8'];
        }

        $tailBody = is_string($client->body ?? null) ? $client->body : '';
        if ($tailBody !== '') {
            if ($seenRawBody !== '' && str_starts_with($tailBody, $seenRawBody)) {
                $tailBody = substr($tailBody, strlen($seenRawBody));
            } elseif ($seenRawBody !== '' && str_contains($seenRawBody, $tailBody)) {
                $tailBody = '';
            }
        }
        if ($tailBody !== '') {
            foreach ($bodyBuffer->write($statusCode, $responseHeaders, $tailBody) as $frame) {
                $pushed = $this->pushSseFrameAsWebSocket($server, $fd, $frame);
                $forwarded = $forwarded || $pushed['forwarded'];
                $completed = $completed || $pushed['completed'];
                $terminalErrorSent = $terminalErrorSent || $pushed['error'];
            }
        }
        foreach ($bodyBuffer->flush($responseHeaders) as $frame) {
            $pushed = $this->pushSseFrameAsWebSocket($server, $fd, $frame);
            $forwarded = $forwarded || $pushed['forwarded'];
            $completed = $completed || $pushed['completed'];
            $terminalErrorSent = $terminalErrorSent || $pushed['error'];
        }
        if (!$bodyBuffer->streamed() && $buffer === '' && is_string($client->body ?? null)) {
            $buffer = $client->body;
        }
        $completed = $completed || $bodyBuffer->completed();
        $bufferedError = StreamErrorDetector::jsonErrorBody($buffer) !== null;
        $client->close();

        if ($status < 400 && !$completed && !$terminalErrorSent && !$bufferedError) {
            $this->pushWebSocketError($server, $fd, 502, $this->incompleteStreamMessage());
            $forwarded = true;
        }

        return [
            'status' => $status,
            'body' => $buffer,
            'headers' => $responseHeaders,
            'forwarded' => $forwarded,
            'completed' => $completed,
        ];
    }

    private function pushBufferedErrorAsWebSocket(Server $server, int $fd, string $body): bool
    {
        $payload = StreamErrorDetector::jsonErrorBody($body);
        if ($payload === null || !$server->isEstablished($fd)) {
            return false;
        }

        $server->push($fd, $payload, \WEBSOCKET_OPCODE_TEXT);

        return true;
    }

    /** @return array{forwarded:bool,completed:bool,error:bool} */
    private function pushSseFrameAsWebSocket(Server $server, int $fd, string $frame): array
    {
        $forwarded = false;
        $completed = false;
        $error = false;
        foreach ($this->webSocketPayloadsFromSseFrame($frame) as $payload) {
            $payload = StreamErrorDetector::normalizeCompletedPayload($payload);
            $completed = $completed || StreamErrorDetector::isCompletedPayload($payload);
            $error = $error || StreamErrorDetector::jsonErrorBody($payload) !== null;
            if (!$server->isEstablished($fd)) {
                continue;
            }
            $server->push($fd, $payload, \WEBSOCKET_OPCODE_TEXT);
            $forwarded = true;
        }

        return [
            'forwarded' => $forwarded,
            'completed' => $completed,
            'error' => $error,
        ];
    }

    /** @return list<string> */
    private function webSocketPayloadsFromSseFrame(string $frame): array
    {
        $payloads = [];
        $dataLines = [];
        foreach (preg_split('/\R/', $frame) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'event:')) {
                continue;
            }
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = trim(substr($line, strlen('data:')));
            }
        }

        foreach ($dataLines as $line) {
            if ($line === '' || $line === '[DONE]') {
                continue;
            }
            if (json_decode($line, true) !== null || json_last_error() === JSON_ERROR_NONE) {
                $payloads[] = $line;
            }
        }

        if ($payloads !== []) {
            return $payloads;
        }

        $trimmed = trim($frame);
        if (str_starts_with($trimmed, 'data:')) {
            $trimmed = trim(substr($trimmed, strlen('data:')));
        }
        if ($trimmed !== '' && $trimmed !== '[DONE]' && (json_decode($trimmed, true) !== null || json_last_error() === JSON_ERROR_NONE)) {
            $payloads[] = $trimmed;
        }

        return $payloads;
    }

    /** @param array{status:int,body:string,headers:array<string,string>,streamed:bool,timings:array{upstream:float,first_byte:?float}} $result */
    private function finishBufferedError(Response $response, array $result): void
    {
        if ($result['streamed']) {
            return;
        }

        $this->copyResponseHeaders($response, $result['headers'], $result['status']);
        $response->end($result['body']);
    }

    private function finishProxyUnavailable(Response $response, string $message): void
    {
        $response->status(503);
        $response->header('Content-Type', 'application/json');
        $response->end($this->proxyUnavailablePayload($message));
    }

    private function proxyUnavailablePayload(string $message): string
    {
        return json_encode([
            'type' => 'error',
            'error' => [
                'code' => 'codex_proxy_unavailable',
                'message' => $message,
                'status' => 503,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function incompleteHttpStreamErrorFrame(): string
    {
        return "event: error\ndata: " . $this->incompleteStreamErrorPayload('upstream_stream_incomplete', 502) . "\n\n";
    }

    private function incompleteStreamErrorPayload(string $code, int $status): string
    {
        return json_encode([
            'type' => 'error',
            'error' => [
                'code' => $code,
                'message' => $this->incompleteStreamMessage(),
                'status' => $status,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function incompleteStreamMessage(): string
    {
        return 'stream disconnected before response.completed';
    }

    private function openUpstreamWebSocket(Request $request, CodexAccount $account, UpstreamHeaderFactory $headers): Client
    {
        $target = new UpstreamTarget($this->upstreamBase);
        [$host, $port, $ssl] = $target->endpoint();
        $client = new Client($host, $port, $ssl);
        $client->set($this->clientOptionsFor($host, ['timeout' => -1]));
        $client->setHeaders($headers->build($request->header ?? [], $account, $host, true));
        $path = $target->pathFor($this->requestTarget($request, '/v1/responses'));
        if (!$client->upgrade($path) || (int) $client->statusCode !== 101) {
            $body = is_string($client->body ?? null) ? $client->body : '';
            $status = (int) ($client->statusCode ?: 502);
            if ($client->statusCode === -1) {
                $body = $this->upstreamClientError('WebSocket upgrade', $client);
            }
            $client->close();
            throw new RuntimeException('Upstream WebSocket upgrade failed with status ' . $status . ': ' . $body);
        }

        return $client;
    }

    /**
     * @param array<string,bool> $authRefreshAttempts
     * @return array{0:Client,1:CodexAccount}
     */
    private function openUpstreamWebSocketWithRecovery(
        Request $request,
        CodexAccount $account,
        UpstreamHeaderFactory $headers,
        ErrorClassifier $classifier,
        SessionKey $sessionKey,
        Scheduler $scheduler,
        AccountRepository $repository,
        string $requestId,
        array &$authRefreshAttempts,
    ): array {
        while (true) {
            try {
                return [$this->openUpstreamWebSocket($request, $account, $headers), $account];
            } catch (Throwable $throwable) {
                $refreshed = $this->refreshAccountAfterWebSocketUpgradeFailure($throwable, $classifier, $account, $repository, $scheduler, $authRefreshAttempts);
                if ($refreshed !== null) {
                    $account = $refreshed;
                    continue;
                }

                $classification = $this->classifyWebSocketUpgradeFailure($throwable, $classifier);
                if (!$classification instanceof ErrorClassification || !$classification->hardSwitch()) {
                    throw $throwable;
                }

                $cooldownSeconds = max(1, $classification->cooldownUntil() - time());
                $failure = $this->webSocketUpgradeFailureResult($throwable);
                $this->activeLogger->warning('Switching Codex account after WebSocket upgrade hard failure', [
                    'request_id' => $requestId,
                    'session' => $sessionKey->primary,
                    'type' => $classification->type(),
                    'cooldown_seconds' => $cooldownSeconds,
                ]);
                $this->traceUpstreamError(
                    $requestId,
                    'websocket',
                    'upstream_upgrade',
                    $sessionKey,
                    $account,
                    $failure['status'] ?? 502,
                    $failure['body'] ?? $throwable->getMessage(),
                    $classification->type(),
                );
                $account = $this->freshAccount(
                    $this->switchAfterHardFailureWithRecovery($sessionKey, $cooldownSeconds, $classification->type(), $scheduler, $repository),
                    $repository,
                    $scheduler,
                );
            }
        }
    }

    /**
     * @param array<string,string|int|callable> $baseOptions
     * @return array<string,string|int|callable>
     */
    private function clientOptionsFor(string $host, array $baseOptions): array
    {
        $baseOptions['ssl_host_name'] = $host;
        $proxyOptions = $this->outboundProxyConfig?->swooleOptionsFor($host) ?? [];

        return array_merge($baseOptions, $proxyOptions);
    }

    private function upstreamClientError(string $operation, Client $client): string
    {
        $message = socket_strerror((int) $client->errCode);
        if ($message === '' || $message === 'Success') {
            $message = 'unknown error';
        }

        return $operation . ' failed: errCode=' . (int) $client->errCode . ' errMsg=' . $message;
    }

    private function startUpstreamWebSocketReader(
        Server $server,
        string $sessionId,
        Client $client,
        CodexWebSocketSessionRegistry $sessionRegistry,
        Scheduler $scheduler,
        ErrorClassifier $classifier,
        AccountRepository $repository,
        UpstreamHeaderFactory $headers,
        WebSocketRetryTracker $retryTracker,
    ): void
    {
        Coroutine::create(function () use ($server, $sessionId, $client, $sessionRegistry, $scheduler, $classifier, $repository, $headers, $retryTracker): void {
            $forwardedData = false;
            $replacedClient = false;
            $terminalErrorSent = false;
            while (true) {
                $frame = $client->recv();
                if ($frame === false || $frame === '') {
                    break;
                }
                if ($sessionRegistry->clientForSession($sessionId) !== $client) {
                    break;
                }

                $data = is_object($frame) && property_exists($frame, 'data') ? (string) $frame->data : (string) $frame;
                $opcode = is_object($frame) && property_exists($frame, 'opcode') ? (int) $frame->opcode : \WEBSOCKET_OPCODE_TEXT;
                if ($opcode === \WEBSOCKET_OPCODE_CLOSE) {
                    break;
                }
                if (!$sessionRegistry->hasActiveRequestForSession($sessionId)) {
                    continue;
                }
                $fd = $sessionRegistry->activeFdForSession($sessionId);
                if ($fd === null) {
                    continue;
                }
                $request = $sessionRegistry->request($fd);
                $account = $sessionRegistry->accountForSession($sessionId);
                if (!$request instanceof Request || !$account instanceof CodexAccount) {
                    continue;
                }
                $data = StreamErrorDetector::normalizeCompletedPayload($data);
                $completedSeen = StreamErrorDetector::isCompletedPayload($data);
                $errorBody = StreamErrorDetector::jsonErrorBody($data);
                if ($errorBody !== null) {
                    $lastPayload = $sessionRegistry->lastPayloadForSession($sessionId);
                    $sessionKey = $lastPayload['sessionKey'] ?? new SessionKey('global', null);
                    $requestId = $this->requestIdFactory->fromHeaders($request->header ?? []);
                    $classification = $this->traceWebSocketStreamError($requestId, $sessionKey, $account, $errorBody, $classifier);
                    if ($classification->hardSwitch()) {
                        $cooldownSeconds = max(1, $classification->cooldownUntil() - time());
                        if ($lastPayload !== null && $retryTracker->claimRetry($fd, $lastPayload['payload'], $account->accountId(), $forwardedData)) {
                            $replacement = null;
                            try {
                                $replacement = $this->freshAccount(
                                    $this->switchAfterHardFailureWithRecovery($lastPayload['sessionKey'], $cooldownSeconds, $classification->type(), $scheduler, $repository),
                                    $repository,
                                    $scheduler,
                                );
                                $authRefreshAttempts = [];
                                [$replacementClient, $replacement] = $this->openUpstreamWebSocketWithRecovery(
                                    $request,
                                    $replacement,
                                    $headers,
                                    $classifier,
                                    $lastPayload['sessionKey'],
                                    $scheduler,
                                    $repository,
                                    $requestId,
                                    $authRefreshAttempts,
                                );
                                $sessionRegistry->attachUpstreamToSession($sessionId, $replacementClient, $replacement);
                                $this->startUpstreamWebSocketReader($server, $sessionId, $replacementClient, $sessionRegistry, $scheduler, $classifier, $repository, $headers, $retryTracker);
                                if (!$this->pushUpstreamWebSocketPayload($replacementClient, $lastPayload['payload'], $lastPayload['opcode'])) {
                                    $sessionRegistry->detachUpstreamFromSession($sessionId, $replacementClient);
                                    $replacementClient->close();
                                    $sessionRegistry->markRequestActive($fd, false);
                                    $sessionRegistry->releaseRequestTurn($fd);
                                    $this->pushWebSocketError($server, $fd, 502, $this->upstreamClientError('WebSocket send', $replacementClient));
                                    $terminalErrorSent = true;
                                    break;
                                }
                                $replacedClient = true;
                                break;
                            } catch (Throwable $throwable) {
                                $this->activeLogger->warning('Replacement WebSocket failed', ['error' => $throwable->getMessage()]);
                                if (!$replacement instanceof CodexAccount) {
                                    if ($server->isEstablished($fd)) {
                                        $server->push($fd, $data, $opcode);
                                        $forwardedData = true;
                                    }
                                    $sessionRegistry->markRequestActive($fd, false);
                                    $sessionRegistry->releaseRequestTurn($fd);
                                    $terminalErrorSent = true;
                                    break;
                                }
                                $sessionRegistry->markRequestActive($fd, false);
                                $sessionRegistry->releaseRequestTurn($fd);
                                $this->pushWebSocketError($server, $fd, 502, $throwable->getMessage());
                                $terminalErrorSent = true;
                                break;
                            }
                        } else {
                            try {
                                $this->switchAfterHardFailureWithRecovery($sessionKey, $cooldownSeconds, $classification->type(), $scheduler, $repository);
                            } catch (Throwable $throwable) {
                                $this->activeLogger->warning('Failed to switch Codex account after WebSocket stream error', ['error' => $throwable->getMessage()]);
                            }
                        }
                    }

                    $sessionRegistry->markRequestActive($fd, false);
                    $sessionRegistry->releaseRequestTurn($fd);
                    $terminalErrorSent = true;
                }

                if ($server->isEstablished($fd)) {
                    $server->push($fd, $data, $opcode);
                    $forwardedData = true;
                }

                if ($completedSeen) {
                    $sessionRegistry->markRequestActive($fd, false);
                    $sessionRegistry->releaseRequestTurn($fd);
                    $forwardedData = false;
                    $terminalErrorSent = false;
                    continue;
                }
            }

            $clientStillCurrent = $sessionRegistry->clientForSession($sessionId) === $client;
            $fd = $sessionRegistry->activeFdForSession($sessionId);
            $request = $fd !== null ? $sessionRegistry->request($fd) : null;
            $account = $sessionRegistry->accountForSession($sessionId);
            $sessionRegistry->detachUpstreamFromSession($sessionId, $client);
            $client->close();
            if (!$clientStillCurrent) {
                return;
            }

            if ($replacedClient) {
                return;
            }
            if (!$sessionRegistry->hasActiveRequestForSession($sessionId)) {
                return;
            }
            if ($fd === null || !$server->isEstablished($fd)) {
                return;
            }
            if (!$request instanceof Request || !$account instanceof CodexAccount) {
                return;
            }
            if ($terminalErrorSent) {
                $sessionRegistry->markRequestActive($fd, false);
                $sessionRegistry->releaseRequestTurn($fd);
                return;
            }
            if (!$forwardedData) {
                $lastPayload = $sessionRegistry->lastPayloadForSession($sessionId);
                if ($lastPayload !== null && $retryTracker->claimRetry($fd, $lastPayload['payload'], $account->accountId(), false)) {
                    $requestId = $this->requestIdFactory->fromHeaders($request->header ?? []);
                    $this->activeLogger->warning('Retrying WebSocket request after upstream closed before first payload', [
                        'request_id' => $requestId,
                        'session' => $lastPayload['sessionKey']->primary,
                        'account' => $account->name(),
                    ]);
                    try {
                        $authRefreshAttempts = [];
                        [$replacementClient, $replacement] = $this->openUpstreamWebSocketWithRecovery(
                            $request,
                            $this->freshAccount($account, $repository, $scheduler),
                            $headers,
                            $classifier,
                            $lastPayload['sessionKey'],
                            $scheduler,
                            $repository,
                            $requestId,
                            $authRefreshAttempts,
                        );
                        $sessionRegistry->attachUpstreamToSession($sessionId, $replacementClient, $replacement);
                        $this->startUpstreamWebSocketReader($server, $sessionId, $replacementClient, $sessionRegistry, $scheduler, $classifier, $repository, $headers, $retryTracker);
                        if ($this->pushUpstreamWebSocketPayload($replacementClient, $lastPayload['payload'], $lastPayload['opcode'])) {
                            return;
                        }
                        $sessionRegistry->markRequestActive($fd, false);
                        $sessionRegistry->releaseRequestTurn($fd);
                        $this->pushWebSocketError($server, $fd, 502, $this->upstreamClientError('WebSocket send', $replacementClient));
                    } catch (Throwable $throwable) {
                        $sessionRegistry->markRequestActive($fd, false);
                        $sessionRegistry->releaseRequestTurn($fd);
                        $this->pushWebSocketError($server, $fd, 502, $throwable->getMessage());
                    }
                    return;
                }
            }
            $sessionRegistry->markRequestActive($fd, false);
            $sessionRegistry->releaseRequestTurn($fd);
            $this->pushWebSocketError($server, $fd, 502, $this->incompleteStreamMessage());
        });
    }

    private function pushUpstreamWebSocketPayload(Client $client, string $payload, int $opcode): bool
    {
        return $client->push($payload, $opcode) !== false;
    }

    private function httpAcceptFor(string $requestUri): string
    {
        $path = (string) (parse_url($requestUri, PHP_URL_PATH) ?: '/');
        if ($path === '/v1/responses/compact' || $path === '/responses/compact') {
            return 'application/json';
        }

        return 'text/event-stream';
    }

    private function requestTarget(Request $request, string $defaultPath): string
    {
        $path = (string) (($request->server['request_uri'] ?? '') ?: $defaultPath);
        $query = (string) ($request->server['query_string'] ?? '');
        if ($query === '' || str_contains($path, '?')) {
            return $path;
        }

        return $path . '?' . $query;
    }

    private function traceWebSocketStreamError(
        string $requestId,
        SessionKey $sessionKey,
        CodexAccount $account,
        string $errorBody,
        ErrorClassifier $classifier,
    ): ErrorClassification {
        $classification = $classifier->classifyErrorPayload($errorBody);
        $this->traceUpstreamError($requestId, 'websocket', 'upstream_error', $sessionKey, $account, 200, $errorBody, $classification->type());

        return $classification;
    }

    /** @param list<string> $mutations */
    private function tracePayloadMutations(string $requestId, string $transport, SessionKey $sessionKey, array $mutations): void
    {
        if (!$this->traceMutations || $this->requestTraceLogger === null || $mutations === []) {
            return;
        }

        try {
            $this->requestTraceLogger->event([
                'request_id' => $requestId,
                'transport' => $transport,
                'phase' => 'request_normalized',
                'session' => $sessionKey->primary,
                'mutations' => $mutations,
            ]);
        } catch (Throwable $throwable) {
            $this->activeLogger->warning('Failed to write request mutation trace', [
                'request_id' => $requestId,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function freshAccount(CodexAccount $account, AccountRepository $repository, Scheduler $scheduler): CodexAccount
    {
        try {
            $refreshed = $this->activeTokenRefresher->refreshIfExpiring($account);
            if ($refreshed === null) {
                return $account;
            }

            return $this->persistRefreshedAccount($refreshed, $repository, $scheduler, 'Refreshed Codex account token');
        } catch (Throwable $throwable) {
            $this->activeLogger->warning('Failed to refresh Codex account token', [
                'account' => $account->name(),
                'error' => $throwable->getMessage(),
            ]);
            return $account;
        }
    }

    /**
     * @param array<string,bool> $attemptedAccounts
     */
    private function refreshAccountAfterAuthFailure(
        ErrorClassification $classification,
        CodexAccount $account,
        AccountRepository $repository,
        Scheduler $scheduler,
        array &$attemptedAccounts,
    ): ?CodexAccount {
        if ($classification->type() !== 'auth') {
            return null;
        }

        return $this->refreshAccountOnce($account, $repository, $scheduler, $attemptedAccounts, 'Refreshed Codex account token after auth failure', 'Failed to refresh Codex account after auth failure');
    }

    /**
     * @param array<string,bool> $attemptedAccounts
     */
    private function refreshAccountAfterWebSocketUpgradeFailure(
        Throwable $throwable,
        ErrorClassifier $classifier,
        CodexAccount $account,
        AccountRepository $repository,
        Scheduler $scheduler,
        array &$attemptedAccounts,
    ): ?CodexAccount {
        $classification = $this->classifyWebSocketUpgradeFailure($throwable, $classifier);
        if (!$classification instanceof ErrorClassification) {
            return null;
        }

        return $this->refreshAccountAfterAuthFailure($classification, $account, $repository, $scheduler, $attemptedAccounts);
    }

    private function classifyWebSocketUpgradeFailure(Throwable $throwable, ErrorClassifier $classifier): ?ErrorClassification
    {
        $failure = $this->webSocketUpgradeFailureResult($throwable);
        if ($failure === null) {
            return null;
        }

        return $classifier->classifyHttpResponse($failure['status'], $failure['body'], []);
    }

    private function shouldFallbackWebSocketUpgradeToHttp(Throwable $throwable, ErrorClassifier $classifier): bool
    {
        $failure = $this->webSocketUpgradeFailureResult($throwable);
        if ($failure === null) {
            return false;
        }

        $classification = $classifier->classifyHttpResponse($failure['status'], $failure['body'], []);
        if ($classification->hardSwitch()) {
            return false;
        }

        return $failure['status'] <= 0 || in_array($failure['status'], [404, 405, 426], true);
    }

    /** @return array{status:int,body:string}|null */
    private function webSocketUpgradeFailureResult(Throwable $throwable): ?array
    {
        $message = $throwable->getMessage();
        if (!preg_match('/status\s+(-?\d+):\s*(.*)\z/s', $message, $matches)) {
            return null;
        }

        return [
            'status' => (int) $matches[1],
            'body' => $matches[2],
        ];
    }

    /**
     * @param array<string,bool> $attemptedAccounts
     */
    private function refreshAccountOnce(
        CodexAccount $account,
        AccountRepository $repository,
        Scheduler $scheduler,
        array &$attemptedAccounts,
        string $successMessage,
        string $failureMessage,
    ): ?CodexAccount {
        if (($attemptedAccounts[$account->accountId()] ?? false) === true) {
            return null;
        }
        $attemptedAccounts[$account->accountId()] = true;

        try {
            $refreshed = $this->activeTokenRefresher->refresh($account);

            return $this->persistRefreshedAccount($refreshed, $repository, $scheduler, $successMessage);
        } catch (Throwable $throwable) {
            $this->activeLogger->warning($failureMessage, [
                'account' => $account->name(),
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    private function persistRefreshedAccount(
        CodexAccount $account,
        AccountRepository $repository,
        Scheduler $scheduler,
        string $message,
    ): CodexAccount {
        $repository->saveAccount($account);
        $scheduler->replaceAccount($account);
        $this->activeLogger->info($message, ['account' => $account->name()]);

        return $account;
    }

    /**
     * @param array<string,float> $timings
     */
    private function accountForSessionWithRecovery(SessionKey $sessionKey, Scheduler $scheduler, AccountRepository $repository, array &$timings = []): CodexAccount
    {
        $timings['scheduler_reload'] = ($timings['scheduler_reload'] ?? 0.0) + $this->syncSchedulerAccounts($repository, $scheduler);

        try {
            return $scheduler->accountForSession($sessionKey->primary, $sessionKey->fallback);
        } catch (RuntimeException $exception) {
            if (!$this->isNoAvailableAccount($exception) || !$this->recoverAuthCooldownAccounts($repository, $scheduler)) {
                throw $exception;
            }

            return $scheduler->accountForSession($sessionKey->primary, $sessionKey->fallback);
        }
    }

    private function switchAfterHardFailureWithRecovery(
        SessionKey $sessionKey,
        int $cooldownSeconds,
        string $cooldownReason,
        Scheduler $scheduler,
        AccountRepository $repository,
        array &$timings = [],
    ): CodexAccount {
        $timings['scheduler_reload'] = ($timings['scheduler_reload'] ?? 0.0) + $this->syncSchedulerAccounts($repository, $scheduler);

        try {
            return $scheduler->switchAfterHardFailure($sessionKey->primary, $cooldownSeconds, $cooldownReason);
        } catch (RuntimeException $exception) {
            if (!$this->isNoAvailableAccount($exception) || !$this->recoverAuthCooldownAccounts($repository, $scheduler)) {
                throw $exception;
            }

            return $scheduler->switchAfterHardFailure($sessionKey->primary, $cooldownSeconds, $cooldownReason);
        }
    }

    private function recoverAuthCooldownAccounts(AccountRepository $repository, Scheduler $scheduler): bool
    {
        $state = StateStore::file($this->stateFile);
        $now = time();
        $recovered = false;

        foreach ($repository->load() as $account) {
            if ($state->cooldownUntil($account->accountId()) <= $now) {
                continue;
            }
            if ($state->cooldownReason($account->accountId()) !== 'auth') {
                continue;
            }

            try {
                $refreshed = $this->activeTokenRefresher->refresh($account);
                $this->persistRefreshedAccount($refreshed, $repository, $scheduler, 'Recovered Codex account from auth cooldown');
                $state->setCooldownUntil($account->accountId(), 0);
                $recovered = true;
            } catch (Throwable $throwable) {
                $this->activeLogger->warning('Failed to recover Codex account from auth cooldown', [
                    'account' => $account->name(),
                    'error' => $throwable->getMessage(),
                ]);
            }
        }

        return $recovered;
    }

    private function isNoAvailableAccount(RuntimeException $exception): bool
    {
        return $exception->getMessage() === 'No available Codex account';
    }

    private function syncSchedulerAccounts(AccountRepository $repository, Scheduler $scheduler): float
    {
        $startedAt = microtime(true);
        try {
            $scheduler->replaceAccounts($repository->load());
        } catch (Throwable $throwable) {
            $this->activeLogger->warning('Failed to reload Codex accounts from disk', [
                'error' => $throwable->getMessage(),
            ]);
        }

        return $this->elapsedMs($startedAt);
    }

    private function pushWebSocketError(Server $server, int $fd, int $status, string $message): void
    {
        if ($server->isEstablished($fd)) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'error' => [
                    'code' => 'upstream_websocket_error',
                    'message' => $message,
                    'status' => $status,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            \Swoole\Timer::after(200, static function () use ($server, $fd): void {
                if ($server->exist($fd)) {
                    $server->disconnect($fd, 1011, 'upstream error');
                }
            });
        }
    }

    private function traceUpstreamError(
        string $requestId,
        string $transport,
        string $phase,
        SessionKey $sessionKey,
        CodexAccount $account,
        int $status,
        string $message,
        string $classification,
    ): void {
        if ($this->requestTraceLogger === null) {
            return;
        }

        try {
            $this->requestTraceLogger->error([
                'request_id' => $requestId,
                'transport' => $transport,
                'phase' => $phase,
                'session' => $sessionKey->primary,
                'account' => $account->name(),
                'status' => $status,
                'classification' => $classification,
                'message' => $message,
            ]);
        } catch (Throwable $throwable) {
            $this->activeLogger->warning('Failed to write request trace', [
                'request_id' => $requestId,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string,float> $timings
     */
    private function traceRequestTiming(
        string $requestId,
        string $transport,
        string $phase,
        SessionKey $sessionKey,
        ?CodexAccount $account,
        int $status,
        string $classification,
        int $attempts,
        array $timings,
        ?string $message = null,
    ): void {
        if (!$this->traceTimings || $this->requestTraceLogger === null) {
            return;
        }

        $event = [
            'request_id' => $requestId,
            'transport' => $transport,
            'phase' => $phase,
            'session' => $sessionKey->primary,
            'status' => $status,
            'classification' => $classification,
            'timings_ms' => $timings,
        ];
        if ($attempts > 0) {
            $event['attempts'] = $attempts;
        }
        if ($account instanceof CodexAccount) {
            $event['account'] = $account->name();
        }
        if ($message !== null && $message !== '') {
            $event['message'] = $message;
        }

        try {
            $this->requestTraceLogger->event($event);
        } catch (Throwable $throwable) {
            $this->activeLogger->warning('Failed to write request timing trace', [
                'request_id' => $requestId,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string,float> $timings
     * @return array<string,float>
     */
    private function timingPayload(array $timings, float $startedAt, ?float $firstByteMs): array
    {
        $payload = [];
        foreach ($timings as $key => $value) {
            if ($key !== '' && $value >= 0) {
                $payload[$key] = $value;
            }
        }
        if ($firstByteMs !== null && $firstByteMs >= 0) {
            $payload['first_byte'] = $firstByteMs;
        }
        $payload['total'] = $this->elapsedMs($startedAt);

        return $payload;
    }

    private function elapsedMs(float $startedAt, ?float $endedAt = null): float
    {
        $endedAt ??= microtime(true);

        return max(0.0, ($endedAt - $startedAt) * 1000);
    }

    /** @param array<string,string> $headers */
    private function copyResponseHeaders(Response $response, array $headers, int $status): void
    {
        $response->status($status > 0 ? $status : 502);
        foreach ($headers as $key => $value) {
            $lower = strtolower((string) $key);
            if (in_array($lower, ['transfer-encoding', 'content-length', 'content-encoding', 'connection'], true)) {
                continue;
            }
            $response->header((string) $key, (string) $value);
        }
    }

}
