<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Proxy;

use CodexAuthProxy\Account\AccountFileValidator;
use CodexAuthProxy\Account\AccountRepository;
use CodexAuthProxy\Config\AppConfig;
use CodexAuthProxy\Logging\LoggerFactory;
use CodexAuthProxy\Network\OutboundProxyConfig;
use CodexAuthProxy\Observability\RequestTraceLogger;
use CodexAuthProxy\Proxy\CodexProxyServer;
use CodexAuthProxy\Proxy\SessionKey;
use CodexAuthProxy\Routing\ErrorClassifier;
use CodexAuthProxy\Routing\Scheduler;
use CodexAuthProxy\Routing\StateStore;
use CodexAuthProxy\Tests\TestCase;
use ReflectionMethod;

final class CodexProxyServerTest extends TestCase
{
    public function testClientOptionsIncludeConfiguredProxyForUpstreamHost(): void
    {
        $server = $this->server([
            'outboundProxyConfig' => OutboundProxyConfig::fromAppConfig($this->config(
                httpProxy: 'http://proxy.local:8080',
                httpsProxy: 'http://secure-proxy.local:8443',
                noProxy: 'localhost',
            )),
        ]);

        $options = $this->clientOptionsFor($server, 'chatgpt.com', ['timeout' => -1]);

        self::assertSame(-1, $options['timeout']);
        self::assertSame('chatgpt.com', $options['ssl_host_name']);
        self::assertSame('secure-proxy.local', $options['http_proxy_host']);
        self::assertSame(8443, $options['http_proxy_port']);
    }

    public function testClientOptionsIncludeConfiguredSocks5ProxyForUpstreamHost(): void
    {
        $server = $this->server([
            'outboundProxyConfig' => OutboundProxyConfig::fromAppConfig($this->config(
                httpProxy: 'socks5://user:pass@proxy.local:1080',
                httpsProxy: null,
                noProxy: 'localhost',
            )),
        ]);

        $options = $this->clientOptionsFor($server, 'chatgpt.com', ['timeout' => -1]);

        self::assertSame(-1, $options['timeout']);
        self::assertSame('chatgpt.com', $options['ssl_host_name']);
        self::assertSame('proxy.local', $options['socks5_host']);
        self::assertSame(1080, $options['socks5_port']);
        self::assertSame('user', $options['socks5_username']);
        self::assertSame('pass', $options['socks5_password']);
    }

    public function testClientOptionsBypassProxyForNoProxyHost(): void
    {
        $server = $this->server([
            'outboundProxyConfig' => OutboundProxyConfig::fromAppConfig($this->config(
                httpProxy: 'http://proxy.local:8080',
                httpsProxy: 'http://secure-proxy.local:8443',
                noProxy: 'chatgpt.com',
            )),
        ]);

        $options = $this->clientOptionsFor($server, 'chatgpt.com', ['timeout' => -1]);

        self::assertSame([
            'timeout' => -1,
            'ssl_host_name' => 'chatgpt.com',
        ], $options);
    }

    public function testRecordsTraceForUpstreamError(): void
    {
        [$traceFile, $traceLogger] = $this->traceLogger('proxy-trace');
        $server = $this->server([
            'requestTraceLogger' => $traceLogger,
        ]);

        $method = new ReflectionMethod(CodexProxyServer::class, 'traceUpstreamError');
        $method->invoke(
            $server,
            'req12345',
            'http',
            'upstream_response',
            new SessionKey('session-a'),
            $this->account('alpha'),
            429,
            'Authorization: Bearer secret-token',
            'quota',
        );

        $records = $this->traceRecords($traceFile);
        self::assertCount(1, $records);
        self::assertSame('req12345', $records[0]['context']['request_id']);
        self::assertSame('session-a', $records[0]['context']['session']);
        self::assertSame('alpha', $records[0]['context']['account']);
        self::assertSame(429, $records[0]['context']['status']);
        self::assertSame('quota', $records[0]['context']['classification']);
        self::assertStringNotContainsString('secret-token', $records[0]['context']['message']);
    }

    public function testRecordsTraceForWebSocketStreamError(): void
    {
        [$traceFile, $traceLogger] = $this->traceLogger('proxy-websocket-trace');
        $server = $this->server([
            'requestTraceLogger' => $traceLogger,
        ]);

        $method = new ReflectionMethod(CodexProxyServer::class, 'traceWebSocketStreamError');
        $method->invoke(
            $server,
            'req-ws',
            new SessionKey('session-ws'),
            $this->account('alpha'),
            '{"type":"error","error":{"code":"usage_limit_reached","message":"too many requests"}}',
            new ErrorClassifier(18000),
        );

        $records = $this->traceRecords($traceFile);
        self::assertCount(1, $records);
        self::assertSame('req-ws', $records[0]['context']['request_id']);
        self::assertSame('websocket', $records[0]['context']['transport']);
        self::assertSame('upstream_error', $records[0]['context']['phase']);
        self::assertSame('session-ws', $records[0]['context']['session']);
        self::assertSame('alpha', $records[0]['context']['account']);
        self::assertSame(200, $records[0]['context']['status']);
        self::assertSame('quota', $records[0]['context']['classification']);
    }

    public function testRecordsTraceForPayloadMutations(): void
    {
        [$traceFile, $traceLogger] = $this->traceLogger('proxy-mutation-trace');
        $server = $this->server([
            'requestTraceLogger' => $traceLogger,
        ]);

        $method = new ReflectionMethod(CodexProxyServer::class, 'tracePayloadMutations');
        $method->invoke(
            $server,
            'req-mutation',
            'http',
            new SessionKey('session-mutation'),
            ['http.input.string_to_message'],
        );

        $records = $this->traceRecords($traceFile);
        self::assertCount(1, $records);
        self::assertSame('req-mutation', $records[0]['context']['request_id']);
        self::assertSame('http', $records[0]['context']['transport']);
        self::assertSame('request_normalized', $records[0]['context']['phase']);
        self::assertSame('session-mutation', $records[0]['context']['session']);
        self::assertSame(['http.input.string_to_message'], $records[0]['context']['mutations']);
    }

    public function testSkipsTraceForPayloadMutationsWhenDisabled(): void
    {
        [$traceFile, $traceLogger] = $this->traceLogger('proxy-mutation-trace-disabled');
        $server = $this->server([
            'requestTraceLogger' => $traceLogger,
            'traceMutations' => false,
        ]);

        $method = new ReflectionMethod(CodexProxyServer::class, 'tracePayloadMutations');
        $method->invoke(
            $server,
            'req-mutation-disabled',
            'http',
            new SessionKey('session-mutation-disabled'),
            ['http.input.string_to_message'],
        );

        self::assertSame([], $this->traceRecords($traceFile));
    }

    public function testRecordsTraceForRequestTimingsWhenEnabled(): void
    {
        [$traceFile, $traceLogger] = $this->traceLogger('proxy-timing-trace');
        $server = $this->server([
            'requestTraceLogger' => $traceLogger,
            'traceTimings' => true,
        ]);

        $method = new ReflectionMethod(CodexProxyServer::class, 'traceRequestTiming');
        $method->invoke(
            $server,
            'req-timing',
            'http',
            'request_completed',
            new SessionKey('session-timing'),
            $this->account('alpha'),
            200,
            'none',
            2,
            [
                'scheduler_reload' => 1.25,
                'account_prepare' => 3.5,
                'upstream' => 120.75,
                'first_byte' => 45.5,
                'total' => 130.25,
            ],
            null,
        );

        $records = $this->traceRecords($traceFile);
        self::assertCount(1, $records);
        self::assertSame('request_completed', $records[0]['context']['phase']);
        self::assertSame(2, $records[0]['context']['attempts']);
        self::assertSame(120.75, $records[0]['context']['timings_ms']['upstream']);
        self::assertSame(130.25, $records[0]['context']['timings_ms']['total']);
    }

    public function testSkipsTraceForRequestTimingsWhenDisabled(): void
    {
        [$traceFile, $traceLogger] = $this->traceLogger('proxy-timing-trace-disabled');
        $server = $this->server([
            'requestTraceLogger' => $traceLogger,
            'traceTimings' => false,
        ]);

        $method = new ReflectionMethod(CodexProxyServer::class, 'traceRequestTiming');
        $method->invoke(
            $server,
            'req-timing-disabled',
            'http',
            'request_completed',
            new SessionKey('session-timing-disabled'),
            $this->account('alpha'),
            200,
            'none',
            1,
            ['total' => 100.0],
            null,
        );

        self::assertSame([], $this->traceRecords($traceFile));
    }

    public function testRecordsTraceForAccountSelection(): void
    {
        [$traceFile, $traceLogger] = $this->traceLogger('proxy-selection-trace');
        $server = $this->server([
            'requestTraceLogger' => $traceLogger,
        ]);

        $method = new ReflectionMethod(CodexProxyServer::class, 'traceAccountSelection');
        $method->invoke(
            $server,
            'req-selection',
            'http',
            new SessionKey('session-selection'),
            [
                'source' => 'new_session',
                'selected_account_id' => 'acct-beta',
                'selected_account_name' => 'beta',
                'candidates' => [
                    [
                        'account_id' => 'acct-beta',
                        'account' => 'beta',
                        'priority' => 'confirmed_available',
                        'confirmed_available' => true,
                        'low_quota' => false,
                        'quota_score' => 90.0,
                    ],
                    [
                        'account_id' => 'acct-alpha',
                        'account' => 'alpha',
                        'priority' => 'low_quota',
                        'confirmed_available' => true,
                        'low_quota' => true,
                        'quota_score' => 20.0,
                    ],
                ],
            ],
        );

        $records = $this->traceRecords($traceFile);
        self::assertCount(1, $records);
        self::assertSame('account_selected', $records[0]['context']['phase']);
        self::assertSame('new_session', $records[0]['context']['selection_source']);
        self::assertSame('beta', $records[0]['context']['account']);
        self::assertSame('acct-beta', $records[0]['context']['selected_account_id']);
        self::assertSame('acct-beta', $records[0]['context']['candidates'][0]['account_id']);
        self::assertTrue($records[0]['context']['candidates'][0]['confirmed_available']);
    }

    public function testSkipsTraceForBoundSessionAccountSelection(): void
    {
        [$traceFile, $traceLogger] = $this->traceLogger('proxy-selection-trace-bound');
        $server = $this->server([
            'requestTraceLogger' => $traceLogger,
        ]);

        $method = new ReflectionMethod(CodexProxyServer::class, 'traceAccountSelection');
        $method->invoke(
            $server,
            'req-selection-bound',
            'http',
            new SessionKey('session-selection-bound'),
            [
                'source' => 'bound_session',
                'selected_account_id' => 'acct-alpha',
                'selected_account_name' => 'alpha',
            ],
        );

        self::assertSame([], $this->traceRecords($traceFile));
    }

    public function testRecordsSessionActivityContextForAccountSelectionTrace(): void
    {
        [$traceFile, $traceLogger] = $this->traceLogger('proxy-selection-trace-session-context');
        $statePath = $this->tempDir('proxy-selection-trace-state') . '/state.json';
        $now = time();
        StateStore::file($statePath)->bindSession('session-selection', 'acct-beta', 'new_session', $now - 120, $now - 60);

        $server = $this->server([
            'requestTraceLogger' => $traceLogger,
            'stateFile' => $statePath,
        ]);

        $method = new ReflectionMethod(CodexProxyServer::class, 'traceAccountSelection');
        $method->invoke(
            $server,
            'req-selection-session-context',
            'http',
            new SessionKey('session-selection'),
            [
                'source' => 'new_session',
                'selected_account_id' => 'acct-beta',
                'selected_account_name' => 'beta',
            ],
        );

        $records = $this->traceRecords($traceFile);
        self::assertCount(1, $records);
        self::assertSame($now - 120, $records[0]['context']['bound_at']);
        self::assertSame($now - 60, $records[0]['context']['last_seen_at']);
        self::assertSame('active', $records[0]['context']['session_activity']);
        self::assertTrue($records[0]['context']['session_is_active']);
        self::assertSame('new_session', $records[0]['context']['bound_selection_source']);
        self::assertSame('acct-beta', $records[0]['context']['bound_account_id']);
    }

    public function testTouchesExistingSessionBindingWhenWebSocketSessionIsReused(): void
    {
        $statePath = $this->tempDir('proxy-session-touch-state') . '/state.json';
        StateStore::file($statePath)->bindSession('session-selection', 'acct-beta', 'new_session', 1_700_000_000, 1_700_000_000);

        $server = $this->server([
            'stateFile' => $statePath,
        ]);

        $method = new ReflectionMethod(CodexProxyServer::class, 'touchSessionBinding');
        $method->invoke($server, new SessionKey('session-selection'), 1_700_000_120);

        self::assertSame([
            'account_id' => 'acct-beta',
            'selection_source' => 'new_session',
            'bound_at' => 1_700_000_000,
            'last_seen_at' => 1_700_000_120,
        ], StateStore::file($statePath)->sessionBinding('session-selection'));
    }

    public function testReloadsSchedulerAccountsFromDisk(): void
    {
        $accountsDir = $this->tempDir('proxy-account-sync');
        $validator = new AccountFileValidator();
        $stale = $validator->validate($this->accountFixture('alpha'));
        $freshPayload = [
            'iss' => 'https://auth.openai.com',
            'email' => 'alpha@example.com',
            'exp' => self::FIXTURE_EXP,
            'fresh' => true,
            'https://api.openai.com/auth' => [
                'chatgpt_account_id' => 'acct-alpha',
                'chatgpt_plan_type' => 'plus',
                'chatgpt_user_id' => 'user-alpha',
            ],
        ];
        $freshToken = $this->makeJwt($freshPayload);
        $scheduler = new Scheduler([$stale], StateStore::memory(), static fn (): int => 1000);
        $bound = $scheduler->accountForSession('thread-sync');
        self::assertNotSame($freshToken, $bound->accessToken());

        $this->writeJson($accountsDir . '/alpha.account.json', [
            'schema' => 'codex-auth-proxy.account.v1',
            'provider' => 'openai-chatgpt-codex',
            'name' => 'alpha',
            'enabled' => true,
            'tokens' => [
                'id_token' => $freshToken,
                'access_token' => $freshToken,
                'refresh_token' => 'refresh-fresh',
                'account_id' => 'acct-alpha',
            ],
            'metadata' => [
                'email' => 'alpha@example.com',
                'plan_type' => 'plus',
            ],
        ]);

        $server = $this->server([
            'accountsDir' => $accountsDir,
        ]);
        $method = new ReflectionMethod(CodexProxyServer::class, 'syncSchedulerAccounts');
        $method->invoke($server, new AccountRepository($accountsDir), $scheduler);

        $reloaded = $scheduler->accountForSession('thread-sync');
        self::assertSame('acct-alpha', $reloaded->accountId());
        self::assertSame($freshToken, $reloaded->accessToken());
    }

    public function testBuildsStructuredProxyUnavailablePayload(): void
    {
        $server = $this->server();

        $method = new ReflectionMethod(CodexProxyServer::class, 'proxyUnavailablePayload');
        $payload = json_decode((string) $method->invoke($server, 'No Codex accounts configured'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('error', $payload['type']);
        self::assertSame('codex_proxy_unavailable', $payload['error']['code']);
        self::assertSame('No Codex accounts configured', $payload['error']['message']);
        self::assertSame(503, $payload['error']['status']);
    }

    public function testShutdownCleanupUsesSwooleShutdownEvent(): void
    {
        $server = $this->server();
        $method = new ReflectionMethod(CodexProxyServer::class, 'shutdownEvent');

        self::assertSame('shutdown', $method->invoke($server));
    }

    /** @param array<string,string|int> $baseOptions */
    private function clientOptionsFor(CodexProxyServer $server, string $host, array $baseOptions): array
    {
        $method = new ReflectionMethod(CodexProxyServer::class, 'clientOptionsFor');

        /** @var array<string,string|int> $options */
        $options = $method->invoke($server, $host, $baseOptions);

        return $options;
    }

    private function account(string $name): \CodexAuthProxy\Account\CodexAccount
    {
        return new \CodexAuthProxy\Account\CodexAccount(
            name: $name,
            accountId: 'acct-' . $name,
            email: $name . '@example.com',
            planType: 'plus',
            idToken: 'id-token',
            accessToken: 'access-token',
            refreshToken: 'refresh-token',
            enabled: true,
        );
    }

    /** @param array<string,mixed> $overrides */
    private function server(array $overrides = []): CodexProxyServer
    {
        $defaults = [
            'host' => '127.0.0.1',
            'port' => 1456,
            'accountsDir' => '/tmp/accounts',
            'stateFile' => '/tmp/state.json',
            'defaultCooldownSeconds' => 18000,
            'upstreamBase' => 'https://chatgpt.com/backend-api/codex',
            'runtimeProfile' => $this->runtimeProfile(),
            'usageBaseUrl' => 'https://chatgpt.com/backend-api',
        ];

        return new CodexProxyServer(...array_replace($defaults, $overrides));
    }

    /** @return array{0:string,1:RequestTraceLogger} */
    private function traceLogger(string $name): array
    {
        $path = $this->tempDir($name) . '/trace.jsonl';

        return [$path, new RequestTraceLogger(LoggerFactory::createTrace($path))];
    }

    /** @return list<array<string,mixed>> */
    private function traceRecords(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            $lines,
        );
    }

    private function config(?string $httpProxy, ?string $httpsProxy, string $noProxy): AppConfig
    {
        return new AppConfig(
            home: '/tmp/home',
            accountsDir: '/tmp/accounts',
            stateFile: '/tmp/state.json',
            host: '127.0.0.1',
            port: 1456,
            cooldownSeconds: 18000,
            callbackHost: 'localhost',
            callbackPort: 1455,
            callbackTimeoutSeconds: 300,
            codexUserAgent: 'ua',
            codexBetaFeatures: 'multi_agent',
            codexOriginator: 'codex-tui',
            codexResidency: '',
            codexUpstreamBaseUrl: 'https://chatgpt.com/backend-api/codex',
            usageBaseUrl: 'https://chatgpt.com/backend-api',
            usageRefreshIntervalSeconds: 600,
            traceMutations: true,
            traceTimings: false,
            httpProxy: $httpProxy,
            httpsProxy: $httpsProxy,
            noProxy: $noProxy,
        );
    }
}
