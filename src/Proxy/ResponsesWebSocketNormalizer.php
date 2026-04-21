<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

final class ResponsesWebSocketNormalizer
{
    public function __construct(private readonly ResponsesPayloadNormalizer $normalizer = new ResponsesPayloadNormalizer())
    {
    }

    public function normalize(string $payload): string
    {
        return $this->normalizer->normalizeWebSocket($payload);
    }
}
