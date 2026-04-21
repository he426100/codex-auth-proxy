<?php

declare(strict_types=1);

namespace CodexAuthProxy\OAuth;

use RuntimeException;

final class LoopbackCallbackServer implements CallbackServer
{
    public function waitForCode(string $host, int $port, string $path, string $expectedState, int $timeoutSeconds): AuthorizationCode
    {
        $server = stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
        if (!is_resource($server)) {
            throw new RuntimeException("Failed to listen on {$host}:{$port}: {$errstr} ({$errno})");
        }

        stream_set_timeout($server, $timeoutSeconds);
        $connection = @stream_socket_accept($server, $timeoutSeconds);
        if (!is_resource($connection)) {
            fclose($server);
            throw new RuntimeException('Timed out waiting for OAuth callback');
        }

        $requestLine = fgets($connection);
        $this->drainHeaders($connection);
        if (!is_string($requestLine)) {
            $this->respond($connection, 400, 'Invalid OAuth callback');
            fclose($connection);
            fclose($server);
            throw new RuntimeException('OAuth callback request was empty');
        }

        try {
            $callback = $this->parseRequestLine($requestLine, $path, $expectedState);
            $this->respond($connection, 200, 'Codex Auth Proxy login completed. You can close this tab.');

            return $callback;
        } catch (RuntimeException $exception) {
            $this->respond($connection, 400, $exception->getMessage());
            throw $exception;
        } finally {
            fclose($connection);
            fclose($server);
        }
    }

    /** @param resource $connection */
    private function drainHeaders(mixed $connection): void
    {
        while (($line = fgets($connection)) !== false) {
            if (trim($line) === '') {
                return;
            }
        }
    }

    private function parseRequestLine(string $requestLine, string $expectedPath, string $expectedState): AuthorizationCode
    {
        $parts = explode(' ', trim($requestLine));
        $target = $parts[1] ?? '';
        $path = (string) parse_url($target, PHP_URL_PATH);
        if ($path !== $expectedPath) {
            throw new RuntimeException('Unexpected OAuth callback path: ' . $path);
        }

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
        $error = $query['error'] ?? null;
        if (is_string($error) && $error !== '') {
            throw new RuntimeException('OAuth callback returned error: ' . $error);
        }

        $state = $query['state'] ?? null;
        if (!is_string($state) || !hash_equals($expectedState, $state)) {
            throw new RuntimeException('OAuth callback state mismatch');
        }

        $code = $query['code'] ?? null;
        if (!is_string($code) || trim($code) === '') {
            throw new RuntimeException('OAuth callback missing code');
        }

        return new AuthorizationCode(trim($code), $state);
    }

    /** @param resource $connection */
    private function respond(mixed $connection, int $status, string $message): void
    {
        $reason = $status === 200 ? 'OK' : 'Bad Request';
        $body = '<!doctype html><meta charset="utf-8"><title>Codex Auth Proxy</title><p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        fwrite($connection, "HTTP/1.1 {$status} {$reason}\r\n");
        fwrite($connection, "Content-Type: text/html; charset=utf-8\r\n");
        fwrite($connection, 'Content-Length: ' . strlen($body) . "\r\n");
        fwrite($connection, "Connection: close\r\n\r\n");
        fwrite($connection, $body);
    }
}
