<?php

declare(strict_types=1);

namespace App\Auth;

use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;

/**
 * Kapselt die TOTP-Bibliothek. 30-Sekunden-Schritte, 6 Ziffern, SHA-1 (Standard der Authenticator-Apps).
 */
final class TotpVerifier
{
    public const PERIOD = 30;

    public function generateSecret(): string
    {
        // 160 Bit (RFC 4226), von allen Authenticator-Apps unterstützt.
        return Base32::encodeUpperUnpadded(random_bytes(20));
    }

    public function provisioningUri(string $secret, string $label, string $issuer): string
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($label);
        $totp->setIssuer($issuer);

        return $totp->getProvisioningUri();
    }

    /**
     * @return int|null der passende Zeitschritt oder null bei falschem Code
     */
    public function verify(string $secret, string $code, int $now): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $totp = TOTP::createFromSecret($secret);
        foreach ([0, -1, 1] as $offset) {
            $timestamp = $now + $offset * self::PERIOD;
            if ($timestamp >= 0 && $totp->verify($code, $timestamp)) {
                return intdiv($timestamp, self::PERIOD);
            }
        }

        return null;
    }

    public function codeAt(string $secret, int $timestamp): string
    {
        return TOTP::createFromSecret($secret)->at($timestamp);
    }
}
