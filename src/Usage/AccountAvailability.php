<?php

declare(strict_types=1);

namespace CodexAuthProxy\Usage;

use CodexAuthProxy\Account\CodexAccount;

final class AccountAvailability
{
    public function __construct(
        public readonly bool $routable,
        public readonly bool $isConfirmedAvailable,
        public readonly string $reason,
        public readonly int $cooldownUntil,
        public readonly ?CachedAccountUsage $usage,
    ) {
    }

    public static function from(CodexAccount $account, int $cooldownUntil, ?CachedAccountUsage $usage, int $now): self
    {
        if (!$account->enabled()) {
            return new self(false, false, 'disabled', $cooldownUntil, $usage);
        }

        if ($cooldownUntil > $now) {
            return new self(false, false, 'cooldown', $cooldownUntil, $usage);
        }

        if ($usage === null) {
            return new self(true, false, 'usage_unknown', $cooldownUntil, null);
        }

        $hasCompleteSnapshot = self::hasCompleteSnapshot($usage);
        $hasActiveExhaustedWindow = self::isActiveExhaustedWindow($usage->primary, $now)
            || self::isActiveExhaustedWindow($usage->secondary, $now);

        if ($usage->error !== null) {
            if (!$hasCompleteSnapshot || $hasActiveExhaustedWindow) {
                return new self(true, false, 'usage_error', $cooldownUntil, $usage);
            }
        }

        if ($hasActiveExhaustedWindow) {
            return new self(false, false, 'usage_exhausted', $cooldownUntil, $usage);
        }

        if (!$hasCompleteSnapshot) {
            return new self(true, false, 'usage_unknown', $cooldownUntil, $usage);
        }

        return new self(true, true, 'ok', $cooldownUntil, $usage);
    }

    private static function isActiveExhaustedWindow(?CachedRateLimitWindow $window, int $now): bool
    {
        if ($window === null || $window->leftPercent > 0.0) {
            return false;
        }

        return $window->resetsAt === null || $window->resetsAt > $now;
    }

    private static function hasCompleteSnapshot(CachedAccountUsage $usage): bool
    {
        return $usage->primary !== null && $usage->secondary !== null;
    }
}
