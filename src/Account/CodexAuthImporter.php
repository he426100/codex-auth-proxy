<?php

declare(strict_types=1);

namespace CodexAuthProxy\Account;

use CodexAuthProxy\Support\Jwt;
use InvalidArgumentException;

final class CodexAuthImporter
{
    /** @param array<string,mixed> $source */
    public function import(array $source, ?string $name = null): array
    {
        if (($source['auth_mode'] ?? null) !== 'chatgpt') {
            throw new InvalidArgumentException('Only chatgpt auth.json files can be imported');
        }

        $tokens = $source['tokens'] ?? null;
        if (!is_array($tokens)) {
            throw new InvalidArgumentException('auth.json tokens must be an object');
        }

        foreach (['id_token', 'access_token', 'refresh_token'] as $key) {
            if (!is_string($tokens[$key] ?? null) || trim($tokens[$key]) === '') {
                throw new InvalidArgumentException("auth.json tokens.{$key} must be a non-empty string");
            }
        }

        $claims = Jwt::payload($tokens['id_token']);
        $auth = $claims['https://api.openai.com/auth'] ?? [];
        $accountId = is_array($auth) && is_string($auth['chatgpt_account_id'] ?? null)
            ? $auth['chatgpt_account_id']
            : ($tokens['account_id'] ?? '');
        $email = is_string($claims['email'] ?? null) ? $claims['email'] : '';
        $accountName = $this->accountName($name, $email, is_string($accountId) ? $accountId : '');

        $imported = [
            'schema' => AccountFileValidator::SCHEMA,
            'provider' => AccountFileValidator::PROVIDER,
            'name' => $accountName,
            'enabled' => true,
            'tokens' => [
                'id_token' => trim($tokens['id_token']),
                'access_token' => trim($tokens['access_token']),
                'refresh_token' => trim($tokens['refresh_token']),
                'account_id' => is_string($accountId) ? trim($accountId) : '',
            ],
            'metadata' => [
                'email' => $email,
                'plan_type' => is_array($auth) && is_string($auth['chatgpt_plan_type'] ?? null) ? $auth['chatgpt_plan_type'] : '',
            ],
        ];

        (new AccountFileValidator())->validate($imported);

        return $imported;
    }

    private function accountName(?string $requestedName, string $email, string $accountId): string
    {
        if (is_string($requestedName) && trim($requestedName) !== '') {
            return trim($requestedName);
        }

        $base = trim($email) !== '' ? $email : $accountId;
        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $base);
        $safe = trim((string) $safe, '.-');

        return $safe !== '' ? $safe : 'account';
    }
}
