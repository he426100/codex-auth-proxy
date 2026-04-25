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

    public function revision(): string
    {
        if (!is_dir($this->directory)) {
            return 'accounts:none';
        }

        $files = glob(rtrim($this->directory, '/') . '/*.account.json') ?: [];
        sort($files);
        if ($files === []) {
            return 'accounts:none';
        }

        $parts = [];
        foreach ($files as $file) {
            clearstatcache(true, $file);
            if (!is_file($file)) {
                continue;
            }

            $parts[] = implode("\0", [
                basename($file),
                (string) (fileinode($file) ?: 0),
                (string) (filesize($file) ?: 0),
                (string) (filemtime($file) ?: 0),
                (string) (filectime($file) ?: 0),
                hash_file('sha256', $file) ?: '',
            ]);
        }
        if ($parts === []) {
            return 'accounts:none';
        }

        return 'accounts:' . hash('sha256', implode("\n", $parts));
    }

    public function save(string $name, CodexAccount $account): string
    {
        $path = rtrim($this->directory, '/') . '/' . $this->safeName($name) . '.account.json';

        return $this->write($path, $account);
    }

    public function resolveImplicitName(string $baseName, string $accountId): string
    {
        $base = $this->safeName($baseName);
        $path = $this->pathForName($base);
        if (!is_file($path) || $this->fileBelongsToAccount($path, $accountId)) {
            return $base;
        }

        $suffix = $this->safeName($accountId);
        $candidate = $base . '-' . $suffix;
        $index = 2;
        while (is_file($this->pathForName($candidate)) && !$this->fileBelongsToAccount($this->pathForName($candidate), $accountId)) {
            $candidate = $base . '-' . $suffix . '-' . $index;
            $index++;
        }

        return $candidate;
    }

    public function saveAccount(CodexAccount $account): string
    {
        if ($account->sourcePath() !== '') {
            return $this->write($account->sourcePath(), $account);
        }

        return $this->save($account->name(), $account);
    }

    public function findByName(string $name): ?CodexAccount
    {
        foreach ($this->load() as $account) {
            if ($account->name() === $name) {
                return $account;
            }
        }

        return null;
    }

    public function deleteByName(string $name): string
    {
        $account = $this->findByName($name);
        if ($account === null) {
            throw new InvalidArgumentException('Account not found: ' . $name);
        }
        if ($account->sourcePath() === '') {
            throw new RuntimeException('Account source path is unavailable: ' . $name);
        }

        $archivedPath = $this->archivePath($account->sourcePath());
        if (!rename($account->sourcePath(), $archivedPath)) {
            throw new RuntimeException('Failed to archive account file: ' . $account->sourcePath());
        }
        chmod($archivedPath, 0600);

        return $archivedPath;
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
                'plan_type' => $account->planType(),
            ],
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $tempPath = tempnam($dir, basename($path) . '.');
        if ($tempPath === false) {
            throw new RuntimeException('Failed to create temp account file for: ' . $path);
        }

        try {
            if (file_put_contents($tempPath, $json, LOCK_EX) === false) {
                throw new RuntimeException('Failed to write account file: ' . $path);
            }
            chmod($tempPath, 0600);
            if (!rename($tempPath, $path)) {
                throw new RuntimeException('Failed to replace account file: ' . $path);
            }
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }

        chmod($path, 0600);

        return $path;
    }

    private function pathForName(string $name): string
    {
        return rtrim($this->directory, '/') . '/' . $this->safeName($name) . '.account.json';
    }

    private function archivePath(string $path): string
    {
        $base = $path . '.deleted.' . date('YmdHis');
        $candidate = $base;
        $index = 2;
        while (file_exists($candidate)) {
            $candidate = $base . '-' . $index;
            $index++;
        }

        return $candidate;
    }

    private function fileBelongsToAccount(string $path, string $accountId): bool
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return false;
        }

        try {
            return $this->validator->validate($decoded, $path)->accountId() === $accountId;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function safeName(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($name));
        $safe = trim((string) $safe, '.-');

        return $safe === '' ? 'account' : $safe;
    }
}
