<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected const FIXTURE_EXP = 2_000_000_000;

    /** @param array<string,mixed> $payload */
    protected function makeJwt(array $payload): string
    {
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $encode(['alg' => 'none', 'typ' => 'JWT']) . '.' . $encode($payload) . '.signature';
    }

    /** @param array<string,mixed> $overrides */
    protected function accountFixture(string $name = 'alpha', array $overrides = []): array
    {
        $payload = [
            'iss' => 'https://auth.openai.com',
            'email' => $name . '@example.com',
            'exp' => self::FIXTURE_EXP,
            'https://api.openai.com/auth' => [
                'chatgpt_account_id' => 'acct-' . $name,
                'chatgpt_plan_type' => 'plus',
                'chatgpt_user_id' => 'user-' . $name,
            ],
        ];

        $base = [
            'schema' => 'codex-auth-proxy.account.v1',
            'provider' => 'openai-chatgpt-codex',
            'name' => $name,
            'enabled' => true,
            'tokens' => [
                'id_token' => $this->makeJwt($payload),
                'access_token' => $this->makeJwt($payload),
                'refresh_token' => 'rt_' . $name,
                'account_id' => 'acct-' . $name,
            ],
            'metadata' => [
                'email' => $name . '@example.com',
                'plan_type' => 'plus',
            ],
        ];

        return array_replace_recursive($base, $overrides);
    }

    protected function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create temp dir');
        }

        return $dir;
    }

    /** @param array<string,mixed> $data */
    protected function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException('Failed to write JSON fixture');
        }
    }
}
