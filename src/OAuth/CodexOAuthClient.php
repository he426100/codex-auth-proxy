<?php

declare(strict_types=1);

namespace CodexAuthProxy\OAuth;

interface CodexOAuthClient
{
    public function authorizationUrl(string $state, PkcePair $pkce, string $redirectUri): string;

    /** @return array{id_token:string,access_token:string,refresh_token:string} */
    public function exchangeCode(string $code, PkcePair $pkce, string $redirectUri): array;
}
