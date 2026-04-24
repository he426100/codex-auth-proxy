<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Codex;

use CodexAuthProxy\Tests\TestCase;

final class CodexProtocolTest extends TestCase
{
    public function testExposesDefaultResponsesWebsocketBetaHeader(): void
    {
        self::assertSame(
            'responses_websockets=2026-02-06',
            \CodexAuthProxy\Codex\CodexProtocol::responsesWebsocketBetaHeader(),
        );
    }

    public function testBuildsWhamUsageEndpointForChatGptBackendBase(): void
    {
        self::assertSame(
            'https://chatgpt.com/backend-api/wham/usage',
            \CodexAuthProxy\Codex\CodexProtocol::usageEndpoint('https://chatgpt.com/backend-api'),
        );
    }

    public function testBuildsCodexUsageEndpointForCustomBaseUrl(): void
    {
        self::assertSame(
            'https://proxy.example.test/api/codex/usage',
            \CodexAuthProxy\Codex\CodexProtocol::usageEndpoint('https://proxy.example.test'),
        );
    }
}
