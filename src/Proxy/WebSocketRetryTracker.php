<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

final class WebSocketRetryTracker
{
    /** @var array<int,array<string,bool>> */
    private array $retriedPayloads = [];

    public function claimRetry(int $fd, string $payload, bool $forwardedData): bool
    {
        if ($forwardedData) {
            return false;
        }

        $hash = sha1($payload);
        if (($this->retriedPayloads[$fd][$hash] ?? false) === true) {
            return false;
        }

        $this->retriedPayloads[$fd][$hash] = true;

        return true;
    }

    public function clear(int $fd): void
    {
        unset($this->retriedPayloads[$fd]);
    }
}
