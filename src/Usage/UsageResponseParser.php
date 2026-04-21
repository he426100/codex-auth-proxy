<?php

declare(strict_types=1);

namespace CodexAuthProxy\Usage;

final class UsageResponseParser
{
    /** @param array<string,mixed> $response */
    public function parse(array $response): AccountUsage
    {
        $node = $this->rateLimitNode($response);
        $planType = $this->string($node, ['plan_type', 'planType']);
        if ($planType === '') {
            $planType = $this->string($response, ['plan_type', 'planType']);
        }

        return new AccountUsage(
            $planType,
            $this->window($node['primary'] ?? null),
            $this->window($node['secondary'] ?? null),
            $response,
        );
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function rateLimitNode(array $response): array
    {
        if (isset($response['rate_limits']) && is_array($response['rate_limits'])) {
            if (array_is_list($response['rate_limits'])) {
                return $this->selectRateLimit($response['rate_limits']);
            }

            return $this->rateLimitNode($response['rate_limits']);
        }

        if (isset($response['payload']) && is_array($response['payload'])) {
            return $this->rateLimitNode($response['payload']);
        }

        if (isset($response['rateLimitsByLimitId']) && is_array($response['rateLimitsByLimitId'])) {
            $byId = $response['rateLimitsByLimitId'];
            if (isset($byId['codex']) && is_array($byId['codex'])) {
                return $this->normalizeArray($byId['codex']);
            }
        }

        if (isset($response['rateLimits']) && is_array($response['rateLimits'])) {
            return $this->selectRateLimit($response['rateLimits']);
        }

        if (isset($response['primary']) || isset($response['secondary'])) {
            return $response;
        }

        return $response;
    }

    /**
     * @param array<mixed> $limits
     * @return array<string,mixed>
     */
    private function selectRateLimit(array $limits): array
    {
        if (!array_is_list($limits)) {
            return $this->normalizeArray($limits);
        }

        foreach ($limits as $limit) {
            if (is_array($limit) && in_array($this->string($limit, ['limit_id', 'limitId']), ['codex', 'codex_usage'], true)) {
                return $this->normalizeArray($limit);
            }
        }

        foreach ($limits as $limit) {
            if (is_array($limit) && (isset($limit['primary']) || isset($limit['secondary']))) {
                return $this->normalizeArray($limit);
            }
        }

        return [];
    }

    private function window(mixed $value): ?RateLimitWindow
    {
        if (!is_array($value)) {
            return null;
        }

        $usedPercent = $this->number($value, ['used_percent', 'usedPercent']);
        $windowMinutes = $this->int($value, ['window_minutes', 'windowDurationMins', 'window_mins']);
        $resetsAt = $this->int($value, ['resets_at', 'resetsAt', 'reset_at']);
        if ($resetsAt !== null && $resetsAt > 20_000_000_000) {
            $resetsAt = (int) floor($resetsAt / 1000);
        }

        if ($usedPercent === null && $windowMinutes === null && $resetsAt === null) {
            return null;
        }

        return new RateLimitWindow($usedPercent ?? 0.0, $windowMinutes ?? 0, $resetsAt);
    }

    /**
     * @param array<mixed> $value
     * @return array<string,mixed>
     */
    private function normalizeArray(array $value): array
    {
        /** @var array<string,mixed> $normalized */
        $normalized = $value;

        return $normalized;
    }

    /** @param array<mixed> $data */
    private function string(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /** @param array<mixed> $data */
    private function number(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }
            if (is_string($value) && is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /** @param array<mixed> $data */
    private function int(array $data, array $keys): ?int
    {
        $value = $this->number($data, $keys);

        return $value === null ? null : (int) $value;
    }
}
