<?php

declare(strict_types=1);

namespace CodexAuthProxy\Observability;

final class RequestIdFactory
{
    /** @param array<string,mixed> $headers */
    public function fromHeaders(array $headers): string
    {
        foreach (['x-request-id', 'x-client-request-id'] as $name) {
            $value = $this->headerValue($headers, $name);
            if ($value !== null) {
                return $value;
            }
        }

        return bin2hex(random_bytes(4));
    }

    /** @param array<string,mixed> $headers */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $name) {
                continue;
            }

            $headerValue = is_array($value) ? implode(', ', $value) : (string) $value;
            $headerValue = trim($headerValue);

            return $headerValue === '' ? null : $headerValue;
        }

        return null;
    }
}
