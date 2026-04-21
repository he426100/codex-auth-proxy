<?php

declare(strict_types=1);

namespace CodexAuthProxy\Usage;

final class CachedRateLimitWindow
{
    public function __construct(
        public readonly float $usedPercent,
        public readonly float $leftPercent,
        public readonly int $windowMinutes,
        public readonly ?int $resetsAt,
    ) {
    }

    /** @return array{used_percent: float, left_percent: float, window_minutes: int, resets_at: int|null} */
    public function toArray(): array
    {
        return [
            'used_percent' => $this->usedPercent,
            'left_percent' => $this->leftPercent,
            'window_minutes' => $this->windowMinutes,
            'resets_at' => $this->resetsAt,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $usedPercent = self::number($data, ['used_percent', 'usedPercent']);
        $leftPercent = self::number($data, ['left_percent', 'leftPercent']);
        $windowMinutes = self::int($data, ['window_minutes', 'windowDurationMins', 'window_mins']);
        $resetsAt = self::int($data, ['resets_at', 'resetsAt', 'reset_at']);
        if ($resetsAt !== null && $resetsAt > 20_000_000_000) {
            $resetsAt = (int) floor($resetsAt / 1000);
        }

        if ($usedPercent === null && $leftPercent === null && $windowMinutes === null && $resetsAt === null) {
            return null;
        }

        if ($usedPercent === null && $leftPercent !== null) {
            $usedPercent = max(0.0, min(100.0, 100.0 - $leftPercent));
        }

        if ($leftPercent === null && $usedPercent !== null) {
            $leftPercent = max(0.0, min(100.0, 100.0 - $usedPercent));
        }

        return new self($usedPercent ?? 0.0, $leftPercent ?? 100.0, $windowMinutes ?? 0, $resetsAt);
    }

    /** @param array<string,mixed> $data */
    private static function number(array $data, array $keys): ?float
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

    /** @param array<string,mixed> $data */
    private static function int(array $data, array $keys): ?int
    {
        $value = self::number($data, $keys);

        return $value === null ? null : (int) $value;
    }
}
