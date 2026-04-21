<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Usage;

use CodexAuthProxy\Account\AccountFileValidator;
use CodexAuthProxy\Tests\TestCase;
use CodexAuthProxy\Usage\AccountAvailability;
use CodexAuthProxy\Usage\CachedAccountUsage;
use CodexAuthProxy\Usage\CachedRateLimitWindow;

final class AccountAvailabilityTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<string,mixed>, 2: ?CachedAccountUsage, 3: int, 4: bool, 5: bool, 6: string, 7: int, 8: ?CachedAccountUsage}>
     */
    public static function availabilityCases(): array
    {
        $exhaustedUsage = new CachedAccountUsage(
            'plus',
            900,
            null,
            new CachedRateLimitWindow(100.0, 0.0, 300, 1100),
            null,
        );
        $expiredExhaustedUsage = new CachedAccountUsage(
            'plus',
            900,
            null,
            new CachedRateLimitWindow(100.0, 0.0, 300, 1000),
            new CachedRateLimitWindow(15.0, 85.0, 10080, 1777338600),
        );
        $usageError = new CachedAccountUsage('plus', 900, 'upstream unavailable', null, null);
        $usageErrorOverExhausted = new CachedAccountUsage(
            'plus',
            900,
            'upstream unavailable',
            new CachedRateLimitWindow(100.0, 0.0, 300, 1100),
            null,
        );
        $usageErrorOverUsableSnapshot = new CachedAccountUsage(
            'plus',
            900,
            'upstream unavailable',
            new CachedRateLimitWindow(80.0, 20.0, 300, 1100),
            new CachedRateLimitWindow(15.0, 85.0, 10080, 1777338600),
        );
        $incompleteUsage = new CachedAccountUsage(
            'plus',
            900,
            null,
            new CachedRateLimitWindow(80.0, 20.0, 300, 1100),
            null,
        );
        $okUsage = new CachedAccountUsage(
            'plus',
            900,
            null,
            new CachedRateLimitWindow(80.0, 20.0, 300, null),
            new CachedRateLimitWindow(15.0, 85.0, 10080, 1777338600),
        );

        return [
            'usage_unknown' => [
                'alpha',
                [],
                null,
                1000,
                true,
                false,
                'usage_unknown',
                0,
                null,
            ],
            'usage_exhausted' => [
                'beta',
                [],
                $exhaustedUsage,
                1000,
                false,
                false,
                'usage_exhausted',
                0,
                $exhaustedUsage,
            ],
            'usage_exhausted_expired' => [
                'beta',
                [],
                $expiredExhaustedUsage,
                1000,
                true,
                true,
                'ok',
                0,
                $expiredExhaustedUsage,
            ],
            'usage_error' => [
                'zeta',
                [],
                $usageError,
                1000,
                true,
                false,
                'usage_error',
                0,
                $usageError,
            ],
            'usage_error_overrides_exhausted_snapshot' => [
                'theta',
                [],
                $usageErrorOverExhausted,
                1000,
                true,
                false,
                'usage_error',
                0,
                $usageErrorOverExhausted,
            ],
            'usage_error_preserves_usable_snapshot' => [
                'iota',
                [],
                $usageErrorOverUsableSnapshot,
                1000,
                true,
                true,
                'ok',
                0,
                $usageErrorOverUsableSnapshot,
            ],
            'usage_unknown_for_incomplete_snapshot' => [
                'kappa',
                [],
                $incompleteUsage,
                1000,
                true,
                false,
                'usage_unknown',
                0,
                $incompleteUsage,
            ],
            'disabled' => [
                'gamma',
                ['enabled' => false],
                null,
                1000,
                false,
                false,
                'disabled',
                0,
                null,
            ],
            'cooldown' => [
                'delta',
                [],
                null,
                1000,
                false,
                false,
                'cooldown',
                1200,
                null,
            ],
            'ok' => [
                'epsilon',
                [],
                $okUsage,
                1000,
                true,
                true,
                'ok',
                0,
                $okUsage,
            ],
        ];
    }

    /**
     * @dataProvider availabilityCases
     *
     * @param array<string,mixed> $overrides
     */
    public function testFromRespectsAvailabilityRules(
        string $name,
        array $overrides,
        ?CachedAccountUsage $usage,
        int $now,
        bool $routable,
        bool $confirmed,
        string $reason,
        int $cooldownUntil,
        ?CachedAccountUsage $expectedUsage,
    ): void {
        $validator = new AccountFileValidator();
        $account = $validator->validate($this->accountFixture($name, $overrides));

        $availability = AccountAvailability::from($account, $cooldownUntil, $usage, $now);

        self::assertSame($routable, $availability->routable);
        self::assertSame($confirmed, $availability->isConfirmedAvailable);
        self::assertSame($reason, $availability->reason);
        self::assertSame($cooldownUntil, $availability->cooldownUntil);
        self::assertSame($expectedUsage, $availability->usage);
    }
}
