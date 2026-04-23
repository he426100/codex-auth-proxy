<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

use CodexAuthProxy\Account\CodexAccount;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;

final class CodexWebSocketUpstreamSession
{
    private ?Channel $requestTurn = null;
    private ?Client $client = null;
    private ?CodexAccount $account = null;
    /** @var array{payload:string,opcode:int,sessionKey:SessionKey}|null */
    private ?array $lastPayload = null;
    private ?int $activeFd = null;
    private float $lastTouchedAt;

    public function __construct(
        private readonly string $sessionId,
    ) {
        $this->lastTouchedAt = microtime(true);
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function touch(): void
    {
        $this->lastTouchedAt = microtime(true);
    }

    public function lastTouchedAt(): float
    {
        return $this->lastTouchedAt;
    }

    public function rememberPayload(SessionKey $sessionKey, string $payload, int $opcode): void
    {
        $this->lastPayload = [
            'payload' => $payload,
            'opcode' => $opcode,
            'sessionKey' => $sessionKey,
        ];
        $this->touch();
    }

    /** @return array{payload:string,opcode:int,sessionKey:SessionKey}|null */
    public function lastPayload(): ?array
    {
        return $this->lastPayload;
    }

    public function attachUpstream(Client $client, CodexAccount $account): void
    {
        $this->client = $client;
        $this->account = $account;
        $this->touch();
    }

    public function detachUpstream(?Client $expected = null): ?Client
    {
        if ($expected instanceof Client && $this->client !== $expected) {
            return null;
        }

        $client = $this->client;
        $this->client = null;
        $this->account = null;
        $this->touch();

        return $client;
    }

    public function client(): ?Client
    {
        return $this->client;
    }

    public function account(): ?CodexAccount
    {
        return $this->account;
    }

    public function markActive(?int $fd): void
    {
        $this->activeFd = $fd;
        $this->touch();
    }

    public function activeFd(): ?int
    {
        return $this->activeFd;
    }

    public function hasActiveRequest(): bool
    {
        return $this->activeFd !== null;
    }

    public function waitForTurn(): bool
    {
        $this->touch();
        if (!$this->requestTurn instanceof Channel) {
            $this->requestTurn = new Channel(1);
            $this->requestTurn->push(true);
        }

        return $this->requestTurn->pop() !== false;
    }

    public function releaseTurn(): void
    {
        $this->touch();
        if ($this->requestTurn instanceof Channel) {
            $this->requestTurn->push(true, 0.001);
        }
    }

    public function close(): ?Client
    {
        $client = $this->client;
        $this->client = null;
        $this->account = null;
        $this->activeFd = null;
        $this->lastPayload = null;
        $this->requestTurn?->close();
        $this->requestTurn = null;

        return $client;
    }
}
