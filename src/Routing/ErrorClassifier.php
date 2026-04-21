<?php

declare(strict_types=1);

namespace CodexAuthProxy\Routing;

final class ErrorClassifier
{
    public function __construct(private readonly int $defaultCooldownSeconds = 18000, private readonly int $authCooldownSeconds = 1800)
    {
    }

    /** @param array<string,string> $headers */
    public function classify(int $statusCode, string $body, array $headers = [], ?int $now = null): ErrorClassification
    {
        $now ??= time();
        $bodyLower = strtolower($body);

        if ($statusCode === 401 || $statusCode === 403) {
            return new ErrorClassification('auth', true, $now + $this->authCooldownSeconds);
        }

        if ($this->containsAuthSignal($bodyLower)) {
            return new ErrorClassification('auth', true, $now + $this->authCooldownSeconds);
        }

        if ($statusCode === 429 || $this->containsQuotaSignal($bodyLower)) {
            return new ErrorClassification('quota', true, $this->cooldownUntil($body, $headers, $now));
        }

        if ($statusCode >= 500) {
            return new ErrorClassification('transient', false, 0);
        }

        return new ErrorClassification('none', false, 0);
    }

    private function containsAuthSignal(string $bodyLower): bool
    {
        foreach (['invalid_token', 'unauthorized', 'authentication_error', 'expired token', 'token expired'] as $needle) {
            if (str_contains($bodyLower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function containsQuotaSignal(string $bodyLower): bool
    {
        foreach (['usage_limit_reached', 'quota_exceeded', 'insufficient_quota', 'rate_limit_exceeded', 'over limit', 'too many requests'] as $needle) {
            if (str_contains($bodyLower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,string> $headers */
    private function cooldownUntil(string $body, array $headers, int $now): int
    {
        foreach ($headers as $key => $value) {
            if (strtolower($key) !== 'retry-after') {
                continue;
            }

            $value = trim($value);
            if (ctype_digit($value)) {
                return $now + (int) $value;
            }

            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $absoluteHint = $this->findAbsoluteCooldownHint($decoded, $now);
            if ($absoluteHint !== null) {
                return $absoluteHint;
            }

            $relativeHint = $this->findRelativeCooldownHint($decoded);
            if ($relativeHint !== null) {
                return $now + $relativeHint;
            }
        }

        return $now + $this->defaultCooldownSeconds;
    }

    /** @param array<string,mixed> $data */
    private function findRelativeCooldownHint(array $data): ?int
    {
        foreach (['retry_after', 'retry_after_seconds', 'reset_after', 'resets_in_seconds'] as $key) {
            $value = $data[$key] ?? null;
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $nested = $this->findRelativeCooldownHint($value);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /** @param array<string,mixed> $data */
    private function findAbsoluteCooldownHint(array $data, int $now): ?int
    {
        foreach (['available_at', 'reset_at', 'resets_at'] as $key) {
            $value = $data[$key] ?? null;
            if (is_int($value) && $value > $now) {
                return $value;
            }
            if (is_string($value) && ctype_digit($value) && (int) $value > $now) {
                return (int) $value;
            }
            if (is_string($value) && trim($value) !== '') {
                $timestamp = strtotime($value);
                if ($timestamp !== false && $timestamp > $now) {
                    return $timestamp;
                }
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $nested = $this->findAbsoluteCooldownHint($value, $now);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }
}
