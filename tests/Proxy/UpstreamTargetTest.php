<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Proxy;

use CodexAuthProxy\Proxy\UpstreamTarget;
use PHPUnit\Framework\TestCase;

final class UpstreamTargetTest extends TestCase
{
    public function testMapsOpenAiResponsesPathToChatGptCodexBackend(): void
    {
        $target = new UpstreamTarget('https://chatgpt.com/backend-api/codex');

        self::assertSame(['chatgpt.com', 443, true], $target->endpoint());
        self::assertSame('/backend-api/codex/responses?stream=true', $target->pathFor('/v1/responses?stream=true'));
    }

    public function testKeepsCustomBasePathAndStripsOnlyLeadingV1Segment(): void
    {
        $target = new UpstreamTarget('http://127.0.0.1:8080/proxy');

        self::assertSame(['127.0.0.1', 8080, false], $target->endpoint());
        self::assertSame('/proxy/models', $target->pathFor('/v1/models'));
        self::assertSame('/proxy/custom/v1/models', $target->pathFor('/custom/v1/models'));
    }
}
