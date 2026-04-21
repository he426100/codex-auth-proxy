<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests;

use CodexAuthProxy\Account\AccountFileValidator;
use CodexAuthProxy\Account\CodexAuthImporter;
use InvalidArgumentException;

final class CodexAuthImporterTest extends TestCase
{
    public function testImportsOfficialCodexAuthJsonIntoProxyAccountSchema(): void
    {
        $source = [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('alpha')['tokens'],
        ];

        $accountFile = (new CodexAuthImporter())->import($source, 'alpha');
        $account = (new AccountFileValidator())->validate($accountFile);

        self::assertSame('codex-auth-proxy.account.v1', $accountFile['schema']);
        self::assertSame('openai-chatgpt-codex', $accountFile['provider']);
        self::assertSame('alpha', $account->name());
        self::assertSame('acct-alpha', $account->accountId());
    }

    public function testRejectsApiKeyAuthJsonDuringImport(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('chatgpt');
        (new CodexAuthImporter())->import([
            'auth_mode' => 'api-key',
            'tokens' => $this->accountFixture('alpha')['tokens'],
        ], 'alpha');
    }
}
