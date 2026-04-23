<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

final class SessionKeyExtractor
{
    public function extract(array $headers, string $body): SessionKey
    {
        return $this->extractWithPriority(
            $headers,
            $body,
            ['x-codex-turn-state', 'session_id', 'conversation_id', 'x-session-id', 'x-codex-session-id', 'x-codex-thread-id', 'openai-conversation-id', 'idempotency-key'],
            ['conversation_id', 'thread_id', 'session_id', 'previous_response_id', 'prompt_cache_key'],
        );
    }

    public function extractExecutionSession(array $headers, string $body): SessionKey
    {
        return $this->extractWithPriority(
            $headers,
            $body,
            ['session_id', 'conversation_id', 'x-session-id', 'x-codex-session-id', 'x-codex-thread-id', 'openai-conversation-id', 'idempotency-key', 'x-codex-turn-state'],
            ['conversation_id', 'thread_id', 'session_id', 'previous_response_id', 'prompt_cache_key'],
        );
    }

    /**
     * @param list<string> $headerKeys
     * @param list<string> $bodyKeys
     */
    private function extractWithPriority(array $headers, string $body, array $headerKeys, array $bodyKeys): SessionKey
    {
        $headers = $this->normalizeHeaders($headers);
        foreach ($headerKeys as $header) {
            $value = $headers[$header] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return new SessionKey($header . ':' . trim($value));
            }
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return new SessionKey('global');
        }

        foreach ($bodyKeys as $key) {
            $value = $decoded[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return new SessionKey($key . ':' . trim($value));
            }
        }

        $metadataUserId = $decoded['metadata']['user_id'] ?? null;
        if (is_string($metadataUserId) && trim($metadataUserId) !== '') {
            $metadataUserId = trim($metadataUserId);
            if (preg_match('/_session_([a-f0-9-]+)$/', $metadataUserId, $matches) === 1) {
                return new SessionKey('metadata.session_id:' . $matches[1]);
            }

            $nested = json_decode($metadataUserId, true);
            if (is_array($nested) && is_string($nested['session_id'] ?? null) && trim($nested['session_id']) !== '') {
                return new SessionKey('metadata.session_id:' . trim($nested['session_id']));
            }

            return new SessionKey('metadata.user_id:' . $metadataUserId);
        }

        [$primary, $fallback] = $this->messageHash($decoded);
        if ($primary !== null) {
            return new SessionKey($primary, $fallback);
        }

        return new SessionKey('global');
    }

    /** @param array<string,mixed> $headers */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower((string) $key)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return $normalized;
    }

    /** @param array<string,mixed> $payload */
    private function messageHash(array $payload): array
    {
        $system = $this->optionalString($payload['instructions'] ?? null);
        $user = '';
        $assistant = '';

        if (is_array($payload['messages'] ?? null)) {
            foreach ($payload['messages'] as $message) {
                if (!is_array($message)) {
                    continue;
                }
                $role = $this->optionalString($message['role'] ?? null);
                $content = $this->extractContentText($message['content'] ?? null);
                if ($content === '') {
                    continue;
                }
                if ($role === 'system' && $system === '') {
                    $system = $this->truncate($content);
                } elseif ($role === 'user' && $user === '') {
                    $user = $this->truncate($content);
                } elseif ($role === 'assistant' && $assistant === '') {
                    $assistant = $this->truncate($content);
                }
            }
        }

        if (is_array($payload['input'] ?? null)) {
            foreach ($payload['input'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $type = $this->optionalString($item['type'] ?? null);
                $role = $this->optionalString($item['role'] ?? null);
                if ($type !== '' && $type !== 'message') {
                    continue;
                }
                $content = $this->extractContentText($item['content'] ?? null);
                if ($content === '') {
                    continue;
                }
                if (($role === 'developer' || $role === 'system') && $system === '') {
                    $system = $this->truncate($content);
                } elseif ($role === 'user' && $user === '') {
                    $user = $this->truncate($content);
                } elseif ($role === 'assistant' && $assistant === '') {
                    $assistant = $this->truncate($content);
                }
            }
        }

        if ($system === '' && $user === '') {
            return [null, null];
        }

        $fallback = $this->computeHash($system, $user, '');
        if ($assistant === '') {
            return [$fallback, null];
        }

        return [$this->computeHash($system, $user, $assistant), $fallback];
    }

    private function extractContentText(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        if (!is_array($content)) {
            return '';
        }

        $texts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $texts[] = trim($part);
                continue;
            }
            if (!is_array($part)) {
                continue;
            }
            $type = $this->optionalString($part['type'] ?? null);
            if (in_array($type, ['text', 'input_text', 'output_text'], true) && is_string($part['text'] ?? null)) {
                $texts[] = trim($part['text']);
            }
        }

        return trim(implode(' ', array_filter($texts, static fn (string $text): bool => $text !== '')));
    }

    private function computeHash(string $system, string $user, string $assistant): string
    {
        return 'msg:' . substr(hash('sha256', "sys:{$system}\nusr:{$user}\nast:{$assistant}\n"), 0, 16);
    }

    private function truncate(string $value): string
    {
        return strlen($value) > 100 ? substr($value, 0, 100) : $value;
    }

    private function optionalString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
