<?php

declare(strict_types=1);

namespace CodexAuthProxy\OAuth;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use RuntimeException;

final class CodexOAuthHttpClient implements CodexOAuthClient
{
    private const AUTH_URL = 'https://auth.openai.com/oauth/authorize';
    private const TOKEN_URL = 'https://auth.openai.com/oauth/token';
    private const CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';

    /** @param array<string,mixed> $proxy */
    public function __construct(private readonly ClientInterface $httpClient, private readonly array $proxy = [])
    {
    }

    public function authorizationUrl(string $state, PkcePair $pkce, string $redirectUri): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => self::CLIENT_ID,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => 'openid email profile offline_access',
            'state' => $state,
            'code_challenge' => $pkce->challenge(),
            'code_challenge_method' => 'S256',
            'prompt' => 'login',
            'id_token_add_organizations' => 'true',
            'codex_cli_simplified_flow' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{id_token:string,access_token:string,refresh_token:string} */
    public function exchangeCode(string $code, PkcePair $pkce, string $redirectUri): array
    {
        $options = [
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            RequestOptions::FORM_PARAMS => [
                'grant_type' => 'authorization_code',
                'client_id' => self::CLIENT_ID,
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $pkce->verifier(),
            ],
            RequestOptions::PROXY => $this->proxy,
        ];

        $response = $this->httpClient->request('POST', self::TOKEN_URL, $options);

        $body = (string) $response->getBody();
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Token exchange failed with status ' . $response->getStatusCode() . ': ' . $body);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Token exchange response must be JSON');
        }

        foreach (['id_token', 'access_token', 'refresh_token'] as $key) {
            if (!is_string($decoded[$key] ?? null) || trim($decoded[$key]) === '') {
                throw new InvalidArgumentException('Token exchange response missing ' . $key);
            }
        }

        return [
            'id_token' => trim($decoded['id_token']),
            'access_token' => trim($decoded['access_token']),
            'refresh_token' => trim($decoded['refresh_token']),
        ];
    }
}
