<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Proxy;

use CodexAuthProxy\Proxy\ResponsesWebSocketNormalizer;
use PHPUnit\Framework\TestCase;

final class ResponsesWebSocketNormalizerTest extends TestCase
{
    public function testForcesResponseCreateTypeForCodexWebSocketPayloads(): void
    {
        $payload = json_encode([
            'type' => 'response.append',
            'previous_response_id' => 'resp_123',
            'input' => [['role' => 'user', 'content' => 'next']],
        ], JSON_THROW_ON_ERROR);

        $normalized = json_decode((new ResponsesWebSocketNormalizer())->normalize($payload), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('response.create', $normalized['type']);
        self::assertSame('resp_123', $normalized['previous_response_id']);
        self::assertSame('next', $normalized['input'][0]['content']);
    }

    public function testLeavesInvalidJsonPayloadsUntouched(): void
    {
        self::assertSame('not-json', (new ResponsesWebSocketNormalizer())->normalize('not-json'));
    }

    public function testReportsWebSocketMutations(): void
    {
        $result = (new ResponsesWebSocketNormalizer())->normalizeWithReport('{"type":"response.append"}');

        self::assertSame('{"type":"response.create"}', $result->payload());
        self::assertSame(['websocket.type.response_create'], $result->mutations());
    }
}
