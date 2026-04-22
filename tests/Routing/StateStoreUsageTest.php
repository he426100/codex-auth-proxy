<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Routing;

use CodexAuthProxy\Routing\StateStore;
use CodexAuthProxy\Tests\TestCase;
use CodexAuthProxy\Usage\CachedAccountUsage;
use CodexAuthProxy\Usage\CachedRateLimitWindow;

final class StateStoreUsageTest extends TestCase
{
    public function testReadsOldStateFilesWithoutUsageNode(): void
    {
        $dir = $this->tempDir('cap-state');
        $path = $dir . '/state.json';
        $this->writeJson($path, [
            'accounts' => [
                'acct-alpha' => ['cooldown_until' => 123],
            ],
            'sessions' => [
                'thread-1' => 'acct-alpha',
            ],
            'cursor' => 7,
        ]);

        $state = StateStore::file($path);

        self::assertNull($state->accountUsage('acct-alpha'));
        self::assertSame([], $state->allAccountUsage());
        self::assertSame('acct-alpha', $state->sessionAccount('thread-1'));
        self::assertSame(123, $state->cooldownUntil('acct-alpha'));
        self::assertSame(7, $state->cursor());
    }

    public function testPersistsAndReloadsCachedAccountUsage(): void
    {
        $dir = $this->tempDir('cap-state');
        $path = $dir . '/state.json';
        $state = StateStore::file($path);
        $usage = new CachedAccountUsage(
            'plus',
            1234567890,
            null,
            new CachedRateLimitWindow(93.0, 7.0, 300, 1776756600),
            new CachedRateLimitWindow(15.0, 85.0, 10080, 1777338600),
        );

        $state->setAccountUsage('acct-alpha', $usage);
        $reloaded = StateStore::file($path)->accountUsage('acct-alpha');

        self::assertNotNull($reloaded);
        self::assertSame('plus', $reloaded->planType);
        self::assertSame(7.0, $reloaded->primary?->leftPercent);
        self::assertSame(85.0, $reloaded->secondary?->leftPercent);
        self::assertNull($reloaded->error);
    }

    public function testPersistsCooldownReasonAndClearsItWhenCooldownIsReset(): void
    {
        $dir = $this->tempDir('cap-state');
        $path = $dir . '/state.json';
        $state = StateStore::file($path);

        $state->setCooldown('acct-alpha', 1234567890, 'auth');
        $reloaded = StateStore::file($path);
        self::assertSame(1234567890, $reloaded->cooldownUntil('acct-alpha'));
        self::assertSame('auth', $reloaded->cooldownReason('acct-alpha'));

        $state->setCooldownUntil('acct-alpha', 0);
        $cleared = StateStore::file($path);
        self::assertSame(0, $cleared->cooldownUntil('acct-alpha'));
        self::assertNull($cleared->cooldownReason('acct-alpha'));
    }

    public function testWritesMergeExternalStateChangesFromOtherProcesses(): void
    {
        $dir = $this->tempDir('cap-state');
        $path = $dir . '/state.json';
        $serveState = StateStore::file($path);
        $serveState->bindSession('thread-1', 'acct-alpha');

        $usage = new CachedAccountUsage(
            'plus',
            1234567890,
            null,
            new CachedRateLimitWindow(93.0, 7.0, 300, 1776756600),
            new CachedRateLimitWindow(15.0, 85.0, 10080, 1777338600),
        );
        StateStore::file($path)->setAccountUsage('acct-alpha', $usage);

        $serveState->bindSession('thread-2', 'acct-beta');
        $reloaded = StateStore::file($path);

        self::assertSame('acct-alpha', $reloaded->sessionAccount('thread-1'));
        self::assertSame('acct-beta', $reloaded->sessionAccount('thread-2'));
        self::assertNotNull($reloaded->accountUsage('acct-alpha'));
        self::assertSame(7.0, $reloaded->accountUsage('acct-alpha')?->primary?->leftPercent);
    }

    public function testSameInstanceReloadsWhenExternalWriterReplacesStateFile(): void
    {
        $dir = $this->tempDir('cap-state');
        $path = $dir . '/state.json';
        $state = StateStore::file($path);

        $state->bindSession('thread-1', 'acct-alpha');
        self::assertSame('acct-alpha', $state->sessionAccount('thread-1'));

        StateStore::file($path)->bindSession('thread-2', 'acct-beta');

        self::assertSame('acct-beta', $state->sessionAccount('thread-2'));
    }

    public function testRestoresHistoricalWindowShapeWithoutLeftPercentOrWindowMinutes(): void
    {
        $dir = $this->tempDir('cap-state');
        $path = $dir . '/state.json';
        $this->writeJson($path, [
            'accounts' => [],
            'sessions' => [],
            'cursor' => 0,
            'usage' => [
                'acct-alpha' => [
                    'plan_type' => 'plus',
                    'checked_at' => 1234567890,
                    'error' => null,
                    'primary' => [
                        'used_percent' => 93.0,
                        'resets_at' => 1776756600,
                    ],
                    'secondary' => [
                        'used_percent' => 15.0,
                    ],
                ],
            ],
        ]);

        $usage = StateStore::file($path)->accountUsage('acct-alpha');

        self::assertNotNull($usage);
        self::assertSame(7.0, $usage->primary?->leftPercent);
        self::assertSame(0, $usage->primary?->windowMinutes);
        self::assertSame(85.0, $usage->secondary?->leftPercent);
        self::assertSame(0, $usage->secondary?->windowMinutes);
    }

    public function testUpdatesOnlyErrorAndCheckedAtForUsageFailures(): void
    {
        $dir = $this->tempDir('cap-state');
        $path = $dir . '/state.json';
        $state = StateStore::file($path);
        $usage = new CachedAccountUsage(
            'plus',
            1234567890,
            null,
            new CachedRateLimitWindow(93.0, 7.0, 300, 1776756600),
            new CachedRateLimitWindow(15.0, 85.0, 10080, 1777338600),
        );

        $state->setAccountUsage('acct-alpha', $usage);
        $state->setAccountUsageError('acct-alpha', 'upstream unavailable', 1234567999);
        $reloaded = StateStore::file($path)->accountUsage('acct-alpha');

        self::assertNotNull($reloaded);
        self::assertSame('plus', $reloaded->planType);
        self::assertSame(1234567999, $reloaded->checkedAt);
        self::assertSame('upstream unavailable', $reloaded->error);
        self::assertSame(7.0, $reloaded->primary?->leftPercent);
        self::assertSame(85.0, $reloaded->secondary?->leftPercent);
    }

    public function testSkipsInvalidUsageEntriesWhileKeepingValidOnes(): void
    {
        $dir = $this->tempDir('cap-state');
        $path = $dir . '/state.json';
        $this->writeJson($path, [
            'accounts' => [],
            'sessions' => [],
            'cursor' => 0,
            'usage' => [
                'acct-good' => [
                    'plan_type' => 'plus',
                    'checked_at' => 1234567890,
                    'error' => null,
                    'primary' => [
                        'used_percent' => 93.0,
                    ],
                ],
                'acct-bad' => [
                    'plan_type' => 'plus',
                    'error' => null,
                ],
            ],
        ]);

        $state = StateStore::file($path);
        $good = $state->accountUsage('acct-good');

        self::assertNotNull($good);
        self::assertSame('plus', $good->planType);
        self::assertSame(7.0, $good->primary?->leftPercent);
        self::assertNull($state->accountUsage('acct-bad'));
        self::assertCount(1, $state->allAccountUsage());
        self::assertSame(['acct-good'], array_keys($state->allAccountUsage()));
    }
}
