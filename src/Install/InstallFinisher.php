<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Config;
use App\Core\CronCredentialStore;
use App\Core\Crypto;

/**
 * Schließt die Einrichtung ab. Die Konfigurationsdatei entsteht zuletzt und nur vollständig.
 */
final class InstallFinisher
{
    public function __construct(
        private readonly string $root,
        private readonly InstallActions $actions,
        private readonly InstallCode $code,
    ) {
    }

    /**
     * @param array{host: string, port: int, name: string, user: string, password: string, url: string, timezone: string} $database
     */
    public function finish(array $database, string $username, string $password, string $totpSecret): InstallResult
    {
        $configPath = $this->root . '/config/config.php';
        if (is_file($configPath)) {
            throw new InstallException('Die Einrichtung konnte nicht abgeschlossen werden. Es wurde keine Konfiguration geschrieben.');
        }

        $keyPath = $this->root . '/config/keys/app.key';
        $cronFile = $this->root . '/storage/cron-credentials.php';
        $createdKey = false;
        $createdCron = false;
        $userCreated = false;
        $dbConfig = new Config(['db' => [
            'host' => $database['host'],
            'port' => $database['port'],
            'name' => $database['name'],
            'user' => $database['user'],
            'password' => $database['password'],
            'charset' => 'utf8mb4',
        ]]);

        try {
            if (!is_file($keyPath)) {
                $this->writeKey($keyPath);
                $createdKey = true;
            }
            $this->actions->migrate($dbConfig);
            $vapid = $this->actions->vapid();
            $cron = $this->actions->cron($database['url']);
            (new CronCredentialStore($cronFile))->save($cron);
            $createdCron = true;
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $this->actions->createUser($dbConfig, Crypto::fromFile($keyPath), $username, $hash, $totpSecret);
            $userCreated = true;
            $this->writeConfig($configPath, $database, $vapid);
            $result = new InstallResult($username, $cron->url, $cron->user, $cron->password);
        } catch (\Throwable) {
            if (!is_file($configPath)) {
                if ($userCreated) {
                    try {
                        $this->actions->removeUser($dbConfig, $username);
                    } catch (\Throwable) {
                        // Der Schlüssel wird trotzdem entfernt, damit der nächste Versuch neu verschlüsselt.
                    }
                }
                if ($createdKey && is_file($keyPath)) {
                    unlink($keyPath);
                }
                if ($createdCron && is_file($cronFile)) {
                    unlink($cronFile);
                }
            }

            throw new InstallException('Die Einrichtung konnte nicht abgeschlossen werden. Es wurde keine Konfiguration geschrieben.');
        }

        $this->markInstalled();

        return $result;
    }

    private function writeKey(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Das Schlüsselverzeichnis konnte nicht angelegt werden.');
        }
        if (file_put_contents($path, Crypto::generateKeyHex() . "\n") === false) {
            throw new \RuntimeException('Der Schlüssel konnte nicht geschrieben werden.');
        }
        chmod($path, 0600);
    }

    /**
     * @param array{host: string, port: int, name: string, user: string, password: string, url: string, timezone: string} $database
     * @param array{publicKey: string, privateKey: string} $vapid
     */
    private function writeConfig(string $path, array $database, array $vapid): void
    {
        $marker = '___PH_KEY_' . bin2hex(random_bytes(8)) . '___';
        $values = [
            'db' => [
                'host' => $database['host'],
                'port' => $database['port'],
                'name' => $database['name'],
                'user' => $database['user'],
                'password' => $database['password'],
                'charset' => 'utf8mb4',
            ],
            'app' => [
                'url' => $database['url'],
                'timezone' => $database['timezone'],
                'trust_proxy' => false,
            ],
            'crypto' => [
                'key_path' => $marker,
            ],
            'push' => [
                'allowed_hosts' => [],
            ],
            'vapid' => [
                'subject' => $database['url'],
                'public_key' => $vapid['publicKey'],
                'private_key' => $vapid['privateKey'],
            ],
        ];
        $exported = str_replace(var_export($marker, true), "__DIR__ . '/keys/app.key'", var_export($values, true));
        $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . $exported . ";\n";
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $php) === false) {
            throw new \RuntimeException('Die Konfiguration konnte nicht geschrieben werden.');
        }
        chmod($tmp, 0600);
        if (file_exists($path) || !rename($tmp, $path)) {
            if (is_file($tmp)) {
                unlink($tmp);
            }
            throw new \RuntimeException('Die Konfiguration konnte nicht geschrieben werden.');
        }
        chmod($path, 0600);
    }

    private function markInstalled(): void
    {
        $lock = $this->root . '/storage/install.lock';
        if (is_dir(dirname($lock)) && file_put_contents($lock, "eingerichtet\n") !== false) {
            chmod($lock, 0600);
        }
        $this->code->discard();
    }
}
