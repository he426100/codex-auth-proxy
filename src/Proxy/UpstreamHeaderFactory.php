<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

use CodexAuthProxy\Account\CodexAccount;
use CodexAuthProxy\Codex\CodexProtocol;
use CodexAuthProxy\Codex\CodexRuntimeProfile;

final class UpstreamHeaderFactory
{
    public function __construct(
        private readonly CodexRuntimeProfile $runtimeProfile,
    )
    {
    }

    /**
     * @param array<string,mixed> $downstreamHeaders
     * @return array<string,string>
     */
    public function build(array $downstreamHeaders, CodexAccount $account, string $host, bool $websocket, ?string $httpAccept = null, ?string $turnState = null, ?string $turnMetadata = null, bool $stripSessionAffinity = false): array
    {
        $headers = [];
        foreach ($downstreamHeaders as $key => $value) {
            $lower = strtolower((string) $key);
            if ($this->shouldDropDownstreamHeader($lower)) {
                continue;
            }
            $headers[(string) $key] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        $headers['Host'] = $host;
        $headers['Authorization'] = 'Bearer ' . $account->accessToken();
        $headers['Accept-Encoding'] = 'identity';

        $this->setCodexHeaders($headers, $downstreamHeaders, $account, $websocket, $httpAccept, $turnState, $turnMetadata, $stripSessionAffinity);

        return $headers;
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $downstreamHeaders
     */
    private function setCodexHeaders(array &$headers, array $downstreamHeaders, CodexAccount $account, bool $websocket, ?string $httpAccept, ?string $turnState, ?string $turnMetadata, bool $stripSessionAffinity): void
    {
        $betaFeatures = $this->headerValue($downstreamHeaders, 'x-codex-beta-features');
        if ($betaFeatures === null && $websocket) {
            $betaFeatures = $this->runtimeProfile->betaFeatures;
        }
        if ($betaFeatures !== null && $betaFeatures !== '') {
            $headers['X-Codex-Beta-Features'] = $betaFeatures;
        }

        foreach ([
            'X-Codex-Turn-State' => 'x-codex-turn-state',
            'X-Codex-Turn-Metadata' => 'x-codex-turn-metadata',
            'X-Client-Request-Id' => 'x-client-request-id',
            'X-Responsesapi-Include-Timing-Metrics' => 'x-responsesapi-include-timing-metrics',
            'session_id' => 'session_id',
            'X-Codex-Window-Id' => 'x-codex-window-id',
            'X-OpenAI-Subagent' => 'x-openai-subagent',
            'X-Codex-Parent-Thread-Id' => 'x-codex-parent-thread-id',
            'Version' => 'version',
        ] as $canonical => $source) {
            if ($stripSessionAffinity && in_array($source, [
                'x-codex-turn-state',
                'x-codex-turn-metadata',
                'session_id',
                'x-codex-window-id',
                'x-codex-parent-thread-id',
            ], true)) {
                continue;
            }
            $value = $this->headerValue($downstreamHeaders, $source);
            if ($value === null && $source === 'x-codex-turn-state') {
                $value = $turnState !== null && trim($turnState) !== '' ? trim($turnState) : null;
            }
            if ($source === 'x-codex-turn-metadata' && $turnMetadata !== null && trim($turnMetadata) !== '') {
                $value = trim($turnMetadata);
            }
            if ($value !== null) {
                $headers[$canonical] = $value;
            }
        }

        $headers['Originator'] = $this->headerValue($downstreamHeaders, 'originator') ?? $this->runtimeProfile->originator;
        $headers['ChatGPT-Account-ID'] = $account->accountId();
        $residency = $this->headerValue($downstreamHeaders, 'x-openai-internal-codex-residency') ?? $this->runtimeProfile->residency;
        if ($residency !== '') {
            $headers['x-openai-internal-codex-residency'] = $residency;
        }

        $userAgent = $this->headerValue($downstreamHeaders, 'user-agent') ?? $this->runtimeProfile->userAgent;
        if ($userAgent !== '') {
            $headers['User-Agent'] = $userAgent;
        }

        if ($websocket) {
            $headers['OpenAI-Beta'] = $this->webSocketBetaHeader($this->headerValue($downstreamHeaders, 'openai-beta'));
            return;
        }

        $headers['Content-Type'] = $this->headerValue($downstreamHeaders, 'content-type') ?? 'application/json';
        $headers['Accept'] = $httpAccept ?? 'text/event-stream';
        $headers['Connection'] = 'Keep-Alive';
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

    private function shouldDropDownstreamHeader(string $lower): bool
    {
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
            'cookie',
            'x-api-key',
            'api-key',
            'x-codex-beta-features',
            'x-codex-turn-state',
            'x-codex-turn-metadata',
            'x-client-request-id',
            'x-responsesapi-include-timing-metrics',
            'version',
            'openai-beta',
            'originator',
            'chatgpt-account-id',
            'session_id',
            'x-codex-window-id',
            'x-openai-subagent',
            'x-codex-parent-thread-id',
            'x-openai-internal-codex-residency',
        ], true)) {
            return true;
        }

        foreach (['x-stainless-', 'anthropic-'] as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function webSocketBetaHeader(?string $downstream): string
    {
        if ($downstream !== null && str_contains($downstream, 'responses_websockets=')) {
            return $downstream;
        }

        return CodexProtocol::responsesWebsocketBetaHeader();
    }
}
