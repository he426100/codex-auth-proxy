<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Support;

use CodexAuthProxy\Support\BasePath;
use CodexAuthProxy\Tests\TestCase;

final class BasePathTest extends TestCase
{
    public function testResolvePrefersExplicitBasePath(): void
    {
        self::assertSame(
            '/tmp/project',
            BasePath::resolve(
                explicitBasePath: '/tmp/project',
                definedBasePath: '/tmp/defined',
                pharPath: '/tmp/build/codex-auth-proxy.phar',
                sourceBasePath: '/tmp/source',
            ),
        );
    }

    public function testResolveFallsBackToPharDirectory(): void
    {
        self::assertSame(
            '/tmp/build',
            BasePath::resolve(
                pharPath: '/tmp/build/codex-auth-proxy.phar',
                sourceBasePath: '/tmp/source',
            ),
        );
    }

    public function testToAbsoluteResolvesRelativePathAgainstBasePath(): void
    {
        self::assertSame(
            '/tmp/project/runtime/logs/app.log',
            BasePath::toAbsolute('/tmp/project', './runtime/logs/app.log'),
        );
    }
}
