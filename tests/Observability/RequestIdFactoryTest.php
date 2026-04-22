<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Observability;

use CodexAuthProxy\Observability\RequestIdFactory;
use CodexAuthProxy\Tests\TestCase;

final class RequestIdFactoryTest extends TestCase
{
    public function testUsesExistingRequestIdHeaderWhenPresent(): void
    {
        $factory = new RequestIdFactory();

        self::assertSame('client-request-1', $factory->fromHeaders(['x-request-id' => 'client-request-1']));
        self::assertSame('codex-request-1', $factory->fromHeaders(['X-Client-Request-Id' => 'codex-request-1']));
    }

    public function testGeneratesHexRequestIdWhenHeaderMissing(): void
    {
        $factory = new RequestIdFactory();

        self::assertMatchesRegularExpression('/^[a-f0-9]{8}$/', $factory->fromHeaders([]));
    }
}
