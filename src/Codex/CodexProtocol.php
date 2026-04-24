<?php

declare(strict_types=1);

namespace CodexAuthProxy\Codex;

final class CodexProtocol
{
    public const DEFAULT_BACKEND_BASE_URL = 'https://chatgpt.com/backend-api';
    public const DEFAULT_RESPONSES_WEBSOCKET_BETA = 'responses_websockets=2026-02-06';

    public static function defaultBackendBaseUrl(): string
    {
        return self::DEFAULT_BACKEND_BASE_URL;
    }

    public static function defaultUpstreamBaseUrl(): string
    {
        return self::DEFAULT_BACKEND_BASE_URL . '/codex';
    }

    public static function responsesWebsocketBetaHeader(): string
    {
        return self::DEFAULT_RESPONSES_WEBSOCKET_BETA;
    }

    public static function usageEndpoint(string $baseUrl): string
    {
        $base = rtrim(trim($baseUrl), '/');
        $host = parse_url($base, PHP_URL_HOST);
        if (is_string($host) && in_array($host, ['chatgpt.com', 'chat.openai.com'], true) && !str_contains($base, '/backend-api')) {
            $base .= '/backend-api';
        }

        if (str_contains($base, '/backend-api')) {
            return $base . '/wham/usage';
        }

        return $base . '/api/codex/usage';
    }
}
