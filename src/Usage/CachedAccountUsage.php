<?php

declare(strict_types=1);

namespace CodexAuthProxy\Usage;

final class CachedAccountUsage
{
    public function __construct(
        public readonly string $planType,
        public readonly int $checkedAt,
        public readonly ?string $error,
        public readonly ?CachedRateLimitWindow $primary,
        public readonly ?CachedRateLimitWindow $secondary,
    ) {
    }

    public static function fromLive(AccountUsage $usage, int $checkedAt): self
    {
        return new self(
            $usage->planType,
            $checkedAt,
            null,
            $usage->primary === null ? null : new CachedRateLimitWindow(
                $usage->primary->usedPercent,
                $usage->primary->leftPercent(),
                $usage->primary->windowMinutes,
                $usage->primary->resetsAt,
            ),
            $usage->secondary === null ? null : new CachedRateLimitWindow(
                $usage->secondary->usedPercent,
                $usage->secondary->leftPercent(),
                $usage->secondary->windowMinutes,
                $usage->secondary->resetsAt,
            ),
        );
    }

    public function withError(string $error, int $checkedAt): self
    {
        return new self($this->planType, $checkedAt, $error, $this->primary, $this->secondary);
    }

    /** @return array{plan_type: string, checked_at: int, error: string|null, primary: array<string,mixed>|null, secondary: array<string,mixed>|null} */
    public function toArray(): array
    {
        return [
            'plan_type' => $this->planType,
            'checked_at' => $this->checkedAt,
            'error' => $this->error,
            'primary' => $this->primary?->toArray(),
            'secondary' => $this->secondary?->toArray(),
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $planType = self::string($data, ['plan_type', 'planType']);
        $checkedAt = self::int($data, ['checked_at', 'checkedAt']);
        $error = $data['error'] ?? null;
        if (is_string($error) && trim($error) === '') {
            $error = null;
        } elseif (!is_string($error) && $error !== null) {
            $error = null;
        }

        if ($checkedAt === null) {
            return null;
        }

        $primary = isset($data['primary']) && is_array($data['primary']) ? CachedRateLimitWindow::fromArray($data['primary']) : null;
        $secondary = isset($data['secondary']) && is_array($data['secondary']) ? CachedRateLimitWindow::fromArray($data['secondary']) : null;

        return new self($planType, $checkedAt, $error, $primary, $secondary);
    }

    /** @param array<string,mixed> $data */
    private static function string(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /** @param array<string,mixed> $data */
    private static function int(array $data, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_int($value)) {
                return $value;
            }
            if (is_float($value)) {
                return (int) $value;
            }
            if (is_string($value) && is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }
}
