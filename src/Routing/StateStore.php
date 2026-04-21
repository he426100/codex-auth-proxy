<?php

declare(strict_types=1);

namespace CodexAuthProxy\Routing;

use RuntimeException;
use CodexAuthProxy\Usage\CachedAccountUsage;

final class StateStore
{
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
        if (!is_file($path)) {
            return new self(self::defaultState(), $path);
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('State file must be a JSON object: ' . $path);
        }

        $state = $decoded + self::defaultState();
        if (!isset($state['usage']) || !is_array($state['usage'])) {
            $state['usage'] = [];
        }

        return new self($state, $path);
    }

    public function sessionAccount(string $sessionKey): ?string
    {
        $value = $this->state['sessions'][$sessionKey] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function bindSession(string $sessionKey, string $accountId): void
    {
        $this->state['sessions'][$sessionKey] = $accountId;
        $this->save();
    }

    public function cooldownUntil(string $accountId): int
    {
        $value = $this->state['accounts'][$accountId]['cooldown_until'] ?? 0;

        return is_int($value) ? $value : 0;
    }

    public function setCooldownUntil(string $accountId, int $timestamp): void
    {
        $this->state['accounts'][$accountId]['cooldown_until'] = $timestamp;
        $this->save();
    }

    public function cursor(): int
    {
        $cursor = $this->state['cursor'] ?? 0;

        return is_int($cursor) ? $cursor : 0;
    }

    public function setCursor(int $cursor): void
    {
        $this->state['cursor'] = max(0, $cursor);
        $this->save();
    }

    /**
     * @return array<string,CachedAccountUsage>
     */
    public function allAccountUsage(): array
    {
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
        if (!isset($this->state['usage']) || !is_array($this->state['usage'])) {
            $this->state['usage'] = [];
        }

        $this->state['usage'][$accountId] = $usage->toArray();
        $this->save();
    }

    public function setAccountUsageError(string $accountId, string $error, int $checkedAt): void
    {
        $current = $this->accountUsage($accountId);
        $updated = $current?->withError($error, $checkedAt)
            ?? new CachedAccountUsage('', $checkedAt, $error, null, null);

        $this->setAccountUsage($accountId, $updated);
    }

    private function save(): void
    {
        if ($this->path === null) {
            return;
        }

        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create state directory: ' . $dir);
        }

        $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($this->path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write state file: ' . $this->path);
        }
        chmod($this->path, 0600);
    }
}
