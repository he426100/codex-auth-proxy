<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Proxy;

use CodexAuthProxy\Account\CodexAccount;
use CodexAuthProxy\Proxy\CodexWebSocketSessionRegistry;
use CodexAuthProxy\Proxy\SessionKey;
use CodexAuthProxy\Tests\TestCase;
use Swoole\Coroutine\Http\Client;
use Swoole\Http\Request;

final class CodexWebSocketSessionRegistryTest extends TestCase
{
    public function testStoresAndClearsPerFdState(): void
    {
        $registry = new CodexWebSocketSessionRegistry();
        $request = new Request();
        $client = new Client('127.0.0.1', 1456);
        $account = $this->account('alpha');

        $registry->rememberRequest(7, $request);
        $registry->bindSession(7, new SessionKey('msg:abc'));
        $registry->rememberPayload(7, new SessionKey('msg:abc'), '{"type":"response.create"}', WEBSOCKET_OPCODE_TEXT, 'turn:1', null);
        $registry->attachUpstream(7, $client, $account);
        $registry->markRequestActive(7, true);

        self::assertSame($request, $registry->request(7));
        self::assertSame($client, $registry->client(7));
        self::assertSame($account, $registry->account(7));
        self::assertTrue($registry->hasActiveRequest(7));
        self::assertSame('{"type":"response.create"}', $registry->lastPayload(7)['payload']);
        self::assertSame('msg:abc', $registry->lastPayload(7)['sessionKey']->primary);

        self::assertSame($client, $registry->detachUpstream(7, $client));
        self::assertNull($registry->client(7));
        self::assertNull($registry->account(7));
        self::assertTrue($registry->hasActiveRequest(7));

        $registry->clear(7);
        self::assertNull($registry->request(7));
        self::assertNull($registry->client(7));
        self::assertNull($registry->account(7));
        self::assertFalse($registry->hasActiveRequest(7));
    }

    public function testReturnsAllTrackedClientsWhenClearingAll(): void
    {
        $registry = new CodexWebSocketSessionRegistry();
        $clientA = new Client('127.0.0.1', 1456);
        $clientB = new Client('127.0.0.1', 1457);

        $registry->rememberRequest(7, new Request());
        $registry->rememberRequest(8, new Request());
        $registry->bindSession(7, new SessionKey('msg:a'));
        $registry->bindSession(8, new SessionKey('msg:b'));
        $registry->attachUpstream(7, $clientA, $this->account('alpha'));
        $registry->attachUpstream(8, $clientB, $this->account('beta'));

        self::assertSame([$clientA, $clientB], $registry->clearAll());
        self::assertNull($registry->client(7));
        self::assertNull($registry->client(8));
    }

    public function testRequestTurnCanBeReacquiredAfterRelease(): void
    {
        $registry = new CodexWebSocketSessionRegistry();
        $registry->rememberRequest(7, new Request());
        $registry->bindSession(7, new SessionKey('msg:turn'));

        \Swoole\Coroutine\run(static function () use ($registry): void {
            self::assertTrue($registry->waitForRequestTurn(7));
            $registry->releaseRequestTurn(7);
            self::assertTrue($registry->waitForRequestTurn(7));
        });
    }

    public function testReusesSameSessionStateAcrossDifferentFds(): void
    {
        $registry = new CodexWebSocketSessionRegistry();
        $client = new Client('127.0.0.1', 1456);
        $account = $this->account('alpha');

        $registry->rememberRequest(7, new Request());
        $registry->rememberRequest(8, new Request());
        $registry->bindSession(7, new SessionKey('turn:reuse'));
        $registry->bindSession(8, new SessionKey('turn:reuse'));
        $registry->attachUpstream(7, $client, $account);
        $registry->rememberPayload(7, new SessionKey('turn:reuse'), '{"type":"response.create"}', WEBSOCKET_OPCODE_TEXT, 'turn:reuse', null);

        self::assertSame($client, $registry->client(8));
        self::assertSame($account, $registry->account(8));
        self::assertSame('{"type":"response.create"}', $registry->lastPayload(8)['payload']);

        $registry->clear(7);

        self::assertSame($client, $registry->client(8));
        self::assertSame($account, $registry->account(8));
        self::assertSame('turn:reuse', $registry->sessionIdForFd(8));
    }

    public function testSweepIdleClosesInactiveSessionClients(): void
    {
        $registry = new CodexWebSocketSessionRegistry();
        $client = new Client('127.0.0.1', 1456);

        $registry->rememberRequest(7, new Request());
        $registry->bindSession(7, new SessionKey('turn:idle'));
        $registry->attachUpstream(7, $client, $this->account('alpha'));

        usleep(2_000);

        self::assertSame([$client], $registry->sweepIdle(0.001));
        self::assertNull($registry->client(7));
    }

    public function testClearReturnsActiveUpstreamClientForAbort(): void
    {
        $registry = new CodexWebSocketSessionRegistry();
        $client = new Client('127.0.0.1', 1456);

        $registry->rememberRequest(7, new Request());
        $registry->bindSession(7, new SessionKey('turn:active-abort'));
        $registry->attachUpstream(7, $client, $this->account('alpha'));
        $registry->markRequestActive(7, true);

        self::assertSame($client, $registry->clear(7));
        self::assertFalse($registry->hasActiveRequest(7));
        self::assertNull($registry->client(7));
    }

    public function testAbortActiveRequestWhenDifferentFdTakesOverSession(): void
    {
        $registry = new CodexWebSocketSessionRegistry();
        $client = new Client('127.0.0.1', 1456);

        $registry->rememberRequest(7, new Request());
        $registry->rememberRequest(8, new Request());
        $registry->bindSession(7, new SessionKey('turn:takeover'));
        $registry->attachUpstream(7, $client, $this->account('alpha'));
        $registry->markRequestActive(7, true);
        $registry->bindSession(8, new SessionKey('turn:takeover'));

        self::assertSame($client, $registry->abortActiveRequestForNewFd(8));
        self::assertFalse($registry->hasActiveRequest(8));
        self::assertNull($registry->client(8));
    }

    private function account(string $name): CodexAccount
    {
        return new CodexAccount(
            name: $name,
            accountId: 'acct-' . $name,
            email: $name . '@example.com',
            planType: 'plus',
            idToken: 'id-token',
            accessToken: 'access-token',
            refreshToken: 'refresh-token',
            enabled: true,
        );
    }
}
