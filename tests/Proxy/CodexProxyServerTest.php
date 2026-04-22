<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Proxy;

use CodexAuthProxy\Account\AccountFileValidator;
use CodexAuthProxy\Account\AccountRepository;
use CodexAuthProxy\Config\AppConfig;
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
        $server = new CodexProxyServer(
            host: '127.0.0.1',
            port: 1456,
            accountsDir: '/tmp/accounts',
            stateFile: '/tmp/state.json',
            defaultCooldownSeconds: 18000,
            outboundProxyConfig: OutboundProxyConfig::fromAppConfig($this->config(
                httpProxy: 'http://proxy.local:8080',
                httpsProxy: 'http://secure-proxy.local:8443',
                noProxy: 'localhost',
            )),
        );

        $options = $this->clientOptionsFor($server, 'chatgpt.com', ['timeout' => -1]);

        self::assertSame(-1, $options['timeout']);
        self::assertSame('chatgpt.com', $options['ssl_host_name']);
        self::assertSame('secure-proxy.local', $options['http_proxy_host']);
        self::assertSame(8443, $options['http_proxy_port']);
    }

    public function testClientOptionsIncludeConfiguredSocks5ProxyForUpstreamHost(): void
    {
        $server = new CodexProxyServer(
            host: '127.0.0.1',
            port: 1456,
            accountsDir: '/tmp/accounts',
            stateFile: '/tmp/state.json',
            defaultCooldownSeconds: 18000,
            outboundProxyConfig: OutboundProxyConfig::fromAppConfig($this->config(
                httpProxy: 'socks5://user:pass@proxy.local:1080',
                httpsProxy: null,
                noProxy: 'localhost',
            )),
        );

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
        $server = new CodexProxyServer(
            host: '127.0.0.1',
            port: 1456,
            accountsDir: '/tmp/accounts',
            stateFile: '/tmp/state.json',
            defaultCooldownSeconds: 18000,
            outboundProxyConfig: OutboundProxyConfig::fromAppConfig($this->config(
                httpProxy: 'http://proxy.local:8080',
                httpsProxy: 'http://secure-proxy.local:8443',
                noProxy: 'chatgpt.com',
            )),
        );

        $options = $this->clientOptionsFor($server, 'chatgpt.com', ['timeout' => -1]);

        self::assertSame([
            'timeout' => -1,
            'ssl_host_name' => 'chatgpt.com',
        ], $options);
    }

    public function testRecordsTraceForUpstreamError(): void
    {
        $traceDir = $this->tempDir('proxy-trace');
        $server = new CodexProxyServer(
            host: '127.0.0.1',
            port: 1456,
            accountsDir: '/tmp/accounts',
            stateFile: '/tmp/state.json',
            defaultCooldownSeconds: 18000,
            requestTraceLogger: new RequestTraceLogger($traceDir),
        );

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

        $files = glob($traceDir . '/*.json') ?: [];
        self::assertCount(1, $files);
        $payload = json_decode((string) file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('req12345', $payload['request_id']);
        self::assertSame('session-a', $payload['session']);
        self::assertSame('alpha', $payload['account']);
        self::assertSame(429, $payload['status']);
        self::assertSame('quota', $payload['classification']);
        self::assertStringNotContainsString('secret-token', $payload['message']);
    }

    public function testRecordsTraceForWebSocketStreamError(): void
    {
        $traceDir = $this->tempDir('proxy-websocket-trace');
        $server = new CodexProxyServer(
            host: '127.0.0.1',
            port: 1456,
            accountsDir: '/tmp/accounts',
            stateFile: '/tmp/state.json',
            defaultCooldownSeconds: 18000,
            requestTraceLogger: new RequestTraceLogger($traceDir),
        );

        $method = new ReflectionMethod(CodexProxyServer::class, 'traceWebSocketStreamError');
        $method->invoke(
            $server,
            'req-ws',
            new SessionKey('session-ws'),
            $this->account('alpha'),
            '{"type":"error","error":{"code":"usage_limit_reached","message":"too many requests"}}',
            new ErrorClassifier(18000),
        );

        $files = glob($traceDir . '/*.json') ?: [];
        self::assertCount(1, $files);
        $payload = json_decode((string) file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('req-ws', $payload['request_id']);
        self::assertSame('websocket', $payload['transport']);
        self::assertSame('upstream_error', $payload['phase']);
        self::assertSame('session-ws', $payload['session']);
        self::assertSame('alpha', $payload['account']);
        self::assertSame(200, $payload['status']);
        self::assertSame('quota', $payload['classification']);
    }

    public function testRecordsTraceForPayloadMutations(): void
    {
        $traceDir = $this->tempDir('proxy-mutation-trace');
        $server = new CodexProxyServer(
            host: '127.0.0.1',
            port: 1456,
            accountsDir: '/tmp/accounts',
            stateFile: '/tmp/state.json',
            defaultCooldownSeconds: 18000,
            requestTraceLogger: new RequestTraceLogger($traceDir),
        );

        $method = new ReflectionMethod(CodexProxyServer::class, 'tracePayloadMutations');
        $method->invoke(
            $server,
            'req-mutation',
            'http',
            new SessionKey('session-mutation'),
            ['http.input.string_to_message'],
        );

        $files = glob($traceDir . '/*.json') ?: [];
        self::assertCount(1, $files);
        $payload = json_decode((string) file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('req-mutation', $payload['request_id']);
        self::assertSame('http', $payload['transport']);
        self::assertSame('request_normalized', $payload['phase']);
        self::assertSame('session-mutation', $payload['session']);
        self::assertSame(['http.input.string_to_message'], $payload['mutations']);
    }

    public function testSkipsTraceForPayloadMutationsWhenDisabled(): void
    {
        $traceDir = $this->tempDir('proxy-mutation-trace-disabled');
        $server = new CodexProxyServer(
            host: '127.0.0.1',
            port: 1456,
            accountsDir: '/tmp/accounts',
            stateFile: '/tmp/state.json',
            defaultCooldownSeconds: 18000,
            requestTraceLogger: new RequestTraceLogger($traceDir),
            traceMutations: false,
        );

        $method = new ReflectionMethod(CodexProxyServer::class, 'tracePayloadMutations');
        $method->invoke(
            $server,
            'req-mutation-disabled',
            'http',
            new SessionKey('session-mutation-disabled'),
            ['http.input.string_to_message'],
        );

        self::assertSame([], glob($traceDir . '/*.json') ?: []);
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

        $server = new CodexProxyServer(
            host: '127.0.0.1',
            port: 1456,
            accountsDir: $accountsDir,
            stateFile: '/tmp/state.json',
            defaultCooldownSeconds: 18000,
        );
        $method = new ReflectionMethod(CodexProxyServer::class, 'syncSchedulerAccounts');
        $method->invoke($server, new AccountRepository($accountsDir), $scheduler);

        $reloaded = $scheduler->accountForSession('thread-sync');
        self::assertSame('acct-alpha', $reloaded->accountId());
        self::assertSame($freshToken, $reloaded->accessToken());
    }

    public function testBuildsStructuredProxyUnavailablePayload(): void
    {
        $server = new CodexProxyServer(
            host: '127.0.0.1',
            port: 1456,
            accountsDir: '/tmp/accounts',
            stateFile: '/tmp/state.json',
            defaultCooldownSeconds: 18000,
        );

        $method = new ReflectionMethod(CodexProxyServer::class, 'proxyUnavailablePayload');
        $payload = json_decode((string) $method->invoke($server, 'No Codex accounts configured'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('error', $payload['type']);
        self::assertSame('codex_proxy_unavailable', $payload['error']['code']);
        self::assertSame('No Codex accounts configured', $payload['error']['message']);
        self::assertSame(503, $payload['error']['status']);
    }

    public function testShutdownCleanupUsesSwooleShutdownEvent(): void
    {
        $server = new CodexProxyServer(
            host: '127.0.0.1',
            port: 1456,
            accountsDir: '/tmp/accounts',
            stateFile: '/tmp/state.json',
            defaultCooldownSeconds: 18000,
        );
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
            logLevel: 'warning',
            codexUserAgent: 'ua',
            codexBetaFeatures: 'multi_agent',
            traceDir: '/tmp/traces',
            traceMutations: true,
            httpProxy: $httpProxy,
            httpsProxy: $httpsProxy,
            noProxy: $noProxy,
        );
    }
}
