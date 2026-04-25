<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests;

use CodexAuthProxy\Account\AccountRepository;
use InvalidArgumentException;

final class AccountRepositoryTest extends TestCase
{
    public function testLoadsOnlyCustomAccountFilesFromAccountDirectory(): void
    {
        $dir = $this->tempDir('cap-accounts');
        $this->writeJson($dir . '/alpha.account.json', $this->accountFixture('alpha'));
        $this->writeJson($dir . '/ordinary.json', [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('ignored')['tokens'],
        ]);

        $accounts = (new AccountRepository($dir))->load();

        self::assertCount(1, $accounts);
        self::assertSame('alpha', $accounts[0]->name());
    }

    public function testFailsFastForMalformedCustomAccountFiles(): void
    {
        $dir = $this->tempDir('cap-accounts');
        $this->writeJson($dir . '/bad.account.json', [
            'schema' => 'codex-auth-proxy.account.v1',
            'provider' => 'openai-chatgpt-codex',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bad.account.json');
        (new AccountRepository($dir))->load();
    }

    public function testSavesRefreshedAccountBackToSourcePath(): void
    {
        $dir = $this->tempDir('cap-accounts');
        $repository = new AccountRepository($dir);
        $account = (new \CodexAuthProxy\Account\AccountFileValidator())->validate($this->accountFixture('alpha'));
        $repository->save('alpha', $account);
        $loaded = $repository->load()[0];
        $fresh = $loaded->withTokens(
            $this->accountFixture('beta')['tokens']['id_token'],
            $this->accountFixture('beta')['tokens']['access_token'],
            $this->accountFixture('beta')['tokens']['refresh_token'],
        );

        $path = $repository->saveAccount($fresh);
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($dir . '/alpha.account.json', $path);
        self::assertSame($this->accountFixture('beta')['tokens']['access_token'], $decoded['tokens']['access_token']);
    }

    public function testRevisionChangesOnlyWhenCustomAccountFilesChange(): void
    {
        $dir = $this->tempDir('cap-accounts-revision');
        $repository = new AccountRepository($dir);
        $emptyRevision = $repository->revision();
        $this->writeJson($dir . '/ordinary.json', ['ignored' => true]);

        self::assertSame($emptyRevision, $repository->revision());

        $payload = $this->accountFixture('alpha');
        $this->writeJson($dir . '/alpha.account.json', $payload);
        $accountRevision = $repository->revision();

        self::assertNotSame($emptyRevision, $accountRevision);

        $payload['revision_marker'] = str_repeat('x', 128);
        $this->writeJson($dir . '/alpha.account.json', $payload);

        self::assertNotSame($accountRevision, $repository->revision());
    }

    public function testRevisionChangesWhenSameSizedAccountFileContentChangesWithinSameSecond(): void
    {
        $dir = $this->tempDir('cap-accounts-revision-fast-update');
        $repository = new AccountRepository($dir);
        $path = $dir . '/alpha.account.json';

        file_put_contents($path, str_repeat('a', 64));
        touch($path, 1700000000);
        $firstRevision = $repository->revision();

        file_put_contents($path, str_repeat('b', 64));
        touch($path, 1700000000);

        self::assertNotSame($firstRevision, $repository->revision());
    }

    public function testResolvesImplicitNameConflictWithAccountIdSuffix(): void
    {
        $dir = $this->tempDir('cap-accounts');
        $repository = new AccountRepository($dir);
        $existing = (new \CodexAuthProxy\Account\AccountFileValidator())->validate($this->accountFixture('alpha'));
        $repository->save('alpha-example.com', $existing);

        self::assertSame('alpha-example.com', $repository->resolveImplicitName('alpha-example.com', 'acct-alpha'));
        self::assertSame('alpha-example.com-acct-beta', $repository->resolveImplicitName('alpha-example.com', 'acct-beta'));
    }

    public function testFindsAndArchivesAccountByName(): void
    {
        $dir = $this->tempDir('cap-accounts');
        $repository = new AccountRepository($dir);
        $account = (new \CodexAuthProxy\Account\AccountFileValidator())->validate($this->accountFixture('alpha'));
        $repository->save('alpha', $account);

        self::assertSame('acct-alpha', $repository->findByName('alpha')->accountId());

        $archivedPath = $repository->deleteByName('alpha');

        self::assertFileDoesNotExist($dir . '/alpha.account.json');
        self::assertFileExists($archivedPath);
        self::assertStringContainsString('alpha.account.json.deleted.', $archivedPath);
        self::assertNull($repository->findByName('alpha'));
        self::assertSame([], $repository->load());
    }
}
