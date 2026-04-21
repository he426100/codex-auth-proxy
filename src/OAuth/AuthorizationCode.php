<?php

declare(strict_types=1);

namespace CodexAuthProxy\OAuth;

final class AuthorizationCode
{
    public function __construct(public readonly string $code, public readonly string $state)
    {
    }
}
