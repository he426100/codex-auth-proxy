<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Proxy;

use CodexAuthProxy\Proxy\UpstreamResponseBodyBuffer;
use CodexAuthProxy\Tests\TestCase;

final class UpstreamResponseBodyBufferTest extends TestCase
{
    public function testBuffersNonSseChunksUntilFinalStatusIsAvailable(): void
    {
        $buffer = new UpstreamResponseBodyBuffer(forceBuffer: false);

        $chunks = $buffer->write(0, ['content-type' => 'application/json'], '{"object":"response.compaction"}');

        self::assertSame([], $chunks);
        self::assertFalse($buffer->streamed());
        self::assertSame('{"object":"response.compaction"}', $buffer->body());
    }

    public function testHeaderlessChunksCanStillBecomeSseWhenFinalHeadersArrive(): void
    {
        $buffer = new UpstreamResponseBodyBuffer(forceBuffer: false);

        $chunks = $buffer->write(0, [], "data: {\"type\":\"response.output_text.delta\"}\n\n");
        $frames = $buffer->flush(['content-type' => 'text/event-stream']);

        self::assertSame([], $chunks);
        self::assertSame(["data: {\"type\":\"response.output_text.delta\"}\n\n"], $frames);
        self::assertSame('', $buffer->body());
        self::assertTrue($buffer->streamed());
    }

    public function testFirstSseErrorBuffersWholeResponseWithoutStreamingLaterFrames(): void
    {
        $buffer = new UpstreamResponseBodyBuffer(forceBuffer: false);

        $frames = $buffer->write(
            200,
            ['content-type' => 'text/event-stream'],
            "event: error\ndata: {\"error\":{\"code\":\"rate_limit_exceeded\"}}\n\ndata: {\"type\":\"response.completed\"}\n\n",
        );

        self::assertSame([], $frames);
        self::assertFalse($buffer->streamed());
        self::assertSame('{"error":{"code":"rate_limit_exceeded"}}', $buffer->body());
    }

    public function testStreamsFragmentedSseFramesInOrderUntilCompleted(): void
    {
        $buffer = new UpstreamResponseBodyBuffer(forceBuffer: false);

        self::assertSame([], $buffer->write(
            200,
            ['content-type' => 'text/event-stream'],
            "data: {\"type\":\"response.output_text.delta\",\"delta\":\"Hel",
        ));
        self::assertSame([
            "data: {\"type\":\"response.output_text.delta\",\"delta\":\"Hello\"}\n\n",
        ], $buffer->write(
            200,
            ['content-type' => 'text/event-stream'],
            "lo\"}\n\n",
        ));
        self::assertSame([
            "data: {\"type\":\"response.output_text.delta\",\"delta\":\" world\"}\n\n",
        ], $buffer->write(
            200,
            ['content-type' => 'text/event-stream'],
            "data: {\"type\":\"response.output_text.delta\",\"delta\":\" world\"}\n\n",
        ));
        self::assertSame([
            "data: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_stream_1\"}}\n\n",
        ], $buffer->write(
            200,
            ['content-type' => 'text/event-stream'],
            "data: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_stream_1\"}}",
        ));
        self::assertSame([], $buffer->flush(['content-type' => 'text/event-stream']));
        self::assertTrue($buffer->streamed());
        self::assertSame('', $buffer->body());
    }
}
