<?php

declare(strict_types=1);

namespace CodexAuthProxy\Routing;

final class ErrorClassification
{
    public function __construct(
        private readonly string $type,
        private readonly bool $hardSwitch,
        private readonly int $cooldownUntil,
    ) {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function hardSwitch(): bool
    {
        return $this->hardSwitch;
    }

    public function cooldownUntil(): int
    {
        return $this->cooldownUntil;
    }
}
