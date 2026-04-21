<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Proxy;

use CodexAuthProxy\Proxy\SseFramer;
use CodexAuthProxy\Tests\TestCase;

final class SseFramerTest extends TestCase
{
    public function testFramesSplitSseChunks(): void
    {
        $framer = new SseFramer();

        self::assertSame([], $framer->write("event: response.output_text.delta\n"));
        self::assertSame(["event: response.output_text.delta\ndata: {\"delta\":\"hi\"}\n\n"], $framer->write("data: {\"delta\":\"hi\"}\n\n"));
    }

    public function testEmitsValidDataFrameWithoutDelimiterImmediately(): void
    {
        $framer = new SseFramer();

        self::assertSame(["data: {\"type\":\"response.completed\"}\n\n"], $framer->write("data: {\"type\":\"response.completed\"}"));
        self::assertSame([], $framer->flush());
    }
}
