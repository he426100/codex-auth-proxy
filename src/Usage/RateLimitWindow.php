<?php

declare(strict_types=1);

namespace CodexAuthProxy\Usage;

final class RateLimitWindow
{
    public function __construct(
        public readonly float $usedPercent,
        public readonly int $windowMinutes,
        public readonly ?int $resetsAt,
    ) {
    }

    public function leftPercent(): float
    {
        return max(0.0, min(100.0, 100.0 - $this->usedPercent));
    }
}
