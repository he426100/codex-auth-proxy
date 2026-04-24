<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests;

use CodexAuthProxy\Routing\ErrorClassifier;

final class ErrorClassifierTest extends TestCase
{
    public function testDoesNotTreatSuccessfulCompactionPayloadAsAuthFailureFromOutputText(): void
    {
        $body = json_encode([
            'id' => 'resp_1',
            'object' => 'response.compaction',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'Please explain why the previous request looked unauthorized.',
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $classification = (new ErrorClassifier())->classify(200, $body, [], 1000);

        self::assertSame('none', $classification->type());
        self::assertFalse($classification->hardSwitch());
    }

    public function testClassifiesQuotaResponsesAsHardSwitchErrors(): void
    {
        $classification = (new ErrorClassifier())->classify(429, '{"error":{"code":"quota_exceeded","message":"Over limit"}}', [], 1000);

        self::assertSame('quota', $classification->type());
        self::assertTrue($classification->hardSwitch());
        self::assertGreaterThan(1000, $classification->cooldownUntil());
    }

    public function testUsesRetryAfterHeadersAsCooldownHints(): void
    {
        $classification = (new ErrorClassifier())->classify(429, '{"error":{"code":"rate_limit_exceeded"}}', ['retry-after' => '120'], 1000);

        self::assertSame('quota', $classification->type());
        self::assertSame(1120, $classification->cooldownUntil());
    }

    public function testClassifiesAuthFailuresAsHardSwitchErrors(): void
    {
        $classification = (new ErrorClassifier())->classify(401, '{"error":{"code":"invalid_token"}}', [], 1000);

        self::assertSame('auth', $classification->type());
        self::assertTrue($classification->hardSwitch());
        self::assertSame(2800, $classification->cooldownUntil());
    }

    public function testUsesCodexUsageLimitResetEpochAsCooldownHint(): void
    {
        $classification = (new ErrorClassifier())->classify(429, '{"error":{"type":"usage_limit_reached","resets_at":1600}}', [], 1000);

        self::assertSame('quota', $classification->type());
        self::assertSame(1600, $classification->cooldownUntil());
    }

    public function testUsesCodexUsageLimitResetSecondsAsCooldownHint(): void
    {
        $classification = (new ErrorClassifier())->classify(429, '{"error":{"type":"usage_limit_reached","resets_in_seconds":300}}', [], 1000);

        self::assertSame('quota', $classification->type());
        self::assertSame(1300, $classification->cooldownUntil());
    }

    public function testKeepsServerErrorsAsTransientErrors(): void
    {
        $classification = (new ErrorClassifier())->classify(502, 'bad gateway', [], 1000);

        self::assertSame('transient', $classification->type());
        self::assertFalse($classification->hardSwitch());
    }

    public function testClassifiesExplicitErrorPayloadWithoutHttpStatus(): void
    {
        $payload = '{"type":"error","error":{"message":"Your authentication token has been invalidated.","type":"invalid_request_error","code":"token_invalidated"}}';

        $classification = (new ErrorClassifier())->classifyErrorPayload($payload, 1000);

        self::assertSame('auth', $classification->type());
        self::assertTrue($classification->hardSwitch());
        self::assertSame(2800, $classification->cooldownUntil());
    }

    public function testClassifiesExplicitQuotaPayloadReturnedWithHttp200(): void
    {
        $payload = '{"type":"error","error":{"code":"usage_limit_reached","message":"too many requests","resets_in_seconds":300}}';

        $classification = (new ErrorClassifier())->classify(200, $payload, [], 1000);

        self::assertSame('quota', $classification->type());
        self::assertTrue($classification->hardSwitch());
        self::assertSame(1300, $classification->cooldownUntil());
    }

    public function testClassifiesPreviousResponseNotFoundAsLineageErrorWithoutHardSwitch(): void
    {
        $payload = '{"type":"error","error":{"type":"invalid_request_error","code":"previous_response_not_found","message":"Previous response not found.","param":"previous_response_id"},"status":400}';

        $classification = (new ErrorClassifier())->classify(400, $payload, [], 1000);

        self::assertSame('lineage', $classification->type());
        self::assertFalse($classification->hardSwitch());
        self::assertSame(0, $classification->cooldownUntil());
    }
}
