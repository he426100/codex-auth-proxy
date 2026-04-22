<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Network;

use CodexAuthProxy\Config\AppConfig;
use CodexAuthProxy\Network\OutboundProxyConfig;
use CodexAuthProxy\Tests\TestCase;
use InvalidArgumentException;

final class OutboundProxyConfigTest extends TestCase
{
    public function testBuildsGuzzleProxyArray(): void
    {
        $config = $this->config('http://proxy.local:8080', 'http://secure.local:8443', 'localhost,.openai.com');

        $proxy = OutboundProxyConfig::fromAppConfig($config)->guzzleProxy();

        self::assertSame('http://proxy.local:8080', $proxy['http']);
        self::assertSame('http://secure.local:8443', $proxy['https']);
        self::assertSame(['localhost', '.openai.com'], $proxy['no']);
    }

    public function testParsesProxyUrlForSwoole(): void
    {
        $config = $this->config('http://user:pass@proxy.local:8080', null, '');

        $options = OutboundProxyConfig::fromAppConfig($config)->swooleOptionsFor('chatgpt.com');

        self::assertSame('proxy.local', $options['http_proxy_host']);
        self::assertSame(8080, $options['http_proxy_port']);
        self::assertSame('user', $options['http_proxy_user']);
        self::assertSame('pass', $options['http_proxy_password']);
    }

    public function testParsesSocks5ProxyUrlForSwoole(): void
    {
        $config = $this->config('socks5://user:pass@proxy.local:1080', null, '');

        $options = OutboundProxyConfig::fromAppConfig($config)->swooleOptionsFor('chatgpt.com');

        self::assertSame('proxy.local', $options['socks5_host']);
        self::assertSame(1080, $options['socks5_port']);
        self::assertSame('user', $options['socks5_username']);
        self::assertSame('pass', $options['socks5_password']);
    }

    public function testUsesConfiguredHttpsProxySlotForSwooleWhenProxyUsesHttpScheme(): void
    {
        $config = $this->config('http://proxy.local:8080', 'http://secure.local:8443', '');

        $options = OutboundProxyConfig::fromAppConfig($config)->swooleOptionsFor('chatgpt.com');

        self::assertSame('secure.local', $options['http_proxy_host']);
        self::assertSame(8443, $options['http_proxy_port']);
    }

    public function testRejectsHttpsProxySchemeAtConfigurationBoundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported proxy scheme');

        OutboundProxyConfig::fromAppConfig($this->config(null, 'https://secure.local:8443', ''));
    }

    public function testBypassesHostsListedInNoProxy(): void
    {
        $config = $this->config('http://proxy.local:8080', 'http://proxy.local:8080', 'localhost,127.0.0.1,::1,.openai.com,example.com');
        $proxy = OutboundProxyConfig::fromAppConfig($config);

        self::assertSame([], $proxy->swooleOptionsFor('localhost'));
        self::assertSame([], $proxy->swooleOptionsFor('localhost:443'));
        self::assertSame([], $proxy->swooleOptionsFor('127.0.0.1'));
        self::assertSame([], $proxy->swooleOptionsFor('::1'));
        self::assertSame([], $proxy->swooleOptionsFor('api.openai.com'));
        self::assertSame([], $proxy->swooleOptionsFor('api.example.com'));
        self::assertFalse($proxy->shouldBypassHost('chatgpt.com'));
    }

    public function testBypassesNoProxyEntriesThatIncludePorts(): void
    {
        $config = $this->config('http://proxy.local:8080', 'http://proxy.local:8080', 'localhost:443,api.openai.com:443');
        $proxy = OutboundProxyConfig::fromAppConfig($config);

        self::assertTrue($proxy->shouldBypassHost('localhost:443'));
        self::assertTrue($proxy->shouldBypassHost('api.openai.com:443'));
        self::assertSame([], $proxy->swooleOptionsFor('api.openai.com:443'));
    }

    public function testWildcardNoProxyBypassesEveryHost(): void
    {
        $config = $this->config('http://proxy.local:8080', 'http://proxy.local:8080', '*');
        $proxy = OutboundProxyConfig::fromAppConfig($config);

        self::assertTrue($proxy->shouldBypassHost('chatgpt.com'));
        self::assertSame([], $proxy->swooleOptionsFor('chatgpt.com'));
    }

    public function testBuildsSubprocessProxyEnvironment(): void
    {
        $config = $this->config('http://proxy.local:8080', 'http://secure.local:8443', 'localhost,.openai.com');

        $env = OutboundProxyConfig::fromAppConfig($config)->environment();

        self::assertSame('http://proxy.local:8080', $env['HTTP_PROXY']);
        self::assertSame('http://secure.local:8443', $env['HTTPS_PROXY']);
        self::assertSame('localhost,.openai.com', $env['NO_PROXY']);
    }

    public function testOmitsEmptyProxyValues(): void
    {
        $config = $this->config(null, null, '');
        $proxy = OutboundProxyConfig::fromAppConfig($config);

        self::assertSame([], $proxy->guzzleProxy());
        self::assertSame([], $proxy->swooleOptionsFor('chatgpt.com'));
        self::assertSame([], $proxy->environment());
    }

    public function testRejectsUnsupportedProxySchemes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported proxy scheme');

        OutboundProxyConfig::fromAppConfig($this->config('ftp://proxy.local:1080', null, ''));
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
            httpProxy: $httpProxy,
            httpsProxy: $httpsProxy,
            noProxy: $noProxy,
        );
    }
}
