<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

final class ResponsesWebSocketNormalizer
{
    public function normalize(string $payload): string
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return $payload;
        }

        $decoded['type'] = 'response.create';

        return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
