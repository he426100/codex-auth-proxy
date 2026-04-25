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

    public function testSnapshotCanSeedIndependentMemoryStore(): void
    {
        $state = StateStore::memory();
        $state->bindSession('thread-1', 'acct-alpha');
        $state->setCooldown('acct-alpha', 1234567890, 'auth');

        $copy = StateStore::fromArray($state->snapshot());
        $copy->bindSession('thread-2', 'acct-beta');

        self::assertSame('acct-alpha', $copy->sessionAccount('thread-1'));
        self::assertSame('acct-beta', $copy->sessionAccount('thread-2'));
        self::assertSame('acct-alpha', $state->sessionAccount('thread-1'));
        self::assertNull($state->sessionAccount('thread-2'));
        self::assertSame(1234567890, $copy->cooldownUntil('acct-alpha'));
        self::assertSame('auth', $copy->cooldownReason('acct-alpha'));
    }

    public function testPersistsStructuredSessionBindingMetadata(): void
    {
        $dir = $this->tempDir('cap-state');
        $path = $dir . '/state.json';
        $state = StateStore::file($path);

        $state->bindSession('thread-1', 'acct-alpha', 'hard_switch', 1234567890);
        $reloaded = StateStore::file($path);

        self::assertSame([
            'account_id' => 'acct-alpha',
            'selection_source' => 'hard_switch',
            'bound_at' => 1234567890,
            'last_seen_at' => 1234567890,
        ], $reloaded->sessionBinding('thread-1'));
        self::assertSame([
            'thread-1' => [
                'account_id' => 'acct-alpha',
                'selection_source' => 'hard_switch',
                'bound_at' => 1234567890,
                'last_seen_at' => 1234567890,
            ],
        ], $reloaded->allSessionBindings());
        self::assertSame(['thread-1' => 'acct-alpha'], $reloaded->allSessionAccounts());
    }

    public function testReadsLegacyStringSessionBindingAsStructuredView(): void
    {
        $state = StateStore::fromArray([
            'accounts' => [],
            'sessions' => [
                'thread-1' => 'acct-alpha',
            ],
            'cursor' => 0,
            'usage' => [],
        ]);

        self::assertSame([
            'account_id' => 'acct-alpha',
            'selection_source' => null,
            'bound_at' => null,
            'last_seen_at' => null,
        ], $state->sessionBinding('thread-1'));
    }

    public function testTouchSessionUpdatesLastSeenWithoutChangingBindingMetadata(): void
    {
        $state = StateStore::memory();
        $state->bindSession('thread-1', 'acct-alpha', 'new_session', 1234567890);
        $state->touchSession('thread-1', 1234567999);

        self::assertSame([
            'account_id' => 'acct-alpha',
            'selection_source' => 'new_session',
            'bound_at' => 1234567890,
            'last_seen_at' => 1234567999,
        ], $state->sessionBinding('thread-1'));
    }

    public function testTouchMissingSessionDoesNotCreateStateFile(): void
    {
        $dir = $this->tempDir('cap-state-touch-missing');
        $path = $dir . '/state.json';
        $state = StateStore::file($path);

        $state->touchSession('missing-thread', 1234567999);

        self::assertFileDoesNotExist($path);
    }

    public function testForgetsSessionBindingAndMetadata(): void
    {
        $state = StateStore::memory();
        $state->bindSession('thread-1', 'acct-alpha', 'new_session', 1234567890);

        self::assertTrue($state->forgetSession('thread-1'));
        self::assertNull($state->sessionBinding('thread-1'));
        self::assertFalse($state->forgetSession('thread-1'));
    }

    public function testPersistsResponseAffinityMappings(): void
    {
        $dir = $this->tempDir('cap-state');
        $path = $dir . '/state.json';
        $state = StateStore::file($path);

        $state->rememberResponseAccount('resp_123', 'acct-beta');
        $reloaded = StateStore::file($path);

        self::assertSame('acct-beta', $reloaded->responseAccount('resp_123'));
        self::assertSame('acct-beta', $reloaded->snapshot()['responses']['resp_123'] ?? null);
    }

    public function testForgetsResponseAffinityOnlyWhenExpectedOwnerMatches(): void
    {
        $dir = $this->tempDir('cap-state');
        $path = $dir . '/state.json';
        $state = StateStore::file($path);

        $state->rememberResponseAccount('resp_123', 'acct-alpha');

        self::assertFalse($state->forgetResponseAccount('resp_123', 'acct-beta'));
        self::assertSame('acct-alpha', StateStore::file($path)->responseAccount('resp_123'));

        self::assertTrue($state->forgetResponseAccount('resp_123', 'acct-alpha'));
        self::assertNull(StateStore::file($path)->responseAccount('resp_123'));
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

        $state->setCooldown('acct-alpha', 1234567890, 'auth', 1234567000);
        $reloaded = StateStore::file($path);
        self::assertSame(1234567890, $reloaded->cooldownUntil('acct-alpha'));
        self::assertSame('auth', $reloaded->cooldownReason('acct-alpha'));
        self::assertSame('auth', $reloaded->lastCooldownReason('acct-alpha'));
        self::assertSame(1234567000, $reloaded->lastCooldownAt('acct-alpha'));

        $state->setCooldownUntil('acct-alpha', 0);
        $cleared = StateStore::file($path);
        self::assertSame(0, $cleared->cooldownUntil('acct-alpha'));
        self::assertNull($cleared->cooldownReason('acct-alpha'));
        self::assertSame('auth', $cleared->lastCooldownReason('acct-alpha'));
        self::assertSame(1234567000, $cleared->lastCooldownAt('acct-alpha'));
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

    public function testWritesMergeResponseAffinityChangesFromOtherProcesses(): void
    {
        $dir = $this->tempDir('cap-state');
        $path = $dir . '/state.json';
        $serveState = StateStore::file($path);
        $serveState->bindSession('thread-1', 'acct-alpha');

        StateStore::file($path)->rememberResponseAccount('resp-prev-beta', 'acct-beta');

        $serveState->setAccountUsageError('acct-alpha', 'upstream unavailable', 1234567999);
        $reloaded = StateStore::file($path);

        self::assertSame('acct-alpha', $reloaded->sessionAccount('thread-1'));
        self::assertSame('acct-beta', $reloaded->responseAccount('resp-prev-beta'));
        self::assertSame('upstream unavailable', $reloaded->accountUsage('acct-alpha')?->error);
    }

    public function testPrunesOldestResponseAffinityMappingsWhenCapacityIsExceeded(): void
    {
        $dir = $this->tempDir('cap-state');
        $path = $dir . '/state.json';
        $state = StateStore::file($path);

        for ($index = 0; $index < 2050; $index++) {
            $state->rememberResponseAccount('resp_' . $index, 'acct-' . ($index % 3));
        }

        $snapshot = StateStore::file($path)->snapshot();
        self::assertCount(2048, $snapshot['responses']);
        self::assertArrayNotHasKey('resp_0', $snapshot['responses']);
        self::assertArrayNotHasKey('resp_1', $snapshot['responses']);
        self::assertSame('acct-1', $snapshot['responses']['resp_2047'] ?? null);
        self::assertSame('acct-2', $snapshot['responses']['resp_2048'] ?? null);
        self::assertSame('acct-0', $snapshot['responses']['resp_2049'] ?? null);
    }

    public function testPruningResponseAffinityMappingsKeepsNewestEntriesAcrossExternalWrites(): void
    {
        $dir = $this->tempDir('cap-state');
        $path = $dir . '/state.json';
        $serveState = StateStore::file($path);

        for ($index = 0; $index < 2048; $index++) {
            $serveState->rememberResponseAccount('resp_' . $index, 'acct-' . ($index % 3));
        }

        StateStore::file($path)->rememberResponseAccount('resp_external', 'acct-external');
        $serveState->rememberResponseAccount('resp_local', 'acct-local');

        $snapshot = StateStore::file($path)->snapshot();
        self::assertCount(2048, $snapshot['responses']);
        self::assertArrayNotHasKey('resp_0', $snapshot['responses']);
        self::assertArrayNotHasKey('resp_1', $snapshot['responses']);
        self::assertSame('acct-external', $snapshot['responses']['resp_external'] ?? null);
        self::assertSame('acct-local', $snapshot['responses']['resp_local'] ?? null);
    }

    public function testPrunesStaleSessionBindingsWhenRetentionWindowIsExceeded(): void
    {
        $dir = $this->tempDir('cap-state');
        $path = $dir . '/state.json';
        $retentionSeconds = 60;
        $now = time();
        $state = StateStore::file($path, $retentionSeconds);

        $state->bindSession('thread-stale', 'acct-alpha', 'new_session', $now - 600, $now - 600);
        $state->bindSession('thread-active', 'acct-beta', 'new_session', $now - 30, $now - 10);

        $snapshot = StateStore::file($path, $retentionSeconds)->snapshot();
        self::assertArrayNotHasKey('thread-stale', $snapshot['sessions']);
        self::assertArrayNotHasKey('thread-stale', $snapshot['session_meta']);
        self::assertSame('acct-beta', $snapshot['sessions']['thread-active'] ?? null);
        self::assertSame('new_session', $snapshot['session_meta']['thread-active']['selection_source'] ?? null);
    }

    public function testPrunesOrphanedStaleSessionMetadataWithoutRemovingLegacyBindings(): void
    {
        $dir = $this->tempDir('cap-state');
        $path = $dir . '/state.json';
        $retentionSeconds = 60;
        $now = time();
        $this->writeJson($path, [
            'accounts' => [],
            'sessions' => [
                'thread-legacy' => 'acct-alpha',
            ],
            'session_meta' => [
                'thread-orphan' => [
                    'selection_source' => 'new_session',
                    'bound_at' => $now - 600,
                    'last_seen_at' => $now - 600,
                ],
            ],
            'responses' => [],
            'cursor' => 0,
            'usage' => [],
        ]);

        StateStore::file($path, $retentionSeconds)->setCooldownUntil('acct-alpha', 1234567890);

        $snapshot = StateStore::file($path, $retentionSeconds)->snapshot();
        self::assertSame('acct-alpha', $snapshot['sessions']['thread-legacy'] ?? null);
        self::assertArrayNotHasKey('thread-legacy', $snapshot['session_meta']);
        self::assertArrayNotHasKey('thread-orphan', $snapshot['session_meta']);
    }

    public function testConsumesCursorAcrossFileBackedInstances(): void
    {
        $dir = $this->tempDir('cap-state');
        $path = $dir . '/state.json';
        $first = StateStore::file($path);
        $second = StateStore::file($path);

        self::assertSame(0, $first->consumeCursor());
        self::assertSame(1, $second->consumeCursor());
        self::assertSame(2, StateStore::file($path)->cursor());
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
