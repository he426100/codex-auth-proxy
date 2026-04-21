<?php

declare(strict_types=1);

namespace CodexAuthProxy\Account;

use CodexAuthProxy\Support\Jwt;
use InvalidArgumentException;

final class AccountFileValidator
{
    public const SCHEMA = 'codex-auth-proxy.account.v1';
    public const PROVIDER = 'openai-chatgpt-codex';

    /** @param array<string,mixed> $data */
    public function validate(array $data, string $sourcePath = ''): CodexAccount
    {
        if (($data['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('account file schema must be ' . self::SCHEMA);
        }

        if (($data['provider'] ?? null) !== self::PROVIDER) {
            throw new InvalidArgumentException('account file provider must be ' . self::PROVIDER);
        }

        $tokens = $data['tokens'] ?? null;
        if (!is_array($tokens)) {
            throw new InvalidArgumentException('account file tokens must be an object');
        }

        $idToken = $this->requiredString($tokens, 'id_token');
        $accessToken = $this->requiredString($tokens, 'access_token');
        $refreshToken = $this->requiredString($tokens, 'refresh_token');

        $idPayload = Jwt::payload($idToken);
        Jwt::payload($accessToken);

        $auth = $idPayload['https://api.openai.com/auth'] ?? null;
        if (!is_array($auth)) {
            throw new InvalidArgumentException('id_token must include https://api.openai.com/auth claims with chatgpt_account_id');
        }

        $claimAccountId = $auth['chatgpt_account_id'] ?? null;
        if (!is_string($claimAccountId) || trim($claimAccountId) === '') {
            throw new InvalidArgumentException('id_token must include chatgpt_account_id');
        }

        $accountId = $tokens['account_id'] ?? $claimAccountId;
        if (!is_string($accountId) || trim($accountId) === '') {
            $accountId = $claimAccountId;
        }
        if (trim($accountId) !== $claimAccountId) {
            throw new InvalidArgumentException('account file account id must match id_token chatgpt_account_id');
        }

        $name = $data['name'] ?? $accountId;
        if (!is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException('account file name must be a non-empty string');
        }

        $email = $this->optionalString($data['metadata']['email'] ?? null);
        if ($email === '') {
            $email = $this->optionalString($idPayload['email'] ?? null);
        }

        return new CodexAccount(
            trim($name),
            trim($accountId),
            $email,
            $idToken,
            $accessToken,
            $refreshToken,
            ($data['enabled'] ?? true) !== false,
            $sourcePath,
        );
    }

    /** @param array<string,mixed> $data */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("account file tokens.{$key} must be a non-empty string");
        }

        return trim($value);
    }

    private function optionalString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
