<?php

declare(strict_types=1);

namespace CodexAuthProxy\Support;

final class BasePath
{
    public static function resolve(
        ?string $explicitBasePath = null,
        ?string $definedBasePath = null,
        ?string $pharPath = null,
        ?string $sourceBasePath = null,
    ): string {
        $candidate = self::nonEmpty($explicitBasePath) ?? self::nonEmpty($definedBasePath);
        if ($candidate !== null) {
            return self::normalize($candidate);
        }

        $pharPath = self::nonEmpty($pharPath);
        if ($pharPath !== null) {
            return self::normalize(dirname($pharPath));
        }

        return self::normalize($sourceBasePath ?? dirname(__DIR__, 2));
    }

    public static function toAbsolute(string $basePath, ?string $path): ?string
    {
        $path = self::nonEmpty($path);
        if ($path === null) {
            return null;
        }

        $path = str_replace('\\', '/', $path);
        if (self::isAbsolute($path)) {
            return self::normalize($path);
        }

        $path = preg_replace('#^(?:\./)+#', '', $path) ?? $path;

        return self::normalize(self::normalize($basePath) . '/' . ltrim($path, '/'));
    }

    private static function nonEmpty(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function normalize(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '' || $normalized === '/') {
            return '/';
        }

        if (preg_match('/^[A-Za-z]:\/$/', $normalized) === 1) {
            return $normalized;
        }

        return rtrim($normalized, '/');
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_starts_with($path, 'phar://')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
