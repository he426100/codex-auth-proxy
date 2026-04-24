<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Codex;

use CodexAuthProxy\Config\AppConfig;
use CodexAuthProxy\Tests\TestCase;

final class CodexRuntimeProfileTest extends TestCase
{
    public function testProvidesProjectScopedDefaults(): void
    {
        self::assertSame('codex-auth-proxy/0.1.0', \CodexAuthProxy\Codex\CodexRuntimeProfile::defaultUserAgent());
        self::assertSame('', \CodexAuthProxy\Codex\CodexRuntimeProfile::defaultBetaFeatures());
        self::assertSame('codex-auth-proxy', \CodexAuthProxy\Codex\CodexRuntimeProfile::defaultOriginator());
        self::assertSame('', \CodexAuthProxy\Codex\CodexRuntimeProfile::defaultResidency());
    }

    public function testBuildsProfileFromAppConfig(): void
    {
        $config = new AppConfig(
            home: '/tmp/home',
            accountsDir: '/tmp/accounts',
            stateFile: '/tmp/state.json',
            host: '127.0.0.1',
            port: 1456,
            cooldownSeconds: 18000,
            callbackHost: 'localhost',
            callbackPort: 1455,
            callbackTimeoutSeconds: 300,
            codexUserAgent: 'ua-test',
            codexBetaFeatures: 'beta-test',
            codexOriginator: 'originator-test',
            codexResidency: 'global',
            codexUpstreamBaseUrl: 'https://chatgpt.com/backend-api/codex',
            usageBaseUrl: 'https://chatgpt.com/backend-api',
            usageRefreshIntervalSeconds: 600,
            traceMutations: true,
            traceTimings: false,
            httpProxy: null,
            httpsProxy: null,
            noProxy: 'localhost',
        );

        $profile = \CodexAuthProxy\Codex\CodexRuntimeProfile::fromAppConfig($config);

        self::assertSame('ua-test', $profile->userAgent);
        self::assertSame('beta-test', $profile->betaFeatures);
        self::assertSame('originator-test', $profile->originator);
        self::assertSame('global', $profile->residency);
    }
}
