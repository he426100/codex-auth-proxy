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
        return $this->classifyHttpResponse($statusCode, $body, $headers, $now);
    }

    /** @param array<string,string> $headers */
    public function classifyHttpResponse(int $statusCode, string $body, array $headers = [], ?int $now = null): ErrorClassification
    {
        $now ??= time();

        if ($statusCode === 401 || $statusCode === 403) {
            return new ErrorClassification('auth', true, $now + $this->authCooldownSeconds);
        }

        if ($statusCode === 429) {
            return new ErrorClassification('quota', true, $this->cooldownUntil($body, $headers, $now));
        }

        if ($statusCode >= 500) {
            return new ErrorClassification('transient', false, 0);
        }

        if (!$this->hasExplicitErrorPayload($body)) {
            return new ErrorClassification('none', false, 0);
        }

        return $this->classifyExplicitErrorPayload($body, $headers, $now);
    }

    public function classifyErrorPayload(string $body, ?int $now = null): ErrorClassification
    {
        $now ??= time();

        return $this->classifyExplicitErrorPayload($body, [], $now);
    }

    /** @param array<string,string> $headers */
    private function classifyExplicitErrorPayload(string $body, array $headers, int $now): ErrorClassification
    {
        $fields = $this->errorFields($body);
        if ($fields === null) {
            return new ErrorClassification('none', false, 0);
        }

        if ($this->containsAuthSignal($fields)) {
            return new ErrorClassification('auth', true, $now + $this->authCooldownSeconds);
        }

        if ($this->containsQuotaSignal($fields)) {
            return new ErrorClassification('quota', true, $this->cooldownUntil($body, $headers, $now));
        }

        if ($this->containsLineageSignal($fields)) {
            return new ErrorClassification('lineage', false, 0);
        }

        if ($this->containsTransientSignal($fields)) {
            return new ErrorClassification('transient', false, 0);
        }

        return new ErrorClassification('none', false, 0);
    }

    /** @param list<string> $fields */
    private function containsAuthSignal(array $fields): bool
    {
        return $this->containsSignal($fields, [
            'invalid_token',
            'token_invalidated',
            'unauthorized',
            'authentication_error',
            'expired token',
            'token expired',
            'authentication token has been invalidated',
        ]);
    }

    /** @param list<string> $fields */
    private function containsQuotaSignal(array $fields): bool
    {
        return $this->containsSignal($fields, [
            'usage_limit_reached',
            'quota_exceeded',
            'insufficient_quota',
            'rate_limit_exceeded',
            'over limit',
            'too many requests',
            'usagelimitexceeded',
        ]);
    }

    /** @param list<string> $fields */
    private function containsTransientSignal(array $fields): bool
    {
        return $this->containsSignal($fields, [
            'server_error',
            'internal_server_error',
            'httpconnectionfailed',
            'responsestreamconnectionfailed',
            'responsestreamdisconnected',
        ]);
    }

    /** @param list<string> $fields */
    private function containsLineageSignal(array $fields): bool
    {
        return $this->containsSignal($fields, [
            'previous_response_not_found',
        ]);
    }

    /** @param list<string> $fields
     *  @param list<string> $needles
     */
    private function containsSignal(array $fields, array $needles): bool
    {
        foreach ($fields as $field) {
            foreach ($needles as $needle) {
                if (str_contains($field, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasExplicitErrorPayload(string $body): bool
    {
        return $this->errorFields($body) !== null;
    }

    /** @return list<string>|null */
    private function errorFields(string $body): ?array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }

        $error = null;
        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $error = $decoded['error'];
        } elseif (($decoded['type'] ?? null) === 'error') {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : $decoded;
        }

        if (!is_array($error)) {
            return null;
        }

        $fields = [];
        foreach ([$decoded, $error] as $source) {
            $this->appendErrorField($fields, $source['code'] ?? null);
            $this->appendErrorField($fields, $source['type'] ?? null);
            $this->appendErrorField($fields, $source['message'] ?? null);
            $this->appendCodexErrorInfo($fields, $source['codexErrorInfo'] ?? null);
        }

        return array_values(array_unique($fields));
    }

    /** @param list<string> $fields */
    private function appendErrorField(array &$fields, mixed $value): void
    {
        if (!is_string($value) || trim($value) === '') {
            return;
        }

        $fields[] = strtolower(trim($value));
    }

    /** @param list<string> $fields */
    private function appendCodexErrorInfo(array &$fields, mixed $value): void
    {
        if (is_string($value)) {
            $this->appendErrorField($fields, $value);
            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach (['type', 'kind', 'name'] as $key) {
            $this->appendErrorField($fields, $value[$key] ?? null);
        }
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
