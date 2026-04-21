<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Proxy;

use CodexAuthProxy\Proxy\WebSocketRetryTracker;
use PHPUnit\Framework\TestCase;

final class WebSocketRetryTrackerTest extends TestCase
{
    public function testAllowsSingleRetryBeforeAnyDataWasForwarded(): void
    {
        $tracker = new WebSocketRetryTracker();

        self::assertTrue($tracker->claimRetry(10, '{"type":"response.create"}', false));
        self::assertFalse($tracker->claimRetry(10, '{"type":"response.create"}', false));
    }

    public function testRejectsRetryAfterDataWasForwarded(): void
    {
        $tracker = new WebSocketRetryTracker();

        self::assertFalse($tracker->claimRetry(10, '{"type":"response.create"}', true));
    }
}
