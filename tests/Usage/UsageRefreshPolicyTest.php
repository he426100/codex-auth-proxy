<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Usage;

use CodexAuthProxy\Tests\TestCase;
use CodexAuthProxy\Usage\UsageRefreshPolicy;

final class UsageRefreshPolicyTest extends TestCase
{
    public function testSuccessDelayUsesBaseIntervalWithoutJitterWhenRandomReturnsMinimum(): void
    {
        $policy = new UsageRefreshPolicy(600, static fn (int $min, int $max): int => $min);

        self::assertSame(600, $policy->delayAfterSuccessSeconds());
    }

    public function testSuccessDelayAddsBoundedPositiveJitter(): void
    {
        $policy = new UsageRefreshPolicy(600, static fn (int $min, int $max): int => $max);

        self::assertSame(660, $policy->delayAfterSuccessSeconds());
    }

    public function testFailureDelayUsesExponentialBackoffAndCapsAtBaseInterval(): void
    {
        $policy = new UsageRefreshPolicy(600, static fn (int $min, int $max): int => $min);

        self::assertSame(30, $policy->delayAfterFailureSeconds(1));
        self::assertSame(60, $policy->delayAfterFailureSeconds(2));
        self::assertSame(120, $policy->delayAfterFailureSeconds(3));
        self::assertSame(240, $policy->delayAfterFailureSeconds(4));
        self::assertSame(480, $policy->delayAfterFailureSeconds(5));
        self::assertSame(600, $policy->delayAfterFailureSeconds(6));
        self::assertSame(600, $policy->delayAfterFailureSeconds(7));
    }

    public function testFailureDelayDoesNotExceedShortBaseInterval(): void
    {
        $policy = new UsageRefreshPolicy(20, static fn (int $min, int $max): int => $min);

        self::assertSame(20, $policy->delayAfterFailureSeconds(1));
        self::assertSame(20, $policy->delayAfterFailureSeconds(4));
    }
}
