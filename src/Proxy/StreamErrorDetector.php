<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

final class StreamErrorDetector
{
    public static function errorBody(string $frame): ?string
    {
        $event = '';
        $dataLines = [];
        foreach (preg_split('/\R/', $frame) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, strlen('event:')));
                continue;
            }
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

        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return null;
        }

        $isErrorEvent = $event === 'error';
        $isErrorPayload = isset($decoded['error']) || ($decoded['type'] ?? null) === 'error';
        if (!$isErrorEvent && !$isErrorPayload) {
            return null;
        }

        return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function jsonErrorBody(string $payload): ?string
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }

        if (!isset($decoded['error']) && ($decoded['type'] ?? null) !== 'error') {
            return null;
        }

        return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
