<?php

declare(strict_types=1);

namespace CodexAuthProxy\Routing;

use RuntimeException;
use CodexAuthProxy\Usage\CachedAccountUsage;

final class StateStore
{
    private ?string $stateRevision = null;

    /** @param array<string,mixed> $state */
    private function __construct(private array $state, private readonly ?string $path = null)
    {
    }

    /**
     * @return array{accounts: array<string,mixed>, sessions: array<string,mixed>, cursor: int, usage: array<string,mixed>}
     */
    private static function defaultState(): array
    {
        return [
            'accounts' => [],
            'sessions' => [],
            'cursor' => 0,
            'usage' => [],
        ];
    }

    public static function memory(): self
    {
        return new self(self::defaultState());
    }

    public static function file(string $path): self
    {
        $store = new self(self::readState($path), $path);
        $store->stateRevision = self::stateRevision($path);

        return $store;
    }

    public function sessionAccount(string $sessionKey): ?string
    {
        $this->reload();
        $value = $this->state['sessions'][$sessionKey] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function bindSession(string $sessionKey, string $accountId): void
    {
        $this->update(function (array &$state) use ($sessionKey, $accountId): void {
            $state['sessions'][$sessionKey] = $accountId;
        });
    }

    /** @return array<string,string> */
    public function allSessionAccounts(): array
    {
        $this->reload();
        if (!isset($this->state['sessions']) || !is_array($this->state['sessions'])) {
            return [];
        }

        $sessions = [];
        foreach ($this->state['sessions'] as $sessionKey => $accountId) {
            if (!is_string($sessionKey) || $sessionKey === '' || !is_string($accountId) || $accountId === '') {
                continue;
            }
            $sessions[$sessionKey] = $accountId;
        }

        return $sessions;
    }

    public function cooldownUntil(string $accountId): int
    {
        $this->reload();
        $value = $this->state['accounts'][$accountId]['cooldown_until'] ?? 0;

        return is_int($value) ? $value : 0;
    }

    public function cooldownReason(string $accountId): ?string
    {
        $this->reload();
        $value = $this->state['accounts'][$accountId]['cooldown_reason'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setCooldownUntil(string $accountId, int $timestamp): void
    {
        $this->update(function (array &$state) use ($accountId, $timestamp): void {
            $state['accounts'][$accountId]['cooldown_until'] = max(0, $timestamp);
            if ($timestamp <= 0) {
                unset($state['accounts'][$accountId]['cooldown_reason']);
            }
        });
    }

    public function setCooldown(string $accountId, int $timestamp, ?string $reason): void
    {
        $this->update(function (array &$state) use ($accountId, $timestamp, $reason): void {
            $state['accounts'][$accountId]['cooldown_until'] = max(0, $timestamp);
            if ($timestamp <= 0 || $reason === null || $reason === '') {
                unset($state['accounts'][$accountId]['cooldown_reason']);
                return;
            }
            $state['accounts'][$accountId]['cooldown_reason'] = $reason;
        });
    }

    public function cursor(): int
    {
        $this->reload();
        $cursor = $this->state['cursor'] ?? 0;

        return is_int($cursor) ? $cursor : 0;
    }

    public function setCursor(int $cursor): void
    {
        $this->update(function (array &$state) use ($cursor): void {
            $state['cursor'] = max(0, $cursor);
        });
    }

    /**
     * @return array<string,CachedAccountUsage>
     */
    public function allAccountUsage(): array
    {
        $this->reload();
        if (!isset($this->state['usage']) || !is_array($this->state['usage'])) {
            return [];
        }

        $usage = [];
        foreach ($this->state['usage'] as $accountId => $value) {
            if (!is_string($accountId) || !is_array($value)) {
                continue;
            }
            $entry = CachedAccountUsage::fromArray($value);
            if ($entry !== null) {
                $usage[$accountId] = $entry;
            }
        }

        return $usage;
    }

    public function accountUsage(string $accountId): ?CachedAccountUsage
    {
        $usage = $this->allAccountUsage();

        return $usage[$accountId] ?? null;
    }

    public function setAccountUsage(string $accountId, CachedAccountUsage $usage): void
    {
        $this->update(function (array &$state) use ($accountId, $usage): void {
            if (!isset($state['usage']) || !is_array($state['usage'])) {
                $state['usage'] = [];
            }

            $state['usage'][$accountId] = $usage->toArray();
        });
    }

    public function setAccountUsageError(string $accountId, string $error, int $checkedAt): void
    {
        $this->update(function (array &$state) use ($accountId, $error, $checkedAt): void {
            $current = isset($state['usage'][$accountId]) && is_array($state['usage'][$accountId])
                ? CachedAccountUsage::fromArray($state['usage'][$accountId])
                : null;
            $updated = $current?->withError($error, $checkedAt)
                ?? new CachedAccountUsage('', $checkedAt, $error, null, null);
            if (!isset($state['usage']) || !is_array($state['usage'])) {
                $state['usage'] = [];
            }
            $state['usage'][$accountId] = $updated->toArray();
        });
    }

    /** @return array<string,mixed> */
    private static function readState(string $path): array
    {
        if (!is_file($path)) {
            return self::defaultState();
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('State file must be a JSON object: ' . $path);
        }

        return self::normalizeState($decoded);
    }

    /** @param array<string,mixed> $state */
    private static function normalizeState(array $state): array
    {
        $state += self::defaultState();
        foreach (['accounts', 'sessions', 'usage'] as $key) {
            if (!isset($state[$key]) || !is_array($state[$key])) {
                $state[$key] = [];
            }
        }
        if (!is_int($state['cursor'] ?? null)) {
            $state['cursor'] = 0;
        }

        return $state;
    }

    private function reload(): void
    {
        if ($this->path === null) {
            return;
        }

        $revision = self::stateRevision($this->path);
        if ($revision === $this->stateRevision) {
            return;
        }

        $this->state = self::readState($this->path);
        $this->stateRevision = $revision;
    }

    /**
     * @param callable(array<string,mixed>):void $mutator
     */
    private function update(callable $mutator): void
    {
        if ($this->path === null) {
            $mutator($this->state);
            return;
        }

        $this->ensureStateDirectory();
        $lockPath = $this->path . '.lock';
        $lock = fopen($lockPath, 'c');
        if (!is_resource($lock)) {
            throw new RuntimeException('Failed to open state lock file: ' . $lockPath);
        }

        $locked = false;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Failed to lock state file: ' . $lockPath);
            }
            $locked = true;
            chmod($lockPath, 0600);
            $this->state = self::readState($this->path);
            $this->stateRevision = self::stateRevision($this->path);
            $mutator($this->state);
            $this->writeState();
        } finally {
            if ($locked) {
                flock($lock, LOCK_UN);
            }
            fclose($lock);
        }
    }

    private function ensureStateDirectory(): void
    {
        if ($this->path === null) {
            return;
        }

        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create state directory: ' . $dir);
        }
    }

    private function writeState(): void
    {
        if ($this->path === null) {
            return;
        }

        $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $tmpPath = $this->path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmpPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write state file: ' . $tmpPath);
        }
        chmod($tmpPath, 0600);
        if (!rename($tmpPath, $this->path)) {
            @unlink($tmpPath);
            throw new RuntimeException('Failed to replace state file: ' . $this->path);
        }
        chmod($this->path, 0600);
        $this->stateRevision = self::stateRevision($this->path);
    }

    private static function stateRevision(string $path): string
    {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if (!is_array($stat)) {
            return 'missing';
        }

        return implode(':', [
            (string) $stat['ino'],
            (string) $stat['size'],
            (string) $stat['mtime'],
        ]);
    }
}
