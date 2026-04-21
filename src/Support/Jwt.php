<?php

declare(strict_types=1);

namespace CodexAuthProxy\Support;

use InvalidArgumentException;

final class Jwt
{
    /** @return array<string,mixed> */
    public static function payload(string $token): array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException('JWT must contain three non-empty parts');
        }

        $payload = self::base64UrlDecode($parts[1]);
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('JWT payload must be a JSON object');
        }

        return $decoded;
    }

    private static function base64UrlDecode(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding > 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('JWT payload is not valid base64url');
        }

        return $decoded;
    }
}
