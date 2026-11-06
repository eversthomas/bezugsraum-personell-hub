<?php

declare(strict_types=1);

namespace App\Install;

use App\Auth\UserRepository;
use App\Core\Config;
use App\Core\CronCredentials;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\Migrator;

/**
 * Echte Einrichtungsschritte gegen Datenbank, Migrationen und VAPID.
 */
final class DefaultInstallActions implements InstallActions
{
    public function __construct(private readonly string $root)
    {
    }

    public function migrate(Config $config): void
    {
        (new Migrator(Db::connect($config), $this->root . '/migrations'))->migrate();
    }

    public function createUser(Config $config, Crypto $crypto, string $username, string $passwordHash, string $totpSecret): void
    {
        $users = new UserRepository(Db::connect($config));
        if ($users->findByUsername($username) !== null) {
            throw new \RuntimeException('Der Benutzer existiert bereits.');
        }
        $users->create($username, $passwordHash, $crypto->encrypt($totpSecret), gmdate('Y-m-d H:i:s'));
    }

    public function removeUser(Config $config, string $username): void
    {
        try {
            Db::connect($config)->execute('DELETE FROM users WHERE username = ?', [$username]);
        } catch (\Throwable) {
            // Ohne diesen Benutzer kann ein neuer Versuch denselben Namen nicht anlegen.
        }
    }

    public function vapid(): array
    {
        $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
        $public = $keys['publicKey'] ?? null;
        $private = $keys['privateKey'] ?? null;
        if (!is_string($public) || $public === '' || !is_string($private) || $private === '') {
            throw new \RuntimeException('VAPID-Schlüssel fehlen.');
        }

        return ['publicKey' => $public, 'privateKey' => $private];
    }

    public function cron(string $appUrl): CronCredentials
    {
        return CronCredentials::generate($appUrl);
    }
}
