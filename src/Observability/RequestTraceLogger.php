<?php

declare(strict_types=1);

namespace CodexAuthProxy\Observability;

use Psr\Log\LoggerInterface;

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

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /** @param array<string,mixed> $event */
    public function error(array $event): void
    {
        $this->logger->warning('request_trace_error', $this->payload($event));
    }

    /** @param array<string,mixed> $event */
    public function event(array $event): void
    {
        $this->logger->info('request_trace', $this->payload($event));
    }

    /** @param array<string,mixed> $event */
    private function payload(array $event): array
    {
        $payload = [
            'schema' => self::SCHEMA,
        ];

        foreach (['request_id', 'transport', 'phase', 'session', 'account', 'status', 'classification'] as $key) {
            if (array_key_exists($key, $event)) {
                $payload[$key] = is_string($event[$key]) ? $this->redact($event[$key]) : $event[$key];
            }
        }
        if (isset($event['attempts']) && is_int($event['attempts']) && $event['attempts'] > 0) {
            $payload['attempts'] = $event['attempts'];
        }
        if (isset($event['timings_ms']) && is_array($event['timings_ms'])) {
            $timings = [];
            foreach ($event['timings_ms'] as $key => $value) {
                if (!is_string($key) || $key === '' || (!is_int($value) && !is_float($value))) {
                    continue;
                }
                $timings[$key] = round((float) $value, 3);
            }
            if ($timings !== []) {
                $payload['timings_ms'] = $timings;
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
}
