<?php

declare(strict_types=1);

namespace CodexAuthProxy\Codex;

use CodexAuthProxy\AppMeta;
use CodexAuthProxy\Config\AppConfig;

final class CodexRuntimeProfile
{
    public const DEFAULT_BETA_FEATURES = '';
    public const DEFAULT_RESIDENCY = '';

    public function __construct(
        public readonly string $userAgent,
        public readonly string $betaFeatures,
        public readonly string $originator,
        public readonly string $residency,
    ) {
    }

    public static function fromAppConfig(AppConfig $config): self
    {
        return new self(
            $config->codexUserAgent,
            $config->codexBetaFeatures,
            $config->codexOriginator,
            $config->codexResidency,
        );
    }

    public static function defaultUserAgent(): string
    {
        return AppMeta::userAgent();
    }

    public static function defaultBetaFeatures(): string
    {
        return self::DEFAULT_BETA_FEATURES;
    }

    public static function defaultOriginator(): string
    {
        return AppMeta::NAME;
    }

    public static function defaultResidency(): string
    {
        return self::DEFAULT_RESIDENCY;
    }
}
