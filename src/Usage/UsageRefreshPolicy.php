<?php

declare(strict_types=1);

namespace CodexAuthProxy\Usage;

final class UsageRefreshPolicy
{
    /** @param null|callable(int,int):int $random */
    public function __construct(
        private readonly int $baseIntervalSeconds,
        private readonly mixed $random = null,
    ) {
    }

    public function delayAfterSuccessSeconds(): int
    {
        return $this->withPositiveJitter($this->normalizedBaseInterval(), 0.1);
    }

    public function delayAfterFailureSeconds(int $consecutiveFailures): int
    {
        $base = $this->normalizedBaseInterval();
        $delay = min($base, 30);
        if ($delay <= 0) {
            return 1;
        }

        for ($attempt = 1; $attempt < max(1, $consecutiveFailures); $attempt++) {
            $delay = min($base, $delay * 2);
        }

        return $this->withPositiveJitter($delay, 0.2);
    }

    private function normalizedBaseInterval(): int
    {
        return max(1, $this->baseIntervalSeconds);
    }

    private function withPositiveJitter(int $seconds, float $ratio): int
    {
        if ($seconds <= 1 || $ratio <= 0.0) {
            return $seconds;
        }

        $spread = max(1, (int) floor($seconds * $ratio));

        return $seconds + $this->random(0, $spread);
    }

    private function random(int $min, int $max): int
    {
        if (is_callable($this->random)) {
            return max($min, min($max, (int) ($this->random)($min, $max)));
        }

        return random_int($min, $max);
    }
}
