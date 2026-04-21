<?php

declare(strict_types=1);

namespace CodexAuthProxy\Account;

final class CodexAccount
{
    public function __construct(
        private readonly string $name,
        private readonly string $accountId,
        private readonly string $email,
        private readonly string $planType,
        private string $idToken,
        private string $accessToken,
        private string $refreshToken,
        private readonly bool $enabled,
        private readonly string $sourcePath = '',
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function accountId(): string
    {
        return $this->accountId;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function planType(): string
    {
        return $this->planType;
    }

    public function idToken(): string
    {
        return $this->idToken;
    }

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function refreshToken(): string
    {
        return $this->refreshToken;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function sourcePath(): string
    {
        return $this->sourcePath;
    }

    public function withName(string $name): self
    {
        return new self(
            $name,
            $this->accountId,
            $this->email,
            $this->planType,
            $this->idToken,
            $this->accessToken,
            $this->refreshToken,
            $this->enabled,
            $this->sourcePath,
        );
    }

    public function withTokens(string $idToken, string $accessToken, string $refreshToken): self
    {
        return new self(
            $this->name,
            $this->accountId,
            $this->email,
            $this->planType,
            $idToken,
            $accessToken,
            $refreshToken,
            $this->enabled,
            $this->sourcePath,
        );
    }
}
