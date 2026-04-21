<?php

declare(strict_types=1);

namespace CodexAuthProxy\Account;

use DateTimeImmutable;
use DateTimeZone;

final class CodexCliAuth
{
    /**
     * @return array{
     *     auth_mode: string,
     *     OPENAI_API_KEY: null,
     *     tokens: array{id_token: string, access_token: string, refresh_token: string, account_id: string},
     *     last_refresh: string
     * }
     */
    public static function payload(CodexAccount $account): array
    {
        return [
            'auth_mode' => 'chatgpt',
            'OPENAI_API_KEY' => null,
            'tokens' => [
                'id_token' => $account->idToken(),
                'access_token' => $account->accessToken(),
                'refresh_token' => $account->refreshToken(),
                'account_id' => $account->accountId(),
            ],
            'last_refresh' => self::lastRefresh(),
        ];
    }

    private static function lastRefresh(): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $now->format('Y-m-d\TH:i:s.') . $now->format('u') . '000Z';
    }
}
