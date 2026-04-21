<?php

declare(strict_types=1);

namespace CodexAuthProxy\Account;

use InvalidArgumentException;
use RuntimeException;

final class AccountRepository
{
    public function __construct(
        private readonly string $directory,
        private readonly AccountFileValidator $validator = new AccountFileValidator(),
    ) {
    }

    /** @return list<CodexAccount> */
    public function load(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $files = glob(rtrim($this->directory, '/') . '/*.account.json') ?: [];
        sort($files);

        $accounts = [];
        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (!is_array($decoded)) {
                throw new InvalidArgumentException(basename($file) . ': account file must be a JSON object');
            }

            try {
                $accounts[] = $this->validator->validate($decoded, $file);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException(basename($file) . ': ' . $exception->getMessage(), 0, $exception);
            }
        }

        return $accounts;
    }

    public function save(string $name, CodexAccount $account): string
    {
        $path = rtrim($this->directory, '/') . '/' . $this->safeName($name) . '.account.json';

        return $this->write($path, $account);
    }

    public function saveAccount(CodexAccount $account): string
    {
        if ($account->sourcePath() !== '') {
            return $this->write($account->sourcePath(), $account);
        }

        return $this->save($account->name(), $account);
    }

    private function write(string $path, CodexAccount $account): string
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create account directory: ' . $dir);
        }

        $payload = [
            'schema' => AccountFileValidator::SCHEMA,
            'provider' => AccountFileValidator::PROVIDER,
            'name' => $account->name(),
            'enabled' => $account->enabled(),
            'tokens' => [
                'id_token' => $account->idToken(),
                'access_token' => $account->accessToken(),
                'refresh_token' => $account->refreshToken(),
                'account_id' => $account->accountId(),
            ],
            'metadata' => [
                'email' => $account->email(),
            ],
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write account file: ' . $path);
        }
        chmod($path, 0600);

        return $path;
    }

    private function safeName(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($name));
        $safe = trim((string) $safe, '.-');

        return $safe === '' ? 'account' : $safe;
    }
}
