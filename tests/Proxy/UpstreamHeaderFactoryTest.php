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
        $headers = (new UpstreamHeaderFactory($this->runtimeProfile('codex-cli-test', 'multi_agent', 'codex-originator-test')))->build([
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
        self::assertSame('codex-originator-test', $headers['Originator']);
        self::assertSame('acct-alpha', $headers['ChatGPT-Account-ID']);
        self::assertArrayNotHasKey('authorization', $headers);
    }

    public function testBuildsJsonAcceptHeaderForBufferedJsonEndpoints(): void
    {
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));
        $headers = (new UpstreamHeaderFactory($this->runtimeProfile('codex-cli-test', 'multi_agent')))->build(
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
        $headers = (new UpstreamHeaderFactory($this->runtimeProfile('codex-cli-test', 'multi_agent')))->build([], $account, 'api.openai.com', false);

        self::assertArrayNotHasKey('X-Codex-Beta-Features', $headers);
    }

    public function testBuildsCodexWebSocketHeaders(): void
    {
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));
        $factory = new UpstreamHeaderFactory($this->runtimeProfile('codex-cli-test', 'multi_agent', 'codex-originator-test'));
        $headers = $factory->build([
            'user-agent' => 'downstream-agent',
            'originator' => 'downstream-originator',
            'openai-beta' => 'existing',
            'session_id' => 'session-1',
            'x-codex-window-id' => 'session-1:0',
            'x-openai-subagent' => 'subagent-1',
            'x-codex-parent-thread-id' => 'parent-1',
        ], $account, 'chatgpt.com', true);

        self::assertSame('Bearer ' . $account->accessToken(), $headers['Authorization']);
        self::assertSame('downstream-agent', $headers['User-Agent']);
        self::assertSame('multi_agent', $headers['X-Codex-Beta-Features']);
        self::assertSame('responses_websockets=2026-02-06', $headers['OpenAI-Beta']);
        self::assertSame('downstream-originator', $headers['Originator']);
        self::assertSame('acct-alpha', $headers['ChatGPT-Account-ID']);
        self::assertSame('session-1', $headers['session_id']);
        self::assertSame('session-1:0', $headers['X-Codex-Window-Id']);
        self::assertSame('subagent-1', $headers['X-OpenAI-Subagent']);
        self::assertSame('parent-1', $headers['X-Codex-Parent-Thread-Id']);
        self::assertArrayNotHasKey('user-agent', $headers);
    }

    public function testDropsSensitiveDownstreamHeaders(): void
    {
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));
        $headers = (new UpstreamHeaderFactory($this->runtimeProfile('codex-cli-test', 'multi_agent')))->build([
            'cookie' => 'session=secret',
            'x-api-key' => 'downstream-key',
            'x-stainless-runtime' => 'node',
            'anthropic-version' => '2023-06-01',
            'x-custom-safe' => 'kept',
        ], $account, 'chatgpt.com', true);

        self::assertArrayNotHasKey('cookie', $headers);
        self::assertArrayNotHasKey('x-api-key', $headers);
        self::assertArrayNotHasKey('x-stainless-runtime', $headers);
        self::assertArrayNotHasKey('anthropic-version', $headers);
        self::assertSame('kept', $headers['x-custom-safe']);
    }

    public function testInjectsStoredTurnStateOnlyWhenDownstreamDoesNotProvideOne(): void
    {
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));
        $factory = new UpstreamHeaderFactory($this->runtimeProfile('codex-cli-test', 'multi_agent'));

        $headers = $factory->build([], $account, 'chatgpt.com', true, turnState: 'ts-stored');
        self::assertSame('ts-stored', $headers['X-Codex-Turn-State']);

        $headers = $factory->build(['x-codex-turn-state' => 'ts-downstream'], $account, 'chatgpt.com', true, turnState: 'ts-stored');
        self::assertSame('ts-downstream', $headers['X-Codex-Turn-State']);
    }

    public function testStripsSessionAffinityHeadersForCrossAccountFailover(): void
    {
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));
        $factory = new UpstreamHeaderFactory($this->runtimeProfile('codex-cli-test', 'multi_agent'));

        $headers = $factory->build([
            'session_id' => 'session-1',
            'x-codex-turn-state' => 'ts-downstream',
            'x-codex-turn-metadata' => '{"turn_id":"turn-1"}',
            'x-codex-window-id' => 'session-1:0',
            'x-openai-subagent' => 'subagent-1',
        ], $account, 'chatgpt.com', true, turnState: 'ts-stored', turnMetadata: '{"turn_id":"turn-stored"}', stripSessionAffinity: true);

        self::assertArrayNotHasKey('session_id', $headers);
        self::assertArrayNotHasKey('X-Codex-Turn-State', $headers);
        self::assertArrayNotHasKey('X-Codex-Turn-Metadata', $headers);
        self::assertArrayNotHasKey('X-Codex-Window-Id', $headers);
        self::assertArrayNotHasKey('X-Codex-Parent-Thread-Id', $headers);
        self::assertSame('subagent-1', $headers['X-OpenAI-Subagent']);
    }
}
