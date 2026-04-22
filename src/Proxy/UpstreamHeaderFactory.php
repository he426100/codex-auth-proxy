<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

use CodexAuthProxy\Account\CodexAccount;

final class UpstreamHeaderFactory
{
    private const WEBSOCKET_BETA = 'responses_websockets=2026-02-06';

    public function __construct(private readonly string $userAgent = '', private readonly string $betaFeatures = '')
    {
    }

    /**
     * @param array<string,mixed> $downstreamHeaders
     * @return array<string,string>
     */
    public function build(array $downstreamHeaders, CodexAccount $account, string $host, bool $websocket, ?string $httpAccept = null): array
    {
        $headers = [];
        foreach ($downstreamHeaders as $key => $value) {
            $lower = strtolower((string) $key);
            if (in_array($lower, [
                'host',
                'authorization',
                'content-length',
                'accept-encoding',
                'connection',
                'upgrade',
                'sec-websocket-key',
                'sec-websocket-version',
                'sec-websocket-extensions',
                'user-agent',
                'accept',
                'content-type',
                'x-codex-beta-features',
                'x-codex-turn-state',
                'x-codex-turn-metadata',
                'x-client-request-id',
                'x-responsesapi-include-timing-metrics',
                'version',
                'openai-beta',
                'originator',
                'chatgpt-account-id',
            ], true)) {
                continue;
            }
            $headers[(string) $key] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        $headers['Host'] = $host;
        $headers['Authorization'] = 'Bearer ' . $account->accessToken();
        $headers['Accept-Encoding'] = 'identity';

        $this->setCodexHeaders($headers, $downstreamHeaders, $account, $websocket, $httpAccept);

        return $headers;
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $downstreamHeaders
     */
    private function setCodexHeaders(array &$headers, array $downstreamHeaders, CodexAccount $account, bool $websocket, ?string $httpAccept): void
    {
        $betaFeatures = $this->headerValue($downstreamHeaders, 'x-codex-beta-features');
        if ($betaFeatures === null && $websocket) {
            $betaFeatures = $this->betaFeatures;
        }
        if ($betaFeatures !== null && $betaFeatures !== '') {
            $headers['X-Codex-Beta-Features'] = $betaFeatures;
        }

        foreach ([
            'X-Codex-Turn-State' => 'x-codex-turn-state',
            'X-Codex-Turn-Metadata' => 'x-codex-turn-metadata',
            'X-Client-Request-Id' => 'x-client-request-id',
            'X-Responsesapi-Include-Timing-Metrics' => 'x-responsesapi-include-timing-metrics',
            'Version' => 'version',
        ] as $canonical => $source) {
            $value = $this->headerValue($downstreamHeaders, $source);
            if ($value !== null) {
                $headers[$canonical] = $value;
            }
        }

        $headers['Originator'] = $this->headerValue($downstreamHeaders, 'originator') ?? 'codex-tui';
        $headers['Chatgpt-Account-Id'] = $account->accountId();

        if ($websocket) {
            $headers['OpenAI-Beta'] = $this->webSocketBetaHeader($this->headerValue($downstreamHeaders, 'openai-beta'));
            return;
        }

        $headers['Content-Type'] = $this->headerValue($downstreamHeaders, 'content-type') ?? 'application/json';
        $headers['Accept'] = $httpAccept ?? 'text/event-stream';
        $headers['Connection'] = 'Keep-Alive';
        if ($this->userAgent !== '') {
            $headers['User-Agent'] = $this->headerValue($downstreamHeaders, 'user-agent') ?? $this->userAgent;
        }
    }

    /** @param array<string,mixed> $headers */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower($key) === strtolower($name)) {
                $headerValue = is_array($value) ? implode(', ', $value) : (string) $value;

                return trim($headerValue) === '' ? null : $headerValue;
            }
        }

        return null;
    }

    private function webSocketBetaHeader(?string $downstream): string
    {
        if ($downstream !== null && str_contains($downstream, 'responses_websockets=')) {
            return $downstream;
        }

        return self::WEBSOCKET_BETA;
    }
}
