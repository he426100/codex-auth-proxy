<?php

declare(strict_types=1);

namespace CodexAuthProxy\Observability;

use RuntimeException;

final class RequestTraceLogger
{
    private const SCHEMA = 'codex-auth-proxy.trace.v1';
    private const MAX_MESSAGE_LENGTH = 4000;
    private const CONTENT_KEYS = [
        'input' => true,
        'output' => true,
        'content' => true,
        'text' => true,
        'messages' => true,
        'instructions' => true,
    ];

    public function __construct(private readonly string $traceDir)
    {
    }

    /** @param array<string,mixed> $event */
    public function error(array $event): void
    {
        $this->event($event);
    }

    /** @param array<string,mixed> $event */
    public function event(array $event): void
    {
        $payload = $this->payload($event);
        $this->ensureTraceDir();

        $requestId = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) ($payload['request_id'] ?? 'unknown'));
        $path = rtrim($this->traceDir, '/') . '/' . $this->timestampForFilename() . '-' . $requestId . '-' . bin2hex(random_bytes(2)) . '.json';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException('Failed to write request trace: ' . $path);
        }
    }

    /** @param array<string,mixed> $event */
    private function payload(array $event): array
    {
        $payload = [
            'schema' => self::SCHEMA,
            'timestamp' => gmdate(DATE_ATOM),
        ];

        foreach (['request_id', 'transport', 'phase', 'session', 'account', 'status', 'classification'] as $key) {
            if (array_key_exists($key, $event)) {
                $payload[$key] = is_string($event[$key]) ? $this->redact($event[$key]) : $event[$key];
            }
        }

        if (isset($event['message'])) {
            $payload['message'] = $this->truncate($this->sanitizeMessage((string) $event['message']));
        }
        if (isset($event['mutations']) && is_array($event['mutations'])) {
            $payload['mutations'] = array_values(array_filter(
                $event['mutations'],
                static fn (mixed $mutation): bool => is_string($mutation) && $mutation !== '',
            ));
        }

        return $payload;
    }

    private function sanitizeMessage(string $value): string
    {
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return $this->redact($value);
        }

        /** @var array<mixed> $decoded */
        $encoded = json_encode($this->redactJsonContent($decoded), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->redact($encoded);
    }

    /** @param array<mixed> $value */
    private function redactJsonContent(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && isset(self::CONTENT_KEYS[strtolower($key)])) {
                $value[$key] = '[redacted]';
                continue;
            }

            if (is_array($item)) {
                /** @var array<mixed> $item */
                $value[$key] = $this->redactJsonContent($item);
                continue;
            }

            if (is_string($item)) {
                $value[$key] = $this->redact($item);
            }
        }

        return $value;
    }

    private function redact(string $value): string
    {
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\-\/]+=*/i', 'Bearer [redacted]', $value) ?? $value;
        $value = preg_replace('/(refresh_token|access_token|id_token)=([^&\s]+)/i', '$1=[redacted]', $value) ?? $value;
        $value = preg_replace('/("(?:refresh_token|access_token|id_token)"\s*:\s*")([^"]+)(")/i', '$1[redacted]$3', $value) ?? $value;

        return $value;
    }

    private function truncate(string $value): string
    {
        if (strlen($value) <= self::MAX_MESSAGE_LENGTH) {
            return $value;
        }

        return substr($value, 0, self::MAX_MESSAGE_LENGTH) . '... [truncated]';
    }

    private function ensureTraceDir(): void
    {
        if (is_dir($this->traceDir)) {
            return;
        }
        if (!mkdir($this->traceDir, 0700, true) && !is_dir($this->traceDir)) {
            throw new RuntimeException('Failed to create trace dir: ' . $this->traceDir);
        }
    }

    private function timestampForFilename(): string
    {
        $microtime = microtime(true);
        $seconds = (int) $microtime;
        $micros = (int) (($microtime - $seconds) * 1_000_000);

        return gmdate('Ymd-His', $seconds) . sprintf('-%06d', $micros);
    }
}
