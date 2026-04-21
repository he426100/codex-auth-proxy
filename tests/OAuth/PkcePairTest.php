<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\OAuth;

use CodexAuthProxy\OAuth\PkcePair;
use CodexAuthProxy\Tests\TestCase;

final class PkcePairTest extends TestCase
{
    public function testCreatesS256ChallengeFromVerifier(): void
    {
        $pair = PkcePair::fromVerifier('verifier-1');

        self::assertSame('verifier-1', $pair->verifier());
        self::assertSame(rtrim(strtr(base64_encode(hash('sha256', 'verifier-1', true)), '+/', '-_'), '='), $pair->challenge());
    }
}
