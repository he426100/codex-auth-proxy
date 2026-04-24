<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

final class WebSocketRetryTracker
{
    /** @var array<int,array{hash:string,attempted_accounts:array<string,bool>}> */
    private array $payloadAttempts = [];

    public function beginPayload(int $fd, string $payload): void
    {
        $this->payloadAttempts[$fd] = [
            'hash' => sha1($payload),
            'attempted_accounts' => [],
        ];
    }

    public function claimRetry(int $fd, string $payload, string $accountId, bool $forwardedData): bool
    {
        if ($forwardedData) {
            return false;
        }

        $hash = sha1($payload);
        $entry = $this->payloadAttempts[$fd] ?? [
            'hash' => $hash,
            'attempted_accounts' => [],
        ];
        if ($entry['hash'] !== $hash) {
            $entry = [
                'hash' => $hash,
                'attempted_accounts' => [],
            ];
        }
        if (($entry['attempted_accounts'][$accountId] ?? false) === true) {
            return false;
        }

        $entry['attempted_accounts'][$accountId] = true;
        $this->payloadAttempts[$fd] = $entry;

        return true;
    }

    /** @return list<string> */
    public function attemptedAccounts(int $fd, string $payload): array
    {
        $hash = sha1($payload);
        $entry = $this->payloadAttempts[$fd] ?? null;
        if (!is_array($entry) || $entry['hash'] !== $hash) {
            return [];
        }

        return array_keys(array_filter(
            $entry['attempted_accounts'],
            static fn (mixed $attempted): bool => $attempted === true,
        ));
    }

    public function clear(int $fd): void
    {
        unset($this->payloadAttempts[$fd]);
    }
}
