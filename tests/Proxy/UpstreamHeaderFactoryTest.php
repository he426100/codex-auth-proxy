<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Proxy;

use CodexAuthProxy\Account\AccountFileValidator;
use CodexAuthProxy\Proxy\UpstreamHeaderFactory;
use CodexAuthProxy\Tests\TestCase;

final class UpstreamHeaderFactoryTest extends TestCase
{
    public function testBuildsCodexHeadersWithoutLeakingDownstreamAuthorization(): void
    {
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));
        $headers = (new UpstreamHeaderFactory('codex-cli-test', 'multi_agent'))->build([
            'authorization' => 'Bearer downstream',
            'host' => '127.0.0.1:1456',
            'accept' => 'application/json',
        ], $account, 'api.openai.com', false);

        self::assertSame('api.openai.com', $headers['Host']);
        self::assertSame('Bearer ' . $account->accessToken(), $headers['Authorization']);
        self::assertSame('identity', $headers['Accept-Encoding']);
        self::assertSame('codex-cli-test', $headers['User-Agent']);
        self::assertSame('application/json', $headers['Content-Type']);
        self::assertSame('text/event-stream', $headers['Accept']);
        self::assertSame('Keep-Alive', $headers['Connection']);
        self::assertSame('codex-tui', $headers['Originator']);
        self::assertSame('acct-alpha', $headers['Chatgpt-Account-Id']);
        self::assertArrayNotHasKey('authorization', $headers);
    }

    public function testBuildsJsonAcceptHeaderForBufferedJsonEndpoints(): void
    {
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));
        $headers = (new UpstreamHeaderFactory('codex-cli-test', 'multi_agent'))->build(
            ['accept' => 'text/event-stream'],
            $account,
            'api.openai.com',
            false,
            'application/json',
        );

        self::assertSame('application/json', $headers['Accept']);
    }

    public function testDoesNotInjectDefaultBetaFeaturesOnHttpRequests(): void
    {
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));
        $headers = (new UpstreamHeaderFactory('codex-cli-test', 'multi_agent'))->build([], $account, 'api.openai.com', false);

        self::assertArrayNotHasKey('X-Codex-Beta-Features', $headers);
    }

    public function testBuildsCodexWebSocketHeaders(): void
    {
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));
        $factory = new UpstreamHeaderFactory('codex-cli-test', 'multi_agent');
        $headers = $factory->build([
            'user-agent' => 'downstream-agent',
            'openai-beta' => 'existing',
        ], $account, 'chatgpt.com', true);

        self::assertSame('Bearer ' . $account->accessToken(), $headers['Authorization']);
        self::assertSame('multi_agent', $headers['X-Codex-Beta-Features']);
        self::assertSame('responses_websockets=2026-02-06', $headers['OpenAI-Beta']);
        self::assertSame('codex-tui', $headers['Originator']);
        self::assertSame('acct-alpha', $headers['Chatgpt-Account-Id']);
        self::assertArrayNotHasKey('User-Agent', $headers);
        self::assertArrayNotHasKey('user-agent', $headers);
    }
}
