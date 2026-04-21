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
}
