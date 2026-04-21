<?php

declare(strict_types=1);

namespace CodexAuthProxy\Config;

use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Dotenv\Dotenv;

final class AppConfigLoader
{
    public function __construct(private readonly ?string $home = null, private readonly ?string $envFile = null)
    {
    }

    /** @param array<string,mixed> $overrides */
    public function load(array $overrides = []): AppConfig
    {
        $this->loadDotenv();

        $home = $this->stringValue($overrides['home'] ?? null)
            ?? $this->env('CODEX_AUTH_PROXY_HOME')
            ?? $this->home
            ?? (getenv('HOME') ?: '.');
        $home = rtrim($home, '/');
        $root = $home . '/.config/codex-auth-proxy';

        $raw = [
            'home' => $home,
            'accounts_dir' => $this->stringValue($overrides['accounts_dir'] ?? null) ?? $this->env('CODEX_AUTH_PROXY_ACCOUNTS_DIR') ?? $root . '/accounts',
            'state_file' => $this->stringValue($overrides['state_file'] ?? null) ?? $this->env('CODEX_AUTH_PROXY_STATE_FILE') ?? $root . '/state.json',
            'host' => $this->stringValue($overrides['host'] ?? null) ?? $this->env('CODEX_AUTH_PROXY_HOST') ?? '127.0.0.1',
            'port' => $this->intValue($overrides['port'] ?? null) ?? $this->envInt('CODEX_AUTH_PROXY_PORT') ?? 1456,
            'cooldown_seconds' => $this->intValue($overrides['cooldown_seconds'] ?? null) ?? $this->envInt('CODEX_AUTH_PROXY_COOLDOWN_SECONDS') ?? 18000,
            'callback_host' => $this->stringValue($overrides['callback_host'] ?? null) ?? $this->env('CODEX_AUTH_PROXY_CALLBACK_HOST') ?? 'localhost',
            'callback_port' => $this->intValue($overrides['callback_port'] ?? null) ?? $this->envInt('CODEX_AUTH_PROXY_CALLBACK_PORT') ?? 1455,
            'callback_timeout_seconds' => $this->intValue($overrides['callback_timeout_seconds'] ?? null) ?? $this->envInt('CODEX_AUTH_PROXY_CALLBACK_TIMEOUT_SECONDS') ?? 300,
            'log_level' => $this->stringValue($overrides['log_level'] ?? null) ?? $this->env('CODEX_AUTH_PROXY_LOG_LEVEL') ?? 'warning',
            'codex_user_agent' => $this->stringValue($overrides['codex_user_agent'] ?? null) ?? $this->env('CODEX_AUTH_PROXY_CODEX_USER_AGENT') ?? 'codex_cli_rs/0.114.0 codex-auth-proxy/0.1.0',
            'codex_beta_features' => $this->stringValue($overrides['codex_beta_features'] ?? null) ?? $this->env('CODEX_AUTH_PROXY_CODEX_BETA_FEATURES') ?? 'multi_agent',
        ];

        /** @var array{home:string,accounts_dir:string,state_file:string,host:string,port:int,cooldown_seconds:int,callback_host:string,callback_port:int,callback_timeout_seconds:int,log_level:string,codex_user_agent:string,codex_beta_features:string} $processed */
        $processed = (new Processor())->processConfiguration(new AppConfiguration(), [$raw]);

        return new AppConfig(
            $processed['home'],
            $processed['accounts_dir'],
            $processed['state_file'],
            $processed['host'],
            $processed['port'],
            $processed['cooldown_seconds'],
            $processed['callback_host'],
            $processed['callback_port'],
            $processed['callback_timeout_seconds'],
            $processed['log_level'],
            $processed['codex_user_agent'],
            $processed['codex_beta_features'],
        );
    }

    private function loadDotenv(): void
    {
        $envFile = $this->envFile ?? getcwd() . '/.env';
        if (is_file($envFile)) {
            (new Dotenv())->usePutenv()->loadEnv($envFile);
        }
    }

    private function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function envInt(string $name): ?int
    {
        $value = $this->env($name);

        return $value === null ? null : (int) $value;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            return (int) $value;
        }

        return null;
    }
}
