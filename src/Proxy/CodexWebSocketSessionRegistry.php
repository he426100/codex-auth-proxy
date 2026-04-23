<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

use Swoole\Coroutine\Http\Client;
use Swoole\Http\Request;

final class CodexWebSocketSessionRegistry
{
    /** @var array<int,Request> */
    private array $requests = [];

    /** @var array<int,string> */
    private array $fdSessions = [];

    /** @var array<string,CodexWebSocketUpstreamSession> */
    private array $sessions = [];

    public function rememberRequest(int $fd, Request $request): void
    {
        $this->requests[$fd] = $request;
    }

    public function request(int $fd): ?Request
    {
        return $this->requests[$fd] ?? null;
    }

    public function bindSession(int $fd, SessionKey $sessionKey): CodexWebSocketUpstreamSession
    {
        $sessionId = $sessionKey->primary;
        $previousSessionId = $this->fdSessions[$fd] ?? null;
        if ($previousSessionId !== null && $previousSessionId !== $sessionId) {
            $previous = $this->sessions[$previousSessionId] ?? null;
            if ($previous instanceof CodexWebSocketUpstreamSession && $previous->activeFd() === $fd) {
                $previous->markActive(null);
                $previous->releaseTurn();
            }
        }

        $this->fdSessions[$fd] = $sessionId;

        return $this->session($sessionId);
    }

    public function abortActiveRequestForNewFd(int $fd): ?Client
    {
        $session = $this->sessionForFd($fd);
        if (!$session instanceof CodexWebSocketUpstreamSession) {
            return null;
        }

        $activeFd = $session->activeFd();
        if ($activeFd === null || $activeFd === $fd) {
            return null;
        }

        $session->markActive(null);
        $session->releaseTurn();

        return $session->detachUpstream();
    }

    public function sessionForFd(int $fd): ?CodexWebSocketUpstreamSession
    {
        $sessionId = $this->fdSessions[$fd] ?? null;
        if ($sessionId === null) {
            return null;
        }

        return $this->sessions[$sessionId] ?? null;
    }

    public function sessionIdForFd(int $fd): ?string
    {
        return $this->fdSessions[$fd] ?? null;
    }

    public function activeFdForSession(string $sessionId): ?int
    {
        return $this->sessionById($sessionId)?->activeFd();
    }

    public function hasActiveRequestForSession(string $sessionId): bool
    {
        return $this->sessionById($sessionId)?->hasActiveRequest() ?? false;
    }

    /** @return array{payload:string,opcode:int,sessionKey:SessionKey}|null */
    public function lastPayloadForSession(string $sessionId): ?array
    {
        return $this->sessionById($sessionId)?->lastPayload();
    }

    public function rememberPayload(int $fd, SessionKey $sessionKey, string $payload, int $opcode): void
    {
        $session = $this->sessionForFd($fd);
        if (!$session instanceof CodexWebSocketUpstreamSession) {
            $session = $this->bindSession($fd, $sessionKey);
        }

        $session->rememberPayload($sessionKey, $payload, $opcode);
    }

    /** @return array{payload:string,opcode:int,sessionKey:SessionKey}|null */
    public function lastPayload(int $fd): ?array
    {
        return $this->sessionForFd($fd)?->lastPayload();
    }

    public function attachUpstream(int $fd, Client $client, \CodexAuthProxy\Account\CodexAccount $account): void
    {
        $this->sessionForFd($fd)?->attachUpstream($client, $account);
    }

    public function attachUpstreamToSession(string $sessionId, Client $client, \CodexAuthProxy\Account\CodexAccount $account): void
    {
        $this->session($sessionId)->attachUpstream($client, $account);
    }

    public function detachUpstream(int $fd, ?Client $expected = null): ?Client
    {
        return $this->sessionForFd($fd)?->detachUpstream($expected);
    }

    public function detachUpstreamFromSession(string $sessionId, ?Client $expected = null): ?Client
    {
        return $this->sessionById($sessionId)?->detachUpstream($expected);
    }

    public function client(int $fd): ?Client
    {
        return $this->sessionForFd($fd)?->client();
    }

    public function clientForSession(string $sessionId): ?Client
    {
        return $this->sessionById($sessionId)?->client();
    }

    public function account(int $fd): ?\CodexAuthProxy\Account\CodexAccount
    {
        return $this->sessionForFd($fd)?->account();
    }

    public function accountForSession(string $sessionId): ?\CodexAuthProxy\Account\CodexAccount
    {
        return $this->sessionById($sessionId)?->account();
    }

    public function markRequestActive(int $fd, bool $active): void
    {
        $session = $this->sessionForFd($fd);
        if (!$session instanceof CodexWebSocketUpstreamSession) {
            return;
        }

        $session->markActive($active ? $fd : null);
    }

    public function hasActiveRequest(int $fd): bool
    {
        return $this->sessionForFd($fd)?->hasActiveRequest() ?? false;
    }

    public function waitForRequestTurn(int $fd): bool
    {
        return $this->sessionForFd($fd)?->waitForTurn() ?? false;
    }

    public function releaseRequestTurn(int $fd): void
    {
        $this->sessionForFd($fd)?->releaseTurn();
    }

    public function releaseRequestTurnForSession(string $sessionId): void
    {
        $this->sessionById($sessionId)?->releaseTurn();
    }

    public function clear(int $fd): ?Client
    {
        unset($this->requests[$fd]);

        $sessionId = $this->fdSessions[$fd] ?? null;
        unset($this->fdSessions[$fd]);

        if ($sessionId === null) {
            return null;
        }

        $session = $this->sessions[$sessionId] ?? null;
        if (!$session instanceof CodexWebSocketUpstreamSession) {
            return null;
        }

        if ($session->activeFd() === $fd) {
            $session->markActive(null);
            $session->releaseTurn();

            return $session->detachUpstream();
        }

        return null;
    }

    public function sweepIdle(float $idleSeconds): array
    {
        $closed = [];
        $deadline = microtime(true) - $idleSeconds;
        foreach ($this->sessions as $sessionId => $session) {
            if ($session->hasActiveRequest()) {
                continue;
            }
            if ($session->lastTouchedAt() > $deadline) {
                continue;
            }
            $client = $session->close();
            unset($this->sessions[$sessionId]);
            if ($client instanceof Client) {
                $closed[] = $client;
            }
        }

        return $closed;
    }

    /** @return list<Client> */
    public function clearAll(): array
    {
        $clients = [];
        foreach ($this->sessions as $session) {
            $client = $session->close();
            if ($client instanceof Client) {
                $clients[] = $client;
            }
        }

        $this->requests = [];
        $this->fdSessions = [];
        $this->sessions = [];

        return $clients;
    }

    private function session(string $sessionId): CodexWebSocketUpstreamSession
    {
        if (!isset($this->sessions[$sessionId])) {
            $this->sessions[$sessionId] = new CodexWebSocketUpstreamSession($sessionId);
        }

        $this->sessions[$sessionId]->touch();

        return $this->sessions[$sessionId];
    }

    private function sessionById(string $sessionId): ?CodexWebSocketUpstreamSession
    {
        return $this->sessions[$sessionId] ?? null;
    }
}
