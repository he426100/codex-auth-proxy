<?php

declare(strict_types=1);

namespace CodexAuthProxy;

final class AppMeta
{
    public const NAME = 'codex-auth-proxy';
    public const VERSION = '0.1.0';

    public static function userAgent(): string
    {
        return self::NAME . '/' . self::VERSION;
    }
}
