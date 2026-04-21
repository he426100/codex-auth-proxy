<?php

declare(strict_types=1);

namespace CodexAuthProxy\Auth;

use CodexAuthProxy\Account\AccountFileValidator;
use CodexAuthProxy\Account\CodexAccount;
use CodexAuthProxy\Support\Jwt;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use RuntimeException;

final class TokenRefresher
{
    private const TOKEN_URL = 'https://auth.openai.com/oauth/token';
    private const CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';

    private readonly ClientInterface $httpClient;

    public function __construct(?ClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new Client(['timeout' => 30]);
    }

    public function refresh(CodexAccount $account): CodexAccount
    {
        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            RequestOptions::FORM_PARAMS => [
                'client_id' => self::CLIENT_ID,
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refreshToken(),
                'scope' => 'openid profile email',
            ],
        ]);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($status !== 200) {
            throw new RuntimeException('Token refresh failed with status ' . $status . ': ' . $body);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Token refresh response must be JSON');
        }

        foreach (['id_token', 'access_token', 'refresh_token'] as $key) {
            if (!is_string($decoded[$key] ?? null) || trim($decoded[$key]) === '') {
                throw new InvalidArgumentException('Token refresh response missing ' . $key);
            }
        }

        return $this->validatedRefreshedAccount($account, $decoded['id_token'], $decoded['access_token'], $decoded['refresh_token']);
    }

    public function refreshIfExpiring(CodexAccount $account, int $skewSeconds = 300): ?CodexAccount
    {
        if (!$this->isExpiring($account, $skewSeconds)) {
            return null;
        }

        return $this->refresh($account);
    }

    private function isExpiring(CodexAccount $account, int $skewSeconds): bool
    {
        try {
            $payload = Jwt::payload($account->accessToken());
        } catch (InvalidArgumentException) {
            $payload = Jwt::payload($account->idToken());
        }

        $exp = $payload['exp'] ?? null;
        if (!is_int($exp)) {
            return false;
        }

        return $exp <= time() + $skewSeconds;
    }

    private function validatedRefreshedAccount(CodexAccount $account, string $idToken, string $accessToken, string $refreshToken): CodexAccount
    {
        $validated = (new AccountFileValidator())->validate([
            'schema' => AccountFileValidator::SCHEMA,
            'provider' => AccountFileValidator::PROVIDER,
            'name' => $account->name(),
            'enabled' => $account->enabled(),
            'tokens' => [
                'id_token' => $idToken,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'account_id' => $account->accountId(),
            ],
            'metadata' => [
                'email' => $account->email(),
            ],
        ], $account->sourcePath());

        if ($validated->accountId() !== $account->accountId()) {
            throw new InvalidArgumentException('Token refresh returned a different account id');
        }

        return $validated;
    }
}
