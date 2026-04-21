<?php

declare(strict_types=1);

namespace CodexAuthProxy\OAuth;

interface CallbackServer
{
    public function waitForCode(string $host, int $port, string $path, string $expectedState, int $timeoutSeconds): AuthorizationCode;
}
