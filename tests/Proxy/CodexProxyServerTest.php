<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Proxy;

use CodexAuthProxy\Config\AppConfig;
use CodexAuthProxy\Network\OutboundProxyConfig;
use CodexAuthProxy\Proxy\CodexProxyServer;
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
        self::assertSame('secure-proxy.local', $options['http_proxy_host']);
        self::assertSame(8443, $options['http_proxy_port']);
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

        self::assertSame(['timeout' => -1], $options);
    }

    /** @param array<string,string|int> $baseOptions */
    private function clientOptionsFor(CodexProxyServer $server, string $host, array $baseOptions): array
    {
        $method = new ReflectionMethod(CodexProxyServer::class, 'clientOptionsFor');

        /** @var array<string,string|int> $options */
        $options = $method->invoke($server, $host, $baseOptions);

        return $options;
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
            httpProxy: $httpProxy,
            httpsProxy: $httpsProxy,
            noProxy: $noProxy,
        );
    }
}
