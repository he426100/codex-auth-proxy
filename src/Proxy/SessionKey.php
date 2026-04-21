<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

final class SessionKey
{
    public function __construct(public readonly string $primary, public readonly ?string $fallback = null)
    {
    }
}
