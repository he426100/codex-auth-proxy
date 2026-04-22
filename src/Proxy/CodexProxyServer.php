<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

use CodexAuthProxy\Account\AccountRepository;
use CodexAuthProxy\Account\CodexAccount;
use CodexAuthProxy\Auth\TokenRefresher;
use CodexAuthProxy\Network\OutboundProxyConfig;
use CodexAuthProxy\Observability\RequestIdFactory;
use CodexAuthProxy\Observability\RequestTraceLogger;
use CodexAuthProxy\Routing\ErrorClassification;
use CodexAuthProxy\Routing\ErrorClassifier;
use CodexAuthProxy\Routing\Scheduler;
use CodexAuthProxy\Routing\StateStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\Http\Request;
use Swoole\Http\Response;
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
        private readonly string $upstreamBase = 'https://chatgpt.com/backend-api/codex',
        ?LoggerInterface $logger = null,
        ?TokenRefresher $tokenRefresher = null,
        private readonly ?OutboundProxyConfig $outboundProxyConfig = null,
        private readonly string $codexUserAgent = 'codex_cli_rs/0.114.0 codex-auth-proxy/0.1.0',
        private readonly string $codexBetaFeatures = 'multi_agent',
        private readonly ?RequestTraceLogger $requestTraceLogger = null,
        private readonly RequestIdFactory $requestIdFactory = new RequestIdFactory(),
        private readonly bool $traceMutations = true,
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
        $headers = new UpstreamHeaderFactory($this->codexUserAgent, $this->codexBetaFeatures);
        $payloadNormalizer = new ResponsesPayloadNormalizer();
        $normalizer = new ResponsesWebSocketNormalizer();
        $retryTracker = new WebSocketRetryTracker();
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
    ): void {
        /** @var array<int,Request> $websocketRequests */
        $websocketRequests = [];
        /** @var array<int,Client> $websocketClients */
        $websocketClients = [];
        /** @var array<int,array{payload:string,opcode:int,sessionKey:SessionKey}> $websocketLastPayloads */
        $websocketLastPayloads = [];

        $server->on('open', static function (Server $server, Request $request) use (&$websocketRequests): void {
            $fd = (int) ($request->fd ?? 0);
            if ($fd > 0) {
                $websocketRequests[$fd] = $request;
            }
        });

        $server->on('message', function (Server $server, Frame $frame) use (&$websocketRequests, &$websocketClients, &$websocketLastPayloads, $scheduler, $classifier, $repository, $extractor, $headers, $normalizer, $retryTracker): void {
            $this->handleWebSocketMessage($server, $frame, $websocketRequests, $websocketClients, $websocketLastPayloads, $scheduler, $classifier, $repository, $extractor, $headers, $normalizer, $retryTracker);
        });

        $server->on('close', static function (Server $server, int $fd) use (&$websocketRequests, &$websocketClients, &$websocketLastPayloads, $retryTracker): void {
            unset($websocketRequests[$fd]);
            unset($websocketLastPayloads[$fd]);
            $retryTracker->clear($fd);
            if (isset($websocketClients[$fd])) {
                $websocketClients[$fd]->close();
                unset($websocketClients[$fd]);
            }
        });

        $server->on('request', function (Request $request, Response $response) use ($scheduler, $classifier, $repository, $extractor, $headers, $payloadNormalizer): void {
            $this->handleHttp($request, $response, $scheduler, $classifier, $repository, $extractor, $headers, $payloadNormalizer);
        });

        $server->on($this->shutdownEvent(), function () use (&$websocketClients): void {
            foreach ($websocketClients as $client) {
                $client->close();
            }
            $websocketClients = [];
            $this->activeLogger->info('Codex auth proxy stopped');
        });
    }

    private function shutdownEvent(): string
    {
        return 'shutdown';
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
        $this->tracePayloadMutations($requestId, 'http', $sessionKey, $normalizedBody->mutations());
        try {
            $account = $this->freshAccount($this->accountForSessionWithRecovery($sessionKey, $scheduler, $repository), $repository, $scheduler);
            $authRefreshAttempts = [];
            while (true) {
                $result = $this->forward($request, $response, $account, $body, $headers, false);
                $classification = $classifier->classify($result['status'], $result['body'], $result['headers']);

                if (!$classification->hardSwitch()) {
                    if ($result['status'] >= 400) {
                        $this->traceUpstreamError($requestId, 'http', 'upstream_response', $sessionKey, $account, $result['status'], $result['body'], $classification->type());
                    }
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
                $account = $this->freshAccount(
                    $this->switchAfterHardFailureWithRecovery($sessionKey, $cooldownSeconds, $classification->type(), $scheduler, $repository),
                    $repository,
                    $scheduler,
                );
            }
        } catch (RuntimeException $exception) {
            $this->activeLogger->warning('Codex proxy request unavailable', [
                'request_id' => $requestId,
                'error' => $exception->getMessage(),
            ]);
            $this->finishProxyUnavailable($response, $exception->getMessage());
        }
    }

    /**
     * @param array<int,Request> $websocketRequests
     * @param array<int,Client> $websocketClients
     * @param array<int,array{payload:string,opcode:int,sessionKey:SessionKey}> $websocketLastPayloads
     */
    private function handleWebSocketMessage(
        Server $server,
        Frame $frame,
        array &$websocketRequests,
        array &$websocketClients,
        array &$websocketLastPayloads,
        Scheduler $scheduler,
        ErrorClassifier $classifier,
        AccountRepository $repository,
        SessionKeyExtractor $extractor,
        UpstreamHeaderFactory $headers,
        ResponsesWebSocketNormalizer $normalizer,
        WebSocketRetryTracker $retryTracker,
    ): void {
        $fd = (int) $frame->fd;
        $request = $websocketRequests[$fd] ?? null;
        if ($request === null) {
            $this->pushWebSocketError($server, $fd, 400, 'Missing WebSocket handshake request');
            return;
        }

        $client = $websocketClients[$fd] ?? null;
        $rawPayload = (string) $frame->data;
        $requestId = $this->requestIdFactory->fromHeaders($request->header ?? []);
        $sessionKey = $extractor->extract($request->header ?? [], $rawPayload);
        $normalizedPayload = $normalizer->normalizeWithReport($rawPayload);
        $payload = $normalizedPayload->payload();
        $this->tracePayloadMutations($requestId, 'websocket', $sessionKey, $normalizedPayload->mutations());
        $retryTracker->beginPayload($fd, $payload);
        $websocketLastPayloads[$fd] = [
            'payload' => $payload,
            'opcode' => (int) $frame->opcode,
            'sessionKey' => $sessionKey,
        ];
        if ($client === null) {
            $account = null;
            $authRefreshAttempts = [];
            try {
                $account = $this->freshAccount($this->accountForSessionWithRecovery($sessionKey, $scheduler, $repository), $repository, $scheduler);
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

                $websocketClients[$fd] = $client;
                $this->startUpstreamWebSocketReader($server, $fd, $client, $request, $account, $websocketClients, $websocketLastPayloads, $scheduler, $classifier, $repository, $headers, $retryTracker);
            } catch (Throwable $throwable) {
                $this->activeLogger->warning('Upstream WebSocket failed', ['request_id' => $requestId, 'error' => $throwable->getMessage()]);
                if ($account instanceof CodexAccount) {
                    $this->traceUpstreamError($requestId, 'websocket', 'upstream_upgrade', $sessionKey, $account, 502, $throwable->getMessage(), 'transport');
                }
                $this->pushWebSocketError($server, $fd, 502, $throwable->getMessage());
                return;
            }
        }

        $client->push($payload, (int) $frame->opcode);
    }

    /** @return array{status:int,body:string,headers:array<string,string>,streamed:bool} */
    private function forward(Request $request, Response $response, CodexAccount $account, string $body, UpstreamHeaderFactory $headers, bool $forceBuffer): array
    {
        $target = new UpstreamTarget($this->upstreamBase);
        [$host, $port, $ssl] = $target->endpoint();
        $headersSent = false;
        $bodyBuffer = new UpstreamResponseBodyBuffer($forceBuffer);

        $client = new Client($host, $port, $ssl);
        $client->set($this->clientOptionsFor($host, [
            'timeout' => -1,
            'write_func' => function (Client $client, string $chunk) use (&$headersSent, $response, $bodyBuffer): int {
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
        $client->close();

        return [
            'status' => $status,
            'body' => $buffer,
            'headers' => $responseHeaders,
            'streamed' => $bodyBuffer->streamed(),
        ];
    }

    /** @param array{status:int,body:string,headers:array<string,string>,streamed:bool} $result */
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

    /**
     * @param array<int,Client> $websocketClients
     * @param array<int,array{payload:string,opcode:int,sessionKey:SessionKey}> $websocketLastPayloads
     */
    private function startUpstreamWebSocketReader(
        Server $server,
        int $fd,
        Client $client,
        Request $request,
        CodexAccount $account,
        array &$websocketClients,
        array &$websocketLastPayloads,
        Scheduler $scheduler,
        ErrorClassifier $classifier,
        AccountRepository $repository,
        UpstreamHeaderFactory $headers,
        WebSocketRetryTracker $retryTracker,
    ): void
    {
        Coroutine::create(function () use ($server, $fd, $client, $request, $account, &$websocketClients, &$websocketLastPayloads, $scheduler, $classifier, $repository, $headers, $retryTracker): void {
            $forwardedData = false;
            $completedSeen = false;
            $replacedClient = false;
            $terminalErrorSent = false;
            while (true) {
                $frame = $client->recv();
                if ($frame === false || $frame === '') {
                    break;
                }

                $data = is_object($frame) && property_exists($frame, 'data') ? (string) $frame->data : (string) $frame;
                $opcode = is_object($frame) && property_exists($frame, 'opcode') ? (int) $frame->opcode : \WEBSOCKET_OPCODE_TEXT;
                if ($opcode === \WEBSOCKET_OPCODE_CLOSE) {
                    break;
                }
                $data = StreamErrorDetector::normalizeCompletedPayload($data);
                $completedSeen = StreamErrorDetector::isCompletedPayload($data);
                $errorBody = StreamErrorDetector::jsonErrorBody($data);
                if ($errorBody !== null) {
                    $lastPayload = $websocketLastPayloads[$fd] ?? null;
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
                                $websocketClients[$fd] = $replacementClient;
                                $this->startUpstreamWebSocketReader($server, $fd, $replacementClient, $request, $replacement, $websocketClients, $websocketLastPayloads, $scheduler, $classifier, $repository, $headers, $retryTracker);
                                $replacementClient->push($lastPayload['payload'], $lastPayload['opcode']);
                                $replacedClient = true;
                                break;
                            } catch (Throwable $throwable) {
                                $this->activeLogger->warning('Replacement WebSocket failed', ['error' => $throwable->getMessage()]);
                                if (!$replacement instanceof CodexAccount) {
                                    if ($server->isEstablished($fd)) {
                                        $server->push($fd, $data, $opcode);
                                        $forwardedData = true;
                                    }
                                    $terminalErrorSent = true;
                                    break;
                                }
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

                    $terminalErrorSent = true;
                }

                if ($server->isEstablished($fd)) {
                    $server->push($fd, $data, $opcode);
                    $forwardedData = true;
                }

                if ($completedSeen) {
                    break;
                }
            }

            $client->close();
            if (!$replacedClient && $server->isEstablished($fd)) {
                if ($terminalErrorSent) {
                    return;
                }
                if (!$completedSeen) {
                    $this->pushWebSocketError($server, $fd, 502, $this->incompleteStreamMessage());
                    return;
                }
                $server->disconnect($fd, \SWOOLE_WEBSOCKET_CLOSE_NORMAL, 'upstream closed');
            }
        });
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
        $classification = $classifier->classify(200, $errorBody, []);
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

        return $classifier->classify($failure['status'], $failure['body'], []);
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

    private function accountForSessionWithRecovery(SessionKey $sessionKey, Scheduler $scheduler, AccountRepository $repository): CodexAccount
    {
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
    ): CodexAccount {
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
