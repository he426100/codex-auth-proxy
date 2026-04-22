<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Config;

use CodexAuthProxy\Config\AppConfigLoader;
use CodexAuthProxy\Tests\TestCase;

final class AppConfigLoaderTest extends TestCase
{
    public function testLoadUsesCommittedDefaults(): void
    {
        $snapshot = $this->snapshotEnv($this->proxyEnvNames());
        $cwd = getcwd();

        try {
            $this->unsetEnvNames($this->proxyEnvNames());
            $this->disableDotenv();
            chdir($this->tempDir('cap-empty-cwd'));

            $config = $this->newLoader()->load();

            self::assertSame('/tmp/codex-auth-home', $config->home);
            self::assertSame('/tmp/codex-auth-home/.config/codex-auth-proxy/accounts', $config->accountsDir);
            self::assertSame('/tmp/codex-auth-home/.config/codex-auth-proxy/state.json', $config->stateFile);
            self::assertSame('127.0.0.1', $config->host);
            self::assertSame(1456, $config->port);
            self::assertSame(18000, $config->cooldownSeconds);
            self::assertSame('localhost', $config->callbackHost);
            self::assertSame(1455, $config->callbackPort);
            self::assertSame(300, $config->callbackTimeoutSeconds);
            self::assertSame('warning', $config->logLevel);
            self::assertSame('codex_cli_rs/0.114.0 codex-auth-proxy/0.1.0', $config->codexUserAgent);
            self::assertSame('multi_agent', $config->codexBetaFeatures);
            self::assertSame('/tmp/codex-auth-home/.config/codex-auth-proxy/traces', $config->traceDir);
            self::assertTrue($config->traceMutations);
            self::assertNull($config->httpProxy);
            self::assertNull($config->httpsProxy);
            self::assertSame('localhost,127.0.0.1,::1', $config->noProxy);
        } finally {
            $this->restoreEnv($snapshot);

            if ($cwd !== false) {
                chdir($cwd);
            }
        }
    }

    public function testLoadAppliesEnvironmentOverrides(): void
    {
        $snapshot = $this->snapshotEnv($this->proxyEnvNames());
        $cwd = getcwd();

        try {
            $this->disableDotenv();
            $this->setEnv('CODEX_AUTH_PROXY_HOST', '10.10.10.10');
            $this->setEnv('CODEX_AUTH_PROXY_PORT', '2468');
            $this->setEnv('CODEX_AUTH_PROXY_TRACE_DIR', '/tmp/codex-traces');
            $this->setEnv('CODEX_AUTH_PROXY_TRACE_MUTATIONS', 'false');
            $this->setEnv('CODEX_AUTH_PROXY_HTTP_PROXY', 'http://auth-http:8888');
            $this->setEnv('CODEX_AUTH_PROXY_HTTPS_PROXY', 'https://auth-https:9443');
            $this->setEnv('CODEX_AUTH_PROXY_NO_PROXY', 'auth.local');

            chdir($this->tempDir('cap-empty-cwd'));

            $config = $this->newLoader()->load();

            self::assertSame('10.10.10.10', $config->host);
            self::assertSame(2468, $config->port);
            self::assertSame('/tmp/codex-traces', $config->traceDir);
            self::assertFalse($config->traceMutations);
            self::assertSame('http://auth-http:8888', $config->httpProxy);
            self::assertSame('https://auth-https:9443', $config->httpsProxy);
            self::assertSame('auth.local', $config->noProxy);
        } finally {
            $this->restoreEnv($snapshot);

            if ($cwd !== false) {
                chdir($cwd);
            }
        }
    }

    public function testCommittedDefaultsLoadDotenvFromConfiguredFile(): void
    {
        $snapshot = $this->snapshotEnv($this->proxyEnvNames());
        $cwd = getcwd();
        $dotenvDir = $this->tempDir('cap-dotenv-cwd');
        file_put_contents($dotenvDir . '/.env', implode("\n", [
            'CODEX_AUTH_PROXY_HOME=/tmp/codex-auth-dotenv-home',
            'CODEX_AUTH_PROXY_HOST=10.30.40.50',
            'CODEX_AUTH_PROXY_PORT=4567',
            'CODEX_AUTH_PROXY_HTTPS_PROXY=http://dotenv-proxy.local:8443',
            '',
        ]));

        try {
            $this->unsetEnvNames($this->proxyEnvNames());
            $this->setEnv('CODEX_AUTH_PROXY_DOTENV_FILE', $dotenvDir . '/.env');
            chdir($this->tempDir('cap-empty-cwd'));

            $config = (new AppConfigLoader())->load();

            self::assertSame('/tmp/codex-auth-dotenv-home', $config->home);
            self::assertSame('10.30.40.50', $config->host);
            self::assertSame(4567, $config->port);
            self::assertSame('http://dotenv-proxy.local:8443', $config->httpsProxy);
        } finally {
            $this->restoreEnv($snapshot);

            if ($cwd !== false) {
                chdir($cwd);
            }
        }
    }

    public function testDefaultsFileCanReadEnvironmentWithEnvHelper(): void
    {
        $names = [
            'CAP_TEST_HOME',
            'CAP_TEST_HOST',
            'CAP_TEST_PORT',
            'CAP_TEST_HTTP_PROXY',
        ];
        $snapshot = $this->snapshotEnv($names);
        $defaultsFile = $this->tempDir('cap-config') . '/defaults.php';
        $envHelper = dirname(__DIR__, 2) . '/src/Config/env.php';
        file_put_contents($defaultsFile, <<<PHP
<?php

declare(strict_types=1);

require_once '{$envHelper}';

return [
    'home' => env('CAP_TEST_HOME', '/tmp/default-home'),
    'host' => env('CAP_TEST_HOST', '127.0.0.1'),
    'port' => (int) env('CAP_TEST_PORT', 1456),
    'cooldown_seconds' => 18000,
    'callback_host' => 'localhost',
    'callback_port' => 1455,
    'callback_timeout_seconds' => 300,
    'log_level' => 'warning',
    'codex_user_agent' => 'ua',
    'codex_beta_features' => 'multi_agent',
    'http_proxy' => env('CAP_TEST_HTTP_PROXY'),
    'https_proxy' => null,
    'no_proxy' => 'localhost,127.0.0.1,::1',
];
PHP);

        try {
            $this->setEnv('CAP_TEST_HOME', '/tmp/hyperf-style-home');
            $this->setEnv('CAP_TEST_HOST', '10.20.30.40');
            $this->setEnv('CAP_TEST_PORT', '3456');
            $this->setEnv('CAP_TEST_HTTP_PROXY', 'http://proxy.local:8080');

            $config = (new AppConfigLoader(defaultsFile: $defaultsFile))->load();

            self::assertSame('/tmp/hyperf-style-home', $config->home);
            self::assertSame('10.20.30.40', $config->host);
            self::assertSame(3456, $config->port);
            self::assertSame('http://proxy.local:8080', $config->httpProxy);
        } finally {
            $this->restoreEnv($snapshot);
        }
    }

    public function testLoadIgnoresStandardProxyEnvironmentVariables(): void
    {
        $snapshot = $this->snapshotEnv($this->proxyEnvNames());
        $cwd = getcwd();

        try {
            $this->unsetEnvNames([
                'CODEX_AUTH_PROXY_HTTP_PROXY',
                'CODEX_AUTH_PROXY_HTTPS_PROXY',
                'CODEX_AUTH_PROXY_NO_PROXY',
            ]);
            $this->disableDotenv();
            $this->setEnv('HTTP_PROXY', 'http://standard-http:8080');
            $this->setEnv('http_proxy', 'http://standard-http-lower:8081');
            $this->setEnv('HTTPS_PROXY', 'https://standard-https:8443');
            $this->setEnv('https_proxy', 'https://standard-https-lower:8444');
            $this->setEnv('NO_PROXY', 'standard.local');
            $this->setEnv('no_proxy', 'standard-lower.local');

            chdir($this->tempDir('cap-empty-cwd'));

            $config = $this->newLoader()->load();

            self::assertNull($config->httpProxy);
            self::assertNull($config->httpsProxy);
            self::assertSame('localhost,127.0.0.1,::1', $config->noProxy);
        } finally {
            $this->restoreEnv($snapshot);

            if ($cwd !== false) {
                chdir($cwd);
            }
        }
    }

    public function testLoadAppliesCliOverridesBeforeEnvironment(): void
    {
        $snapshot = $this->snapshotEnv($this->proxyEnvNames());
        $cwd = getcwd();

        try {
            $this->disableDotenv();
            $this->setEnv('CODEX_AUTH_PROXY_HOST', '10.10.10.10');
            $this->setEnv('CODEX_AUTH_PROXY_PORT', '2468');
            $this->setEnv('CODEX_AUTH_PROXY_HTTP_PROXY', 'http://auth-http:8888');
            $this->setEnv('CODEX_AUTH_PROXY_HTTPS_PROXY', 'https://auth-https:9443');
            $this->setEnv('CODEX_AUTH_PROXY_NO_PROXY', 'auth.local');

            chdir($this->tempDir('cap-empty-cwd'));

            $config = $this->newLoader()->load([
                'host' => '192.168.0.10',
                'port' => 9000,
                'http_proxy' => 'http://cli-http:9000',
                'https_proxy' => 'https://cli-https:9443',
                'no_proxy' => 'cli.local',
            ]);

            self::assertSame('192.168.0.10', $config->host);
            self::assertSame(9000, $config->port);
            self::assertSame('http://cli-http:9000', $config->httpProxy);
            self::assertSame('https://cli-https:9443', $config->httpsProxy);
            self::assertSame('cli.local', $config->noProxy);
        } finally {
            $this->restoreEnv($snapshot);

            if ($cwd !== false) {
                chdir($cwd);
            }
        }
    }

    /** @return list<string> */
    private function proxyEnvNames(): array
    {
        return [
            'HTTP_PROXY',
            'http_proxy',
            'HTTPS_PROXY',
            'https_proxy',
            'NO_PROXY',
            'no_proxy',
            'CODEX_AUTH_PROXY_HOME',
            'CODEX_AUTH_PROXY_DOTENV_FILE',
            'CODEX_AUTH_PROXY_HOST',
            'CODEX_AUTH_PROXY_PORT',
            'CODEX_AUTH_PROXY_ACCOUNTS_DIR',
            'CODEX_AUTH_PROXY_STATE_FILE',
            'CODEX_AUTH_PROXY_CALLBACK_HOST',
            'CODEX_AUTH_PROXY_CALLBACK_PORT',
            'CODEX_AUTH_PROXY_CALLBACK_TIMEOUT_SECONDS',
            'CODEX_AUTH_PROXY_LOG_LEVEL',
            'CODEX_AUTH_PROXY_CODEX_USER_AGENT',
            'CODEX_AUTH_PROXY_CODEX_BETA_FEATURES',
            'CODEX_AUTH_PROXY_TRACE_DIR',
            'CODEX_AUTH_PROXY_TRACE_MUTATIONS',
            'CODEX_AUTH_PROXY_HTTP_PROXY',
            'CODEX_AUTH_PROXY_HTTPS_PROXY',
            'CODEX_AUTH_PROXY_NO_PROXY',
        ];
    }

    /** @param list<string> $names */
    private function snapshotEnv(array $names): array
    {
        $snapshot = [];

        foreach ($names as $name) {
            $snapshot[$name] = [
                'server' => array_key_exists($name, $_SERVER) ? $_SERVER[$name] : null,
                'env' => array_key_exists($name, $_ENV) ? $_ENV[$name] : null,
                'putenv' => getenv($name) === false ? null : getenv($name),
            ];
        }

        return $snapshot;
    }

    /** @param array<string,array{server:mixed,env:mixed,putenv:mixed}> $snapshot */
    private function restoreEnv(array $snapshot): void
    {
        foreach ($snapshot as $name => $values) {
            $this->restoreEnvValue($name, $values['server'], $_SERVER);
            $this->restoreEnvValue($name, $values['env'], $_ENV);

            if ($values['putenv'] === null) {
                putenv($name);
                continue;
            }

            putenv($name . '=' . $values['putenv']);
        }
    }

    /** @param array<string,mixed> $target */
    private function restoreEnvValue(string $name, mixed $value, array &$target): void
    {
        if ($value === null) {
            unset($target[$name]);

            return;
        }

        $target[$name] = $value;
    }

    /** @param list<string> $names */
    private function unsetEnvNames(array $names): void
    {
        foreach ($names as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }
    }

    private function setEnv(string $name, string $value): void
    {
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
        putenv($name . '=' . $value);
    }

    private function disableDotenv(): void
    {
        $this->setEnv('CODEX_AUTH_PROXY_DOTENV_FILE', $this->tempDir('cap-no-dotenv') . '/missing.env');
    }

    private function newLoader(): AppConfigLoader
    {
        return new AppConfigLoader('/tmp/codex-auth-home');
    }
}
