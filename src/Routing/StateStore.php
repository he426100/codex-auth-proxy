<?php

declare(strict_types=1);

namespace CodexAuthProxy\Routing;

use RuntimeException;

final class StateStore
{
    /** @param array<string,mixed> $state */
    private function __construct(private array $state, private readonly ?string $path = null)
    {
    }

    public static function memory(): self
    {
        return new self([
            'accounts' => [],
            'sessions' => [],
            'cursor' => 0,
        ]);
    }

    public static function file(string $path): self
    {
        if (!is_file($path)) {
            return new self(['accounts' => [], 'sessions' => [], 'cursor' => 0], $path);
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('State file must be a JSON object: ' . $path);
        }

        return new self($decoded + ['accounts' => [], 'sessions' => [], 'cursor' => 0], $path);
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
