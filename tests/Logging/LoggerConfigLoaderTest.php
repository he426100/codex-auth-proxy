<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Logging;

use CodexAuthProxy\Logging\LoggerConfigLoader;
use CodexAuthProxy\Tests\TestCase;

final class LoggerConfigLoaderTest extends TestCase
{
    public function testLoadUsesBasePathForDefaultAndTraceChannels(): void
    {
        $snapshot = $this->snapshotEnv([
            'CODEX_AUTH_PROXY_LOG_FILE',
            'CODEX_AUTH_PROXY_LOG_LEVEL',
            'CODEX_AUTH_PROXY_TRACE_FILE',
            'CODEX_AUTH_PROXY_TRACE_LEVEL',
            'CODEX_AUTH_PROXY_TRACE_DIR',
        ]);

        try {
            $this->unsetEnv('CODEX_AUTH_PROXY_LOG_FILE');
            $this->unsetEnv('CODEX_AUTH_PROXY_LOG_LEVEL');
            $this->unsetEnv('CODEX_AUTH_PROXY_TRACE_FILE');
            $this->unsetEnv('CODEX_AUTH_PROXY_TRACE_LEVEL');
            $this->unsetEnv('CODEX_AUTH_PROXY_TRACE_DIR');

            $config = (new LoggerConfigLoader(basePath: '/tmp/cap-base'))->load();

            self::assertSame('default', $config['default']);
            self::assertSame('/tmp/cap-base/runtime/logs/app.log', $config['channels']['default']['handler']['constructor']['stream']);
            self::assertSame('warning', $config['channels']['default']['handler']['constructor']['level']);
            self::assertSame('/tmp/cap-base/runtime/logs/trace.jsonl', $config['channels']['trace']['handler']['constructor']['stream']);
            self::assertSame('info', $config['channels']['trace']['handler']['constructor']['level']);
        } finally {
            $this->restoreEnv($snapshot);
        }
    }

    public function testLoadAppliesEnvironmentOverrides(): void
    {
        $snapshot = $this->snapshotEnv([
            'CODEX_AUTH_PROXY_LOG_FILE',
            'CODEX_AUTH_PROXY_LOG_LEVEL',
            'CODEX_AUTH_PROXY_TRACE_FILE',
            'CODEX_AUTH_PROXY_TRACE_LEVEL',
        ]);

        try {
            $this->setEnv('CODEX_AUTH_PROXY_LOG_FILE', './runtime/logs/app.log');
            $this->setEnv('CODEX_AUTH_PROXY_LOG_LEVEL', 'error');
            $this->setEnv('CODEX_AUTH_PROXY_TRACE_FILE', './runtime/logs/trace.jsonl');
            $this->setEnv('CODEX_AUTH_PROXY_TRACE_LEVEL', 'warning');

            $config = (new LoggerConfigLoader(basePath: '/tmp/cap-base'))->load();

            self::assertSame('/tmp/cap-base/runtime/logs/app.log', $config['channels']['default']['handler']['constructor']['stream']);
            self::assertSame('error', $config['channels']['default']['handler']['constructor']['level']);
            self::assertSame('/tmp/cap-base/runtime/logs/trace.jsonl', $config['channels']['trace']['handler']['constructor']['stream']);
            self::assertSame('warning', $config['channels']['trace']['handler']['constructor']['level']);
        } finally {
            $this->restoreEnv($snapshot);
        }
    }

    public function testLoadFallsBackFromLegacyTraceDirEnvironment(): void
    {
        $snapshot = $this->snapshotEnv([
            'CODEX_AUTH_PROXY_TRACE_FILE',
            'CODEX_AUTH_PROXY_TRACE_DIR',
        ]);

        try {
            $this->unsetEnv('CODEX_AUTH_PROXY_TRACE_FILE');
            $this->setEnv('CODEX_AUTH_PROXY_TRACE_DIR', './runtime/traces');

            $config = (new LoggerConfigLoader(basePath: '/tmp/cap-base'))->load();

            self::assertSame('/tmp/cap-base/runtime/traces/trace.jsonl', $config['channels']['trace']['handler']['constructor']['stream']);
        } finally {
            $this->restoreEnv($snapshot);
        }
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

    private function setEnv(string $name, string $value): void
    {
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
        putenv($name . '=' . $value);
    }

    private function unsetEnv(string $name): void
    {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    }
}
