<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Proxy;

use CodexAuthProxy\Proxy\SessionKeyExtractor;
use CodexAuthProxy\Tests\TestCase;

final class SessionKeyExtractorTest extends TestCase
{
    public function testPrefersCodexTurnStateHeader(): void
    {
        $extractor = new SessionKeyExtractor();

        $key = $extractor->extract(['x-codex-turn-state' => 'turn-1'], '{}');

        self::assertSame('x-codex-turn-state:turn-1', $key->primary);
        self::assertNull($key->fallback);
    }

    public function testExtractsMetadataUserSessionIdJson(): void
    {
        $extractor = new SessionKeyExtractor();

        $key = $extractor->extract([], json_encode([
            'metadata' => [
                'user_id' => '{"session_id":"session-json-1","device_id":"device-1"}',
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('metadata.session_id:session-json-1', $key->primary);
    }

    public function testExtractsConversationIdFromResponsesRequest(): void
    {
        $extractor = new SessionKeyExtractor();

        $key = $extractor->extract([], '{"conversation_id":"conv-1","input":[]}');

        self::assertSame('conversation_id:conv-1', $key->primary);
    }

    public function testPrefersNativeConversationIdOverMetadataSession(): void
    {
        $extractor = new SessionKeyExtractor();

        $key = $extractor->extract([], json_encode([
            'conversation_id' => 'conv-native-1',
            'metadata' => [
                'user_id' => '{"session_id":"metadata-session-1"}',
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('conversation_id:conv-native-1', $key->primary);
    }

    public function testUsesPromptCacheKeyAsFallbackAfterNativeAnchors(): void
    {
        $extractor = new SessionKeyExtractor();

        $fallback = $extractor->extract([], '{"prompt_cache_key":"cache-1","input":[]}');
        $native = $extractor->extract([], '{"conversation_id":"conv-1","prompt_cache_key":"cache-1","input":[]}');

        self::assertSame('prompt_cache_key:cache-1', $fallback->primary);
        self::assertSame('conversation_id:conv-1', $native->primary);
    }

    public function testExtractPrefersStableSessionHeaderOverTurnState(): void
    {
        $extractor = new SessionKeyExtractor();

        $key = $extractor->extract([
            'session_id' => 'native-session-1',
            'x-codex-turn-state' => 'turn-1',
        ], '{}');

        self::assertSame('session_id:native-session-1', $key->primary);
    }

    public function testExtractPrefersConversationIdInBodyOverTurnStateHeader(): void
    {
        $extractor = new SessionKeyExtractor();

        $key = $extractor->extract([
            'x-codex-turn-state' => 'turn-1',
        ], '{"conversation_id":"conv-1","prompt_cache_key":"cache-1","input":[]}');

        self::assertSame('conversation_id:conv-1', $key->primary);
    }

    public function testExtractExecutionSessionPrefersStableSessionHeaderOverTurnState(): void
    {
        $extractor = new SessionKeyExtractor();

        $key = $extractor->extractExecutionSession([
            'x-codex-turn-state' => 'turn-1',
            'x-session-id' => 'session-stable-1',
        ], '{}');

        self::assertSame('x-session-id:session-stable-1', $key->primary);
    }

    public function testExtractExecutionSessionAcceptsNativeSessionIdHeader(): void
    {
        $extractor = new SessionKeyExtractor();

        $key = $extractor->extractExecutionSession([
            'session_id' => 'native-session-1',
            'x-codex-turn-state' => 'turn-1',
        ], '{}');

        self::assertSame('session_id:native-session-1', $key->primary);
    }

    public function testExtractExecutionSessionFallsBackToTurnState(): void
    {
        $extractor = new SessionKeyExtractor();

        $key = $extractor->extractExecutionSession([
            'x-codex-turn-state' => 'turn-fallback',
        ], '{}');

        self::assertSame('x-codex-turn-state:turn-fallback', $key->primary);
    }

    public function testBuildsStableMessageHashFallbackForResponsesInput(): void
    {
        $extractor = new SessionKeyExtractor();
        $body = json_encode([
            'instructions' => 'You are Codex.',
            'input' => [
                ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'fix tests']]],
                ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'I will inspect']]],
            ],
        ], JSON_THROW_ON_ERROR);

        $key = $extractor->extract([], $body);

        self::assertStringStartsWith('msg:', $key->primary);
        self::assertStringStartsWith('msg:', (string) $key->fallback);
        self::assertNotSame($key->primary, $key->fallback);
    }
}
