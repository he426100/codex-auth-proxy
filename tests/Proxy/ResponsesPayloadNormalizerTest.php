<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Proxy;

use CodexAuthProxy\Proxy\ResponsesPayloadNormalizer;
use CodexAuthProxy\Tests\TestCase;
use stdClass;

final class ResponsesPayloadNormalizerTest extends TestCase
{
    public function testHttpNormalizesEmptyFunctionParametersToObject(): void
    {
        $payload = '{"tools":[{"type":"function","name":"list_pages","parameters":[]}]}';

        $normalized = (new ResponsesPayloadNormalizer())->normalizeHttp($payload);
        $decoded = json_decode($normalized, false, flags: JSON_THROW_ON_ERROR);

        self::assertInstanceOf(stdClass::class, $decoded->tools[0]->parameters);
    }

    public function testHttpNormalizesNestedToolParameters(): void
    {
        $payload = '{"tools":[{"type":"mcp","tools":[{"name":"list_pages","parameters":[]}]}]}';

        $normalized = (new ResponsesPayloadNormalizer())->normalizeHttp($payload);
        $decoded = json_decode($normalized, false, flags: JSON_THROW_ON_ERROR);

        self::assertInstanceOf(stdClass::class, $decoded->tools[0]->tools[0]->parameters);
    }

    public function testWebSocketSetsResponseCreateAndPreservesEmptyObjects(): void
    {
        $payload = '{"type":"response.start","tools":[{"name":"noop","parameters":{}}]}';

        $normalized = (new ResponsesPayloadNormalizer())->normalizeWebSocket($payload);
        $decoded = json_decode($normalized, false, flags: JSON_THROW_ON_ERROR);

        self::assertSame('response.create', $decoded->type);
        self::assertInstanceOf(stdClass::class, $decoded->tools[0]->parameters);
    }

    public function testInvalidJsonIsReturnedUnchanged(): void
    {
        self::assertSame('not-json', (new ResponsesPayloadNormalizer())->normalizeHttp('not-json'));
    }

    public function testHttpAppliesSafeCodexCompatibilityNormalizations(): void
    {
        $payload = json_encode([
            'instructions' => null,
            'input' => [
                ['type' => 'message', 'role' => 'system', 'content' => []],
            ],
            'tools' => [
                ['type' => 'web_search_preview'],
            ],
            'tool_choice' => [
                'type' => 'web_search_preview_2025_03_11',
                'tools' => [
                    ['type' => 'web_search_preview'],
                ],
            ],
            'context_management' => ['compaction' => ['type' => 'auto']],
            'max_output_tokens' => 1024,
            'max_completion_tokens' => 1024,
            'temperature' => 0.2,
            'top_p' => 0.9,
            'truncation' => 'auto',
            'user' => 'user-1',
            'service_tier' => 'default',
        ], JSON_THROW_ON_ERROR);

        $normalized = (new ResponsesPayloadNormalizer())->normalizeHttp($payload);
        $decoded = json_decode($normalized, false, flags: JSON_THROW_ON_ERROR);

        self::assertSame('', $decoded->instructions);
        self::assertSame('developer', $decoded->input[0]->role);
        self::assertSame('web_search', $decoded->tools[0]->type);
        self::assertSame('web_search', $decoded->tool_choice->type);
        self::assertSame('web_search', $decoded->tool_choice->tools[0]->type);
        foreach (['context_management', 'max_output_tokens', 'max_completion_tokens', 'temperature', 'top_p', 'truncation', 'user', 'service_tier'] as $field) {
            self::assertObjectNotHasProperty($field, $decoded);
        }
    }

    public function testHttpWrapsStringInputAndPreservesNativeSessionFields(): void
    {
        $payload = '{"input":"hello","previous_response_id":"resp_1","stream":false,"service_tier":"priority"}';

        $normalized = (new ResponsesPayloadNormalizer())->normalizeHttp($payload);
        $decoded = json_decode($normalized, false, flags: JSON_THROW_ON_ERROR);

        self::assertSame('message', $decoded->input[0]->type);
        self::assertSame('user', $decoded->input[0]->role);
        self::assertSame('input_text', $decoded->input[0]->content[0]->type);
        self::assertSame('hello', $decoded->input[0]->content[0]->text);
        self::assertSame('resp_1', $decoded->previous_response_id);
        self::assertFalse($decoded->stream);
        self::assertSame('priority', $decoded->service_tier);
    }
}
