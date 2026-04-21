<?php

declare(strict_types=1);

namespace CodexAuthProxy\Usage;

final class AccountUsage
{
    /**
     * @param array<string,mixed>|null $raw
     */
    public function __construct(
        public readonly string $planType = '',
        public readonly ?RateLimitWindow $primary = null,
        public readonly ?RateLimitWindow $secondary = null,
        public readonly ?array $raw = null,
    ) {
    }
}
