<?php

declare(strict_types=1);

namespace CodexAuthProxy\OAuth;

final class PkcePair
{
    public function __construct(private readonly string $verifier, private readonly string $challenge)
    {
    }

    public static function generate(): self
    {
        return self::fromVerifier(self::base64Url(random_bytes(64)));
    }

    public static function fromVerifier(string $verifier): self
    {
        return new self($verifier, self::base64Url(hash('sha256', $verifier, true)));
    }

    public function verifier(): string
    {
        return $this->verifier;
    }

    public function challenge(): string
    {
        return $this->challenge;
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
