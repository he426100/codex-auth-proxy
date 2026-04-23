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

    public function testHttpReturnsAlreadyCompatiblePayloadUnchanged(): void
    {
        $payload = <<<'JSON'
{
  "input": [
    {
      "type": "message",
      "role": "user",
      "content": [
        {
          "type": "input_text",
          "text": "hello"
        }
      ]
    }
  ],
  "metadata": {
    "unknown": true
  },
  "stream": false
}
JSON;

        self::assertSame($payload, (new ResponsesPayloadNormalizer())->normalizeHttp($payload));
    }

    public function testWebSocketReturnsAlreadyCompatiblePayloadUnchanged(): void
    {
        $payload = <<<'JSON'
{
  "type": "response.create",
  "tools": [
    {
      "name": "noop",
      "parameters": {}
    }
  ],
  "metadata": {
    "unknown": true
  }
}
JSON;

        self::assertSame($payload, (new ResponsesPayloadNormalizer())->normalizeWebSocket($payload));
    }

    public function testReportsNoMutationsForAlreadyCompatiblePayload(): void
    {
        $payload = '{"input":[{"type":"message","role":"user","content":[]}],"stream":false}';

        $result = (new ResponsesPayloadNormalizer())->normalizeHttpWithReport($payload);

        self::assertSame($payload, $result->payload());
        self::assertSame([], $result->mutations());
    }

    public function testReportsHttpCompatibilityMutations(): void
    {
        $payload = '{"instructions":null,"input":"hello","tools":[{"type":"function","parameters":[]}]}';

        $result = (new ResponsesPayloadNormalizer())->normalizeHttpWithReport($payload);

        self::assertSame([
            'http.instructions.null_to_empty',
            'http.input.string_to_message',
            'parameters.empty_array_to_object',
        ], $result->mutations());
    }

    public function testReportsWebSocketCompatibilityMutations(): void
    {
        $payload = '{"type":"response.append","tools":[{"type":"function","parameters":[]}]}';

        $result = (new ResponsesPayloadNormalizer())->normalizeWebSocketWithReport($payload);

        self::assertSame([
            'websocket.type.response_create',
            'parameters.empty_array_to_object',
        ], $result->mutations());
    }

    public function testNormalizesWebSocketPayloadForHttpFallback(): void
    {
        $payload = '{"type":"response.create","input":"hello","tools":[{"type":"function","parameters":[]}]}';

        $result = (new ResponsesPayloadNormalizer())->normalizeWebSocketHttpFallbackWithReport($payload);
        $decoded = json_decode($result->payload(), false, flags: JSON_THROW_ON_ERROR);

        self::assertObjectNotHasProperty('type', $decoded);
        self::assertTrue($decoded->stream);
        self::assertSame('message', $decoded->input[0]->type);
        self::assertInstanceOf(stdClass::class, $decoded->tools[0]->parameters);
        self::assertSame([
            'websocket_http_fallback.type_removed',
            'websocket_http_fallback.stream_true',
            'http.input.string_to_message',
            'parameters.empty_array_to_object',
        ], $result->mutations());
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
        self::assertEquals((object) ['compaction' => (object) ['type' => 'auto']], $decoded->context_management);
        self::assertSame(1024, $decoded->max_output_tokens);
        self::assertSame(1024, $decoded->max_completion_tokens);
        self::assertSame(0.2, $decoded->temperature);
        self::assertSame(0.9, $decoded->top_p);
        self::assertSame('auto', $decoded->truncation);
        self::assertSame('user-1', $decoded->user);
        self::assertSame('default', $decoded->service_tier);
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
