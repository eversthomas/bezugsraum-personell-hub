<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Einmalige Cron-Zugangsdaten. Der Installer nutzt dieselbe Erzeugung, ohne die Konfiguration zu schreiben.
 */
final class CronCredentials
{
    public function __construct(
        public readonly string $url,
        public readonly string $user,
        public readonly string $password,
        public readonly string $tokenHash,
        public readonly string $passwordHash,
    ) {
    }

    public static function generate(string $appUrl, string $user = 'cron'): self
    {
        $token = self::urlToken(random_bytes(32));
        $password = self::urlToken(random_bytes(18));

        return new self(
            rtrim($appUrl, '/') . '/cron/' . $token,
            $user,
            $password,
            hash('sha256', $token),
            password_hash($password, PASSWORD_DEFAULT),
        );
    }

    /** Mindestens 32 Byte Zufall, URL-sicher und ohne Füllzeichen. */
    public static function urlToken(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
