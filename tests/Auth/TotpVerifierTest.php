<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\TotpVerifier;
use PHPUnit\Framework\TestCase;

final class TotpVerifierTest extends TestCase
{
    public function testAcceptsCurrentAndAdjacentStepOnly(): void
    {
        $totp = new TotpVerifier();
        $secret = $totp->generateSecret();
        $now = 1_800_000_000;

        self::assertSame(intdiv($now, 30), $totp->verify($secret, $totp->codeAt($secret, $now), $now));
        self::assertNotNull($totp->verify($secret, $totp->codeAt($secret, $now - 30), $now));
        self::assertNotNull($totp->verify($secret, $totp->codeAt($secret, $now + 30), $now));
        self::assertNull($totp->verify($secret, $totp->codeAt($secret, $now - 90), $now));
    }

    public function testRejectsMalformedCodes(): void
    {
        $totp = new TotpVerifier();
        $secret = $totp->generateSecret();

        foreach (['', '12345', '1234567', 'abcdef', '12 34'] as $bad) {
            self::assertNull($totp->verify($secret, $bad, 1_800_000_000));
        }
    }

    public function testSecretLengthAndUri(): void
    {
        $totp = new TotpVerifier();
        $secret = $totp->generateSecret();

        self::assertSame(32, strlen($secret));
        self::assertStringStartsWith('otpauth://totp/', $totp->provisioningUri($secret, 'thomas', 'Personal Hub'));
    }
}
