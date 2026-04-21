<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests;

use CodexAuthProxy\Routing\ErrorClassifier;

final class ErrorClassifierTest extends TestCase
{
    public function testClassifiesQuotaResponsesAsHardSwitchErrors(): void
    {
        $classification = (new ErrorClassifier())->classify(429, '{"error":{"code":"quota_exceeded","message":"Over limit"}}', [], 1000);

        self::assertSame('quota', $classification->type());
        self::assertTrue($classification->hardSwitch());
        self::assertGreaterThan(1000, $classification->cooldownUntil());
    }

    public function testUsesRetryAfterHeadersAsCooldownHints(): void
    {
        $classification = (new ErrorClassifier())->classify(429, '{"error":{"code":"rate_limit_exceeded"}}', ['retry-after' => '120'], 1000);

        self::assertSame('quota', $classification->type());
        self::assertSame(1120, $classification->cooldownUntil());
    }

    public function testClassifiesAuthFailuresAsHardSwitchErrors(): void
    {
        $classification = (new ErrorClassifier())->classify(401, '{"error":{"code":"invalid_token"}}', [], 1000);

        self::assertSame('auth', $classification->type());
        self::assertTrue($classification->hardSwitch());
        self::assertSame(2800, $classification->cooldownUntil());
    }

    public function testUsesCodexUsageLimitResetEpochAsCooldownHint(): void
    {
        $classification = (new ErrorClassifier())->classify(429, '{"error":{"type":"usage_limit_reached","resets_at":1600}}', [], 1000);

        self::assertSame('quota', $classification->type());
        self::assertSame(1600, $classification->cooldownUntil());
    }

    public function testUsesCodexUsageLimitResetSecondsAsCooldownHint(): void
    {
        $classification = (new ErrorClassifier())->classify(429, '{"error":{"type":"usage_limit_reached","resets_in_seconds":300}}', [], 1000);

        self::assertSame('quota', $classification->type());
        self::assertSame(1300, $classification->cooldownUntil());
    }

    public function testKeepsServerErrorsAsTransientErrors(): void
    {
        $classification = (new ErrorClassifier())->classify(502, 'bad gateway', [], 1000);

        self::assertSame('transient', $classification->type());
        self::assertFalse($classification->hardSwitch());
    }
}
