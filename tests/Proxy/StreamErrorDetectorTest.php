<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Proxy;

use CodexAuthProxy\Proxy\StreamErrorDetector;
use CodexAuthProxy\Tests\TestCase;

final class StreamErrorDetectorTest extends TestCase
{
    public function testExtractsOpenAIStreamErrorPayload(): void
    {
        $payload = StreamErrorDetector::errorBody("event: error\ndata: {\"error\":{\"code\":\"rate_limit_exceeded\",\"message\":\"over limit\"}}\n\n");

        self::assertSame('{"error":{"code":"rate_limit_exceeded","message":"over limit"}}', $payload);
    }

    public function testIgnoresNonErrorFrames(): void
    {
        $payload = StreamErrorDetector::errorBody("event: response.completed\ndata: {\"type\":\"response.completed\"}\n\n");

        self::assertNull($payload);
    }

    public function testDetectsCompletedFramesAndPayloads(): void
    {
        self::assertTrue(StreamErrorDetector::isCompletedFrame("data: {\"type\":\"response.completed\"}\n\n"));
        self::assertTrue(StreamErrorDetector::isCompletedPayload('{"type":"response.done"}'));
        self::assertFalse(StreamErrorDetector::isCompletedFrame("data: {\"type\":\"response.output_text.delta\"}\n\n"));
        self::assertFalse(StreamErrorDetector::isCompletedPayload('{"type":"response.output_text.delta"}'));
    }

    public function testNormalizesResponseDonePayload(): void
    {
        self::assertSame(
            '{"type":"response.completed","response":{"id":"resp_1"}}',
            StreamErrorDetector::normalizeCompletedPayload('{"type":"response.done","response":{"id":"resp_1"}}'),
        );
        self::assertSame(
            '{"type":"response.completed","response":{"id":"resp_2"}}',
            StreamErrorDetector::normalizeCompletedPayload('{"type":"response.completed","response":{"id":"resp_2"}}'),
        );
    }
}
