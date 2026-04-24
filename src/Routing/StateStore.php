<?php

declare(strict_types=1);

namespace CodexAuthProxy\Routing;

use RuntimeException;
use CodexAuthProxy\Usage\CachedAccountUsage;

final class StateStore
{
    private ?string $stateRevision = null;
    private string $candidateRevision = 'missing';
    private int $memoryRevision = 0;

    /** @param array<string,mixed> $state */
    private function __construct(private array $state, private readonly ?string $path = null)
    {
        if ($this->path === null) {
            $this->stateRevision = 'memory:0';
        }
        $this->refreshCandidateRevision();
    }

    /**
     * @return array{
     *   accounts: array<string,mixed>,
     *   sessions: array<string,mixed>,
     *   session_meta: array<string,mixed>,
     *   cursor: int,
     *   usage: array<string,mixed>
     * }
     */
    private static function defaultState(): array
    {
        return [
            'accounts' => [],
            'sessions' => [],
            'session_meta' => [],
            'cursor' => 0,
            'usage' => [],
        ];
    }

    public static function memory(): self
    {
        return new self(self::defaultState());
    }

    /** @param array<string,mixed> $state */
    public static function fromArray(array $state): self
    {
        return new self(self::normalizeState($state));
    }

    public static function file(string $path): self
    {
        $store = new self(self::readState($path), $path);
        $store->stateRevision = self::stateRevision($path);

        return $store;
    }

    /** @return array{accounts: array<string,mixed>, sessions: array<string,mixed>, session_meta: array<string,mixed>, cursor: int, usage: array<string,mixed>} */
    public function snapshot(): array
    {
        $this->reload();

        return self::normalizeState($this->state);
    }

    public function sessionAccount(string $sessionKey): ?string
    {
        $this->reload();
        $binding = $this->sessionBindingValue($this->state['sessions'][$sessionKey] ?? null);

        return $binding['account_id'] ?? null;
    }

    public function bindSession(string $sessionKey, string $accountId, ?string $selectionSource = null, ?int $boundAt = null, ?int $lastSeenAt = null): void
    {
        $this->update(function (array &$state) use ($sessionKey, $accountId, $selectionSource, $boundAt, $lastSeenAt): void {
            $state['sessions'][$sessionKey] = $accountId;
            if (!isset($state['session_meta']) || !is_array($state['session_meta'])) {
                $state['session_meta'] = [];
            }
            $resolvedLastSeenAt = $lastSeenAt !== null && $lastSeenAt > 0
                ? $lastSeenAt
                : (($boundAt !== null && $boundAt > 0) ? $boundAt : null);

            if (($selectionSource === null || $selectionSource === '') && ($boundAt === null || $boundAt <= 0) && $resolvedLastSeenAt === null) {
                unset($state['session_meta'][$sessionKey]);
                return;
            }

            $meta = [];
            if ($selectionSource !== null && $selectionSource !== '') {
                $meta['selection_source'] = $selectionSource;
            }
            if ($boundAt !== null && $boundAt > 0) {
                $meta['bound_at'] = $boundAt;
            }
            if ($resolvedLastSeenAt !== null) {
                $meta['last_seen_at'] = $resolvedLastSeenAt;
            }
            $state['session_meta'][$sessionKey] = $meta;
        });
    }

    public function touchSession(string $sessionKey, ?int $seenAt = null): void
    {
        $this->update(function (array &$state) use ($sessionKey, $seenAt): void {
            if (!isset($state['sessions'][$sessionKey]) || !is_string($state['sessions'][$sessionKey]) || $state['sessions'][$sessionKey] === '') {
                return;
            }
            if (!isset($state['session_meta']) || !is_array($state['session_meta'])) {
                $state['session_meta'] = [];
            }

            $meta = isset($state['session_meta'][$sessionKey]) && is_array($state['session_meta'][$sessionKey])
                ? $state['session_meta'][$sessionKey]
                : [];
            $meta['last_seen_at'] = $seenAt !== null && $seenAt > 0 ? $seenAt : time();
            $state['session_meta'][$sessionKey] = $meta;
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
        foreach ($this->allSessionBindings() as $sessionKey => $binding) {
            if ($sessionKey === '') {
                continue;
            }
            $sessions[$sessionKey] = $binding['account_id'];
        }

        return $sessions;
    }

    /** @return array{account_id:string,selection_source:?string,bound_at:?int,last_seen_at:?int}|null */
    public function sessionBinding(string $sessionKey): ?array
    {
        $this->reload();

        return $this->sessionBindingValue(
            $this->state['sessions'][$sessionKey] ?? null,
            $this->state['session_meta'][$sessionKey] ?? null,
        );
    }

    /** @return array<string,array{account_id:string,selection_source:?string,bound_at:?int,last_seen_at:?int}> */
    public function allSessionBindings(): array
    {
        $this->reload();
        if (!isset($this->state['sessions']) || !is_array($this->state['sessions'])) {
            return [];
        }

        $sessions = [];
        foreach ($this->state['sessions'] as $sessionKey => $binding) {
            if (!is_string($sessionKey) || $sessionKey === '') {
                continue;
            }

            $normalized = $this->sessionBindingValue($binding, $this->state['session_meta'][$sessionKey] ?? null);
            if ($normalized === null) {
                continue;
            }
            $sessions[$sessionKey] = $normalized;
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

    public function lastCooldownReason(string $accountId): ?string
    {
        $this->reload();
        $value = $this->state['accounts'][$accountId]['last_cooldown_reason'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function lastCooldownAt(string $accountId): ?int
    {
        $this->reload();
        $value = $this->state['accounts'][$accountId]['last_cooldown_at'] ?? null;

        return is_int($value) && $value > 0 ? $value : null;
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

    public function setCooldown(string $accountId, int $timestamp, ?string $reason, ?int $recordedAt = null): void
    {
        $this->update(function (array &$state) use ($accountId, $timestamp, $reason, $recordedAt): void {
            $state['accounts'][$accountId]['cooldown_until'] = max(0, $timestamp);
            if ($timestamp <= 0 || $reason === null || $reason === '') {
                unset($state['accounts'][$accountId]['cooldown_reason']);
                return;
            }
            $state['accounts'][$accountId]['cooldown_reason'] = $reason;
            $state['accounts'][$accountId]['last_cooldown_reason'] = $reason;
            $state['accounts'][$accountId]['last_cooldown_at'] = $recordedAt !== null && $recordedAt > 0 ? $recordedAt : time();
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

    public function candidateRevision(): string
    {
        $this->reload();

        return $this->candidateRevision;
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
        foreach (['accounts', 'sessions', 'session_meta', 'usage'] as $key) {
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
        $this->refreshCandidateRevision();
    }

    /** @return array{account_id:string,selection_source:?string,bound_at:?int,last_seen_at:?int}|null */
    private function sessionBindingValue(mixed $value, mixed $meta = null): ?array
    {
        $selectionSource = null;
        $boundAt = null;
        $lastSeenAt = null;
        if (is_string($value) && $value !== '') {
            $accountId = $value;
        } elseif (is_array($value)) {
            $accountId = $value['account_id'] ?? null;
            if (!is_string($accountId) || $accountId === '') {
                return null;
            }
            $selectionSource = $this->selectionSourceValue($value['selection_source'] ?? null);
            $boundAt = $this->boundAtValue($value['bound_at'] ?? null);
            $lastSeenAt = $this->lastSeenAtValue($value['last_seen_at'] ?? null);
        } else {
            return null;
        }

        if (is_array($meta)) {
            $selectionSource = $this->selectionSourceValue($meta['selection_source'] ?? $selectionSource);
            $boundAt = $this->boundAtValue($meta['bound_at'] ?? $boundAt);
            $lastSeenAt = $this->lastSeenAtValue($meta['last_seen_at'] ?? $lastSeenAt);
        }

        return [
            'account_id' => $accountId,
            'selection_source' => $selectionSource,
            'bound_at' => $boundAt,
            'last_seen_at' => $lastSeenAt,
        ];
    }

    private function selectionSourceValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function boundAtValue(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    private function lastSeenAtValue(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * @param callable(array<string,mixed>):void $mutator
     */
    private function update(callable $mutator): void
    {
        if ($this->path === null) {
            $mutator($this->state);
            $this->memoryRevision++;
            $this->stateRevision = 'memory:' . $this->memoryRevision;
            $this->refreshCandidateRevision();
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
            $this->refreshCandidateRevision();
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
        $this->refreshCandidateRevision();
    }

    private function refreshCandidateRevision(): void
    {
        $candidateState = [
            'accounts' => $this->state['accounts'] ?? [],
            'usage' => $this->state['usage'] ?? [],
        ];

        $this->candidateRevision = hash(
            'sha256',
            json_encode($candidateState, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
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
