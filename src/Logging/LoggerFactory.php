<?php

declare(strict_types=1);

namespace CodexAuthProxy\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class LoggerFactory
{
    /** @param array<string,mixed> $config */
    public static function create(array $config, ?string $channel = null): LoggerInterface
    {
        $channel ??= self::defaultChannel($config);
        $definition = self::channelDefinition($config, $channel);
        $logger = new Logger('codex-auth-proxy.' . $channel);
        $handler = self::buildHandler($definition['handler'] ?? [], $channel);
        $formatter = self::buildFormatter($definition['formatter'] ?? []);
        if ($formatter instanceof FormatterInterface) {
            if (!$handler instanceof FormattableHandlerInterface) {
                throw new RuntimeException('Logger handler does not support formatters for channel: ' . $channel);
            }
            $handler->setFormatter($formatter);
        }
        $logger->pushHandler($handler);

        return $logger;
    }

    public static function createTrace(string $path, string $level = 'info'): LoggerInterface
    {
        self::ensureParentDirectory($path);

        $logger = new Logger('codex-auth-proxy.trace');
        $handler = new StreamHandler($path, Level::fromName(strtoupper($level)), true, 0600);
        $handler->setFormatter(new JsonFormatter());
        $logger->pushHandler($handler);

        return $logger;
    }

    private static function ensureParentDirectory(string $path): void
    {
        $directory = dirname($path);
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create log directory: ' . $directory);
        }
    }

    /** @param array<string,mixed> $config */
    private static function defaultChannel(array $config): string
    {
        $channel = $config['default'] ?? 'default';
        if (!is_string($channel) || $channel === '') {
            throw new RuntimeException('Logger config "default" channel must be a non-empty string');
        }

        return $channel;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function channelDefinition(array $config, string $channel): array
    {
        $channels = $config['channels'] ?? null;
        if (!is_array($channels) || !isset($channels[$channel]) || !is_array($channels[$channel])) {
            throw new RuntimeException('Logger channel is not configured: ' . $channel);
        }

        return $channels[$channel];
    }

    /**
     * @param array<string,mixed> $definition
     */
    private static function buildHandler(array $definition, string $channel): HandlerInterface
    {
        $class = $definition['class'] ?? null;
        $constructor = $definition['constructor'] ?? [];
        if (!is_string($class) || $class === '') {
            throw new RuntimeException('Logger handler class must be configured for channel: ' . $channel);
        }
        if (!is_array($constructor)) {
            throw new RuntimeException('Logger handler constructor must be an array for channel: ' . $channel);
        }
        if (is_a($class, StreamHandler::class, true)) {
            $stream = $constructor['stream'] ?? null;
            if (is_string($stream) && $stream !== '' && !str_starts_with($stream, 'php://')) {
                self::ensureParentDirectory($stream);
            }
        }
        $constructor = self::normalizeConstructor($constructor);

        try {
            $handler = new $class(...$constructor);
        } catch (\Throwable $throwable) {
            throw new RuntimeException('Failed to create logger handler for channel ' . $channel . ': ' . $throwable->getMessage(), previous: $throwable);
        }
        if (!$handler instanceof HandlerInterface) {
            throw new RuntimeException('Logger handler must implement HandlerInterface for channel: ' . $channel);
        }

        return $handler;
    }

    /**
     * @param array<string,mixed> $definition
     */
    private static function buildFormatter(array $definition): ?FormatterInterface
    {
        if ($definition === []) {
            return null;
        }

        $class = $definition['class'] ?? null;
        $constructor = $definition['constructor'] ?? [];
        if (!is_string($class) || $class === '') {
            throw new RuntimeException('Logger formatter class must be a non-empty string');
        }
        if (!is_array($constructor)) {
            throw new RuntimeException('Logger formatter constructor must be an array');
        }
        $constructor = self::normalizeConstructor($constructor);

        try {
            $formatter = new $class(...$constructor);
        } catch (\Throwable $throwable) {
            throw new RuntimeException('Failed to create logger formatter: ' . $throwable->getMessage(), previous: $throwable);
        }
        if (!$formatter instanceof FormatterInterface) {
            throw new RuntimeException('Logger formatter must implement FormatterInterface');
        }

        return $formatter;
    }

    /** @param array<string,mixed> $constructor */
    private static function normalizeConstructor(array $constructor): array
    {
        foreach (['level', 'bubble', 'filePermission', 'useLocking'] as $key) {
            if (!array_key_exists($key, $constructor)) {
                continue;
            }

            if ($key === 'level' && is_string($constructor[$key]) && $constructor[$key] !== '') {
                $constructor[$key] = Level::fromName(strtoupper($constructor[$key]));
            }
        }

        return $constructor;
    }
}
