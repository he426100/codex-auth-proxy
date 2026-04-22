<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Proxy;

use CodexAuthProxy\Proxy\WebSocketRetryTracker;
use PHPUnit\Framework\TestCase;

final class WebSocketRetryTrackerTest extends TestCase
{
    public function testAllowsRetriesAcrossDistinctAccountsBeforeAnyDataWasForwarded(): void
    {
        $tracker = new WebSocketRetryTracker();
        $tracker->beginPayload(10, '{"type":"response.create"}');

        self::assertTrue($tracker->claimRetry(10, '{"type":"response.create"}', 'acct-alpha', false));
        self::assertTrue($tracker->claimRetry(10, '{"type":"response.create"}', 'acct-beta', false));
        self::assertFalse($tracker->claimRetry(10, '{"type":"response.create"}', 'acct-alpha', false));
    }

    public function testResetsAttemptsWhenSamePayloadStartsANewRequestCycle(): void
    {
        $tracker = new WebSocketRetryTracker();

        $tracker->beginPayload(10, '{"type":"response.create"}');
        self::assertTrue($tracker->claimRetry(10, '{"type":"response.create"}', 'acct-alpha', false));

        $tracker->beginPayload(10, '{"type":"response.create"}');
        self::assertTrue($tracker->claimRetry(10, '{"type":"response.create"}', 'acct-alpha', false));
    }

    public function testRejectsRetryAfterDataWasForwarded(): void
    {
        $tracker = new WebSocketRetryTracker();
        $tracker->beginPayload(10, '{"type":"response.create"}');

        self::assertFalse($tracker->claimRetry(10, '{"type":"response.create"}', 'acct-alpha', true));
    }
}
