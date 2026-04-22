<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

final class NormalizedPayload
{
    /** @param list<string> $mutations */
    public function __construct(private readonly string $payload, private readonly array $mutations)
    {
    }

    public function payload(): string
    {
        return $this->payload;
    }

    /** @return list<string> */
    public function mutations(): array
    {
        return $this->mutations;
    }
}
