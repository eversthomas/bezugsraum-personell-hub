<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Config;
use App\Core\CronCredentials;
use App\Core\Crypto;

/**
 * Datenbank- und Schlüsselschritte, die der Abschluss ausführt.
 */
interface InstallActions
{
    public function migrate(Config $config): void;

    public function createUser(Config $config, Crypto $crypto, string $username, string $passwordHash, string $totpSecret): void;

    public function removeUser(Config $config, string $username): void;

    /** @return array{publicKey: string, privateKey: string} */
    public function vapid(): array;

    public function cron(string $appUrl): CronCredentials;
}
