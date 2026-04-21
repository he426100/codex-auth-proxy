<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

use CodexAuthProxy\Account\AccountRepository;
use CodexAuthProxy\Account\CodexAccount;
use CodexAuthProxy\Auth\TokenRefresher;
use CodexAuthProxy\Network\OutboundProxyConfig;
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
    ) {
        $this->activeLogger = $logger ?? new NullLogger();
        $this->activeTokenRefresher = $tokenRefresher ?? new TokenRefresher(proxy: $outboundProxyConfig?->guzzleProxy() ?? []);
    }

    public function start(): void
    {
        if (!extension_loaded('swoole')) {
            throw new RuntimeException('The swoole extension is required for serve');
        }

        $repository = new AccountRepository($this->accountsDir);
        $accounts = $repository->load();
        $scheduler = new Scheduler($accounts, StateStore::file($this->stateFile));
        $classifier = new ErrorClassifier($this->defaultCooldownSeconds);
        $extractor = new SessionKeyExtractor();
        $headers = new UpstreamHeaderFactory($this->codexUserAgent, $this->codexBetaFeatures);
        $normalizer = new ResponsesWebSocketNormalizer();
        $retryTracker = new WebSocketRetryTracker();
        $this->activeLogger->info('Loaded Codex accounts', ['count' => count($accounts)]);

        $server = new Server($this->host, $this->port);
        $server->set([
            'worker_num' => 1,
            'enable_coroutine' => true,
            'http_compression' => false,
            'websocket_compression' => false,
        ]);

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

        $server->on('request', function (Request $request, Response $response) use ($scheduler, $classifier, $repository, $extractor, $headers): void {
            $this->handleHttp($request, $response, $scheduler, $classifier, $repository, $extractor, $headers);
        });

        $server->start();
    }

    private function handleHttp(
        Request $request,
        Response $response,
        Scheduler $scheduler,
        ErrorClassifier $classifier,
        AccountRepository $repository,
        SessionKeyExtractor $extractor,
        UpstreamHeaderFactory $headers,
    ): void {
        $path = $request->server['request_uri'] ?? '/';
        if ($path === '/health') {
            $response->header('Content-Type', 'application/json');
            $response->end('{"status":"ok"}');
            return;
        }

        $body = $request->rawContent() ?: '';
        $sessionKey = $extractor->extract($request->header ?? [], $body);
        $account = $this->freshAccount($scheduler->accountForSession($sessionKey->primary, $sessionKey->fallback), $repository, $scheduler);
        $result = $this->forward($request, $response, $account, $body, $headers, false);
        $classification = $classifier->classify($result['status'], $result['body'], $result['headers']);

        if (!$classification->hardSwitch()) {
            $this->finishBufferedError($response, $result);
            return;
        }

        $cooldownSeconds = max(1, $classification->cooldownUntil() - time());
        $this->activeLogger->warning('Switching Codex account after hard failure', [
            'session' => $sessionKey->primary,
            'type' => $classification->type(),
            'cooldown_seconds' => $cooldownSeconds,
        ]);
        $replacement = $this->freshAccount($scheduler->switchAfterHardFailure($sessionKey->primary, $cooldownSeconds), $repository, $scheduler);
        $retry = $this->forward($request, $response, $replacement, $body, $headers, false);
        $this->finishBufferedError($response, $retry);
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
        $payload = $normalizer->normalize($rawPayload);
        $sessionKey = $extractor->extract($request->header ?? [], $rawPayload);
        $websocketLastPayloads[$fd] = [
            'payload' => $payload,
            'opcode' => (int) $frame->opcode,
            'sessionKey' => $sessionKey,
        ];
        if ($client === null) {
            try {
                $account = $this->freshAccount($scheduler->accountForSession($sessionKey->primary, $sessionKey->fallback), $repository, $scheduler);
                $client = $this->openUpstreamWebSocket($request, $account, $headers);
                $websocketClients[$fd] = $client;
                $this->startUpstreamWebSocketReader($server, $fd, $client, $request, $websocketClients, $websocketLastPayloads, $scheduler, $classifier, $repository, $headers, $retryTracker);
            } catch (Throwable $throwable) {
                $this->activeLogger->warning('Upstream WebSocket failed', ['error' => $throwable->getMessage()]);
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
        $buffer = '';
        $headersSent = false;
        $streamed = false;
        $streamErrorBuffered = false;
        $framer = new SseFramer();

        $client = new Client($host, $port, $ssl);
        $client->set($this->clientOptionsFor($host, [
            'timeout' => -1,
            'write_func' => function (Client $client, string $chunk) use (&$buffer, &$headersSent, &$streamed, &$streamErrorBuffered, $response, $forceBuffer, $framer): int {
                if ($forceBuffer || $client->statusCode >= 400 || $streamErrorBuffered) {
                    $buffer .= $chunk;
                    return strlen($chunk);
                }

                if (!$this->isSse($client->headers ?? [])) {
                    if (!$headersSent) {
                        $this->copyResponseHeaders($response, $client->headers ?? [], $client->statusCode);
                        $headersSent = true;
                    }
                    $streamed = true;
                    $response->write($chunk);
                    return strlen($chunk);
                }

                foreach ($framer->write($chunk) as $frame) {
                    if (!$headersSent) {
                        $errorBody = StreamErrorDetector::errorBody($frame);
                        if ($errorBody !== null) {
                            $streamErrorBuffered = true;
                            $buffer .= $errorBody;
                            continue;
                        }
                        $this->copyResponseHeaders($response, $client->headers ?? [], $client->statusCode);
                        $headersSent = true;
                    }
                    $streamed = true;
                    $response->write($frame);
                }

                return strlen($chunk);
            },
        ]));
        $client->setHeaders($headers->build($request->header ?? [], $account, $host, false));
        $client->setMethod(strtoupper((string) ($request->server['request_method'] ?? 'GET')));
        if ($body !== '') {
            $client->setData($body);
        }

        $path = $target->pathFor((string) ($request->server['request_uri'] ?? '/v1/responses'));
        $client->execute($path);
        $status = (int) ($client->statusCode ?: 502);
        $responseHeaders = is_array($client->headers ?? null) ? $client->headers : [];
        if ($client->statusCode === -1) {
            $buffer = $this->upstreamClientError('HTTP request', $client);
        }
        if (!$streamed && !$streamErrorBuffered) {
            foreach ($framer->flush() as $frame) {
                if (!$headersSent) {
                    $this->copyResponseHeaders($response, $responseHeaders, $status);
                    $headersSent = true;
                }
                $streamed = true;
                $response->write($frame);
            }
        }
        if (!$streamed && $buffer === '' && is_string($client->body ?? null)) {
            $buffer = $client->body;
        }
        if ($streamed) {
            $response->end();
        }
        $client->close();

        return [
            'status' => $status,
            'body' => $buffer,
            'headers' => $responseHeaders,
            'streamed' => $streamed,
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

    private function openUpstreamWebSocket(Request $request, CodexAccount $account, UpstreamHeaderFactory $headers): Client
    {
        $target = new UpstreamTarget($this->upstreamBase);
        [$host, $port, $ssl] = $target->endpoint();
        $client = new Client($host, $port, $ssl);
        $client->set($this->clientOptionsFor($host, ['timeout' => -1]));
        $client->setHeaders($headers->build($request->header ?? [], $account, $host, true));
        $path = $target->pathFor((string) ($request->server['request_uri'] ?? '/v1/responses'));
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
     * @param array<string,string|int|callable> $baseOptions
     * @return array<string,string|int|callable>
     */
    private function clientOptionsFor(string $host, array $baseOptions): array
    {
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
        array &$websocketClients,
        array &$websocketLastPayloads,
        Scheduler $scheduler,
        ErrorClassifier $classifier,
        AccountRepository $repository,
        UpstreamHeaderFactory $headers,
        WebSocketRetryTracker $retryTracker,
    ): void
    {
        Coroutine::create(function () use ($server, $fd, $client, $request, &$websocketClients, &$websocketLastPayloads, $scheduler, $classifier, $repository, $headers, $retryTracker): void {
            $forwardedData = false;
            $replacedClient = false;
            while (true) {
                $frame = $client->recv();
                if ($frame === false || $frame === '') {
                    break;
                }

                $data = is_object($frame) && property_exists($frame, 'data') ? (string) $frame->data : (string) $frame;
                $opcode = is_object($frame) && property_exists($frame, 'opcode') ? (int) $frame->opcode : \WEBSOCKET_OPCODE_TEXT;
                $errorBody = StreamErrorDetector::jsonErrorBody($data);
                if ($errorBody !== null) {
                    $classification = $classifier->classify(200, $errorBody, []);
                    if ($classification->hardSwitch()) {
                        $cooldownSeconds = max(1, $classification->cooldownUntil() - time());
                        $lastPayload = $websocketLastPayloads[$fd] ?? null;
                        if ($lastPayload !== null && $retryTracker->claimRetry($fd, $lastPayload['payload'], $forwardedData)) {
                            $replacement = $this->freshAccount($scheduler->switchAfterHardFailure($lastPayload['sessionKey']->primary, $cooldownSeconds), $repository, $scheduler);
                            try {
                                $replacementClient = $this->openUpstreamWebSocket($request, $replacement, $headers);
                                $websocketClients[$fd] = $replacementClient;
                                $this->startUpstreamWebSocketReader($server, $fd, $replacementClient, $request, $websocketClients, $websocketLastPayloads, $scheduler, $classifier, $repository, $headers, $retryTracker);
                                $replacementClient->push($lastPayload['payload'], $lastPayload['opcode']);
                                $replacedClient = true;
                                break;
                            } catch (Throwable $throwable) {
                                $this->activeLogger->warning('Replacement WebSocket failed', ['error' => $throwable->getMessage()]);
                                $this->pushWebSocketError($server, $fd, 502, $throwable->getMessage());
                                break;
                            }
                        }

                        $sessionKey = $lastPayload['sessionKey'] ?? new SessionKey('global', null);
                        $scheduler->switchAfterHardFailure($sessionKey->primary, $cooldownSeconds);
                    }
                }

                if ($server->isEstablished($fd)) {
                    $server->push($fd, $data, $opcode);
                    $forwardedData = true;
                }
            }

            $client->close();
            if (!$replacedClient && $server->isEstablished($fd)) {
                $server->disconnect($fd, \SWOOLE_WEBSOCKET_CLOSE_NORMAL, 'upstream closed');
            }
        });
    }

    private function freshAccount(CodexAccount $account, AccountRepository $repository, Scheduler $scheduler): CodexAccount
    {
        try {
            $refreshed = $this->activeTokenRefresher->refreshIfExpiring($account);
            if ($refreshed === null) {
                return $account;
            }
            $repository->saveAccount($refreshed);
            $scheduler->replaceAccount($refreshed);
            $this->activeLogger->info('Refreshed Codex account token', ['account' => $refreshed->name()]);
            return $refreshed;
        } catch (Throwable $throwable) {
            $this->activeLogger->warning('Failed to refresh Codex account token', [
                'account' => $account->name(),
                'error' => $throwable->getMessage(),
            ]);
            return $account;
        }
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
            $server->disconnect($fd, 1011, 'upstream error');
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

    /** @param array<string,string> $headers */
    private function isSse(array $headers): bool
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === 'content-type' && str_contains(strtolower((string) $value), 'text/event-stream')) {
                return true;
            }
        }

        return false;
    }

}
