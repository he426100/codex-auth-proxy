<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests;

use CodexAuthProxy\Account\AccountFileValidator;
use InvalidArgumentException;

final class AccountFileValidatorTest extends TestCase
{
    public function testAcceptsValidCodexAuthProxyAccountFile(): void
    {
        $validator = new AccountFileValidator();

        $account = $validator->validate($this->accountFixture('alpha'));

        self::assertSame('alpha', $account->name());
        self::assertSame('acct-alpha', $account->accountId());
        self::assertSame('alpha@example.com', $account->email());
    }

    public function testRejectsOrdinaryCodexAuthJsonWithoutProxySchema(): void
    {
        $validator = new AccountFileValidator();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('schema');
        $validator->validate([
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('alpha')['tokens'],
        ]);
    }

    public function testRejectsNonChatgptCodexAccountFiles(): void
    {
        $validator = new AccountFileValidator();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('provider');
        $validator->validate($this->accountFixture('alpha', ['provider' => 'api-key']));
    }

    public function testRejectsAccountFilesWithoutOpenAIChatGPTAuthClaims(): void
    {
        $validator = new AccountFileValidator();
        $jwt = $this->makeJwt(['iss' => 'https://auth.openai.com', 'email' => 'alpha@example.com']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('chatgpt_account_id');
        $validator->validate($this->accountFixture('alpha', ['tokens' => ['id_token' => $jwt]]));
    }
}
