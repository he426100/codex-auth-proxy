<?php

declare(strict_types=1);

namespace CodexAuthProxy\Config;

use RuntimeException;
use Symfony\Component\Config\Definition\Processor;

final class AppConfigLoader
{
    public function __construct(
        private readonly ?string $home = null,
        private readonly ?string $defaultsFile = null,
    ) {
    }

    /** @param array<string,mixed> $overrides */
    public function load(array $overrides = []): AppConfig
    {
        $defaults = $this->loadDefaults();

        $home = $this->stringValue($overrides['home'] ?? null)
            ?? $this->home
            ?? $this->stringValue($defaults['home'] ?? null)
            ?? '.';
        $home = rtrim($home, '/');
        $root = $home . '/.config/codex-auth-proxy';

        $raw = [
            'home' => $home,
            'accounts_dir' => $this->stringValue($overrides['accounts_dir'] ?? null)
                ?? $this->stringValue($defaults['accounts_dir'] ?? null)
                ?? $root . '/accounts',
            'state_file' => $this->stringValue($overrides['state_file'] ?? null)
                ?? $this->stringValue($defaults['state_file'] ?? null)
                ?? $root . '/state.json',
            'host' => $this->stringValue($overrides['host'] ?? null)
                ?? $this->stringValue($defaults['host'] ?? null),
            'port' => $this->intValue($overrides['port'] ?? null)
                ?? $this->intValue($defaults['port'] ?? null),
            'cooldown_seconds' => $this->intValue($overrides['cooldown_seconds'] ?? null)
                ?? $this->intValue($defaults['cooldown_seconds'] ?? null),
            'callback_host' => $this->stringValue($overrides['callback_host'] ?? null)
                ?? $this->stringValue($defaults['callback_host'] ?? null),
            'callback_port' => $this->intValue($overrides['callback_port'] ?? null)
                ?? $this->intValue($defaults['callback_port'] ?? null),
            'callback_timeout_seconds' => $this->intValue($overrides['callback_timeout_seconds'] ?? null)
                ?? $this->intValue($defaults['callback_timeout_seconds'] ?? null),
            'log_level' => $this->stringValue($overrides['log_level'] ?? null)
                ?? $this->stringValue($defaults['log_level'] ?? null),
            'codex_user_agent' => $this->stringValue($overrides['codex_user_agent'] ?? null)
                ?? $this->stringValue($defaults['codex_user_agent'] ?? null),
            'codex_beta_features' => $this->stringValue($overrides['codex_beta_features'] ?? null)
                ?? $this->stringValue($defaults['codex_beta_features'] ?? null),
            'trace_dir' => $this->stringValue($overrides['trace_dir'] ?? null)
                ?? $this->stringValue($defaults['trace_dir'] ?? null)
                ?? $root . '/traces',
            'http_proxy' => $this->stringValue($overrides['http_proxy'] ?? null)
                ?? $this->stringValue($defaults['http_proxy'] ?? null),
            'https_proxy' => $this->stringValue($overrides['https_proxy'] ?? null)
                ?? $this->stringValue($defaults['https_proxy'] ?? null),
            'no_proxy' => $this->stringValue($overrides['no_proxy'] ?? null)
                ?? $this->stringValue($defaults['no_proxy'] ?? null)
                ?? 'localhost,127.0.0.1,::1',
        ];

        /** @var array{
         *   home:string,
         *   accounts_dir:string,
         *   state_file:string,
         *   host:string,
         *   port:int,
         *   cooldown_seconds:int,
         *   callback_host:string,
         *   callback_port:int,
         *   callback_timeout_seconds:int,
         *   log_level:string,
         *   codex_user_agent:string,
         *   codex_beta_features:string,
         *   trace_dir:string,
         *   http_proxy:?string,
         *   https_proxy:?string,
         *   no_proxy:string
         * } $processed
         */
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
            $processed['trace_dir'],
            $processed['http_proxy'],
            $processed['https_proxy'],
            $processed['no_proxy'],
        );
    }

    /** @return array<string,mixed> */
    private function loadDefaults(): array
    {
        $defaultsFile = $this->defaultsFile ?? dirname(__DIR__, 2) . '/config/defaults.php';
        if (!is_file($defaultsFile)) {
            throw new RuntimeException('Missing defaults file: ' . $defaultsFile);
        }

        $defaults = require $defaultsFile;
        if (!is_array($defaults)) {
            throw new RuntimeException('Defaults file must return an array: ' . $defaultsFile);
        }

        return $defaults;
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
