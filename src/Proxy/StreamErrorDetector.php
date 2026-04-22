<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

final class StreamErrorDetector
{
    public static function normalizeCompletedPayload(string $payload): string
    {
        $decoded = self::decodeJsonPayload($payload);
        if ($decoded === null || ($decoded['type'] ?? null) !== 'response.done') {
            return $payload;
        }

        $decoded['type'] = 'response.completed';

        return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function errorBody(string $frame): ?string
    {
        $decoded = self::decodeSsePayload($frame);
        if ($decoded === null) {
            return null;
        }

        $isErrorEvent = self::sseEvent($frame) === 'error';
        $isErrorPayload = isset($decoded['error']) || ($decoded['type'] ?? null) === 'error';
        if (!$isErrorEvent && !$isErrorPayload) {
            return null;
        }

        return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function jsonErrorBody(string $payload): ?string
    {
        $decoded = self::decodeJsonPayload($payload);
        if ($decoded === null) {
            return null;
        }

        if (!isset($decoded['error']) && ($decoded['type'] ?? null) !== 'error') {
            return null;
        }

        return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function isCompletedFrame(string $frame): bool
    {
        $decoded = self::decodeSsePayload($frame);
        if ($decoded === null) {
            return false;
        }

        return in_array($decoded['type'] ?? null, ['response.completed', 'response.done'], true);
    }

    public static function isCompletedPayload(string $payload): bool
    {
        $decoded = self::decodeJsonPayload($payload);
        if ($decoded === null) {
            return false;
        }

        return in_array($decoded['type'] ?? null, ['response.completed', 'response.done'], true);
    }

    /** @return array<string,mixed>|null */
    private static function decodeSsePayload(string $frame): ?array
    {
        $dataLines = [];
        foreach (preg_split('/\R/', $frame) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = trim(substr($line, strlen('data:')));
            }
        }

        if ($dataLines === []) {
            return null;
        }

        $data = implode("\n", $dataLines);
        if (trim($data) === '' || $data === '[DONE]') {
            return null;
        }

        return self::decodeJsonPayload($data);
    }

    /** @return array<string,mixed>|null */
    private static function decodeJsonPayload(string $payload): ?array
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function sseEvent(string $frame): string
    {
        foreach (preg_split('/\R/', $frame) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'event:')) {
                return trim(substr($line, strlen('event:')));
            }
        }

        return '';
    }
}
