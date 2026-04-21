<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

use stdClass;

final class ResponsesPayloadNormalizer
{
    public function normalizeHttp(string $payload): string
    {
        return $this->normalize($payload, false);
    }

    public function normalizeWebSocket(string $payload): string
    {
        return $this->normalize($payload, true);
    }

    private function normalize(string $payload, bool $websocket): string
    {
        try {
            $decoded = json_decode($payload, false, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $payload;
        }

        if (!$decoded instanceof stdClass && !is_array($decoded)) {
            return $payload;
        }

        if ($websocket && $decoded instanceof stdClass) {
            $decoded->type = 'response.create';
        }

        $this->normalizeParameters($decoded);

        return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function normalizeParameters(mixed &$value): void
    {
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                if ($key === 'parameters' && is_array($item) && $item === []) {
                    $value->{$key} = new stdClass();
                    continue;
                }

                $this->normalizeParameters($item);
                $value->{$key} = $item;
            }

            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            $this->normalizeParameters($item);
            $value[$key] = $item;
        }
    }
}
