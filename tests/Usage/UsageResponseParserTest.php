<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Usage;

use CodexAuthProxy\Tests\TestCase;
use CodexAuthProxy\Usage\UsageResponseParser;

final class UsageResponseParserTest extends TestCase
{
    public function testParsesCodexRateLimitSnapshotShape(): void
    {
        $usage = (new UsageResponseParser())->parse([
            'rate_limits' => [
                'limit_id' => 'codex',
                'limit_name' => null,
                'primary' => [
                    'used_percent' => 93.0,
                    'window_minutes' => 300,
                    'resets_at' => 1776756600,
                ],
                'secondary' => [
                    'used_percent' => 15.0,
                    'window_minutes' => 10080,
                    'resets_at' => 1777338600,
                ],
                'credits' => null,
                'plan_type' => 'plus',
            ],
        ]);

        self::assertSame('plus', $usage->planType);
        self::assertNotNull($usage->primary);
        self::assertSame(93.0, $usage->primary->usedPercent);
        self::assertSame(300, $usage->primary->windowMinutes);
        self::assertSame(1776756600, $usage->primary->resetsAt);
        self::assertNotNull($usage->secondary);
        self::assertSame(15.0, $usage->secondary->usedPercent);
        self::assertSame(10080, $usage->secondary->windowMinutes);
        self::assertSame(1777338600, $usage->secondary->resetsAt);
    }

    public function testParsesCodexRateLimitApiShape(): void
    {
        $usage = (new UsageResponseParser())->parse([
            'rateLimits' => [
                [
                    'limitId' => 'codex',
                    'limitName' => null,
                    'primary' => [
                        'usedPercent' => 40,
                        'windowDurationMins' => 300,
                        'resetsAt' => 1776756600,
                    ],
                    'secondary' => [
                        'usedPercent' => 10,
                        'windowDurationMins' => 10080,
                        'resetsAt' => 1777338600,
                    ],
                    'planType' => 'plus',
                ],
            ],
        ]);

        self::assertSame('plus', $usage->planType);
        self::assertSame(40.0, $usage->primary?->usedPercent);
        self::assertSame(10.0, $usage->secondary?->usedPercent);
    }

    public function testParsesRateLimitsListUnderSnakeCaseKey(): void
    {
        $usage = (new UsageResponseParser())->parse([
            'rate_limits' => [
                [
                    'limit_id' => 'premium',
                    'primary' => null,
                    'secondary' => null,
                    'plan_type' => 'plus',
                ],
                [
                    'limit_id' => 'codex',
                    'primary' => [
                        'used_percent' => 20.0,
                        'window_minutes' => 300,
                    ],
                    'secondary' => [
                        'used_percent' => 30.0,
                        'window_minutes' => 10080,
                    ],
                    'plan_type' => 'plus',
                ],
            ],
        ]);

        self::assertSame('plus', $usage->planType);
        self::assertSame(20.0, $usage->primary?->usedPercent);
        self::assertSame(30.0, $usage->secondary?->usedPercent);
    }

    public function testParsesDirectWhamUsageShape(): void
    {
        $usage = (new UsageResponseParser())->parse([
            'plan_type' => 'plus',
            'rate_limit' => [
                'primary_window' => [
                    'used_percent' => 93.0,
                    'limit_window_seconds' => 18_000,
                    'reset_at' => 1_776_756_600,
                ],
                'secondary_window' => [
                    'used_percent' => 15.0,
                    'limit_window_seconds' => 604_800,
                    'reset_at' => 1_777_338_600,
                ],
            ],
        ]);

        self::assertSame('plus', $usage->planType);
        self::assertSame(93.0, $usage->primary?->usedPercent);
        self::assertSame(300, $usage->primary?->windowMinutes);
        self::assertSame(1_776_756_600, $usage->primary?->resetsAt);
        self::assertSame(15.0, $usage->secondary?->usedPercent);
        self::assertSame(10080, $usage->secondary?->windowMinutes);
        self::assertSame(1_777_338_600, $usage->secondary?->resetsAt);
    }
}
