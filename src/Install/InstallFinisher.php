<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Config;
use App\Core\CronCredentialStore;
use App\Core\Crypto;
use App\Core\RestrictedFile;

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
     * @param string $passwordHash bereits gehasht, nicht das Klartextpasswort
     */
    public function finish(array $database, string $username, string $passwordHash, string $totpSecret): InstallResult
    {
        $lock = $this->root . '/storage/install.lock';
        $handle = $this->openLock($lock);
        try {
            return $this->finishWhileLocked($handle, $database, $username, $passwordHash, $totpSecret);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @param array{host: string, port: int, name: string, user: string, password: string, url: string, timezone: string} $database
     */
    private function finishWhileLocked($handle, array $database, string $username, string $passwordHash, string $totpSecret): InstallResult
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
            $this->actions->createUser($dbConfig, Crypto::fromFile($keyPath), $username, $passwordHash, $totpSecret);
            $userCreated = true;
            $this->writeConfig($configPath, $database, $vapid);
            $result = new InstallResult($username, $cron->url, $cron->user, $cron->password);
        } catch (\Throwable) {
            $this->rollback($configPath, $dbConfig, $username, $userCreated, $createdKey, $keyPath, $createdCron, $cronFile);

            throw new InstallException('Die Einrichtung konnte nicht abgeschlossen werden. Es wurde keine Konfiguration geschrieben.');
        }

        $this->markInstalled($handle);

        return $result;
    }

    private function rollback(
        string $configPath,
        Config $dbConfig,
        string $username,
        bool $userCreated,
        bool $createdKey,
        string $keyPath,
        bool $createdCron,
        string $cronFile,
    ): void {
        if (is_file($configPath)) {
            return;
        }
        if ($userCreated) {
            try {
                $this->actions->removeUser($dbConfig, $username);
            } catch (\Throwable) {
                // Der Schlüssel wird trotzdem entfernt, damit der nächste Versuch neu verschlüsselt.
            }
        }
        if (is_file($configPath)) {
            return;
        }
        if ($createdKey && is_file($keyPath)) {
            unlink($keyPath);
        }
        if (is_file($configPath)) {
            return;
        }
        if ($createdCron && is_file($cronFile)) {
            unlink($cronFile);
        }
    }

    private function writeKey(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Das Schlüsselverzeichnis konnte nicht angelegt werden.');
        }
        RestrictedFile::put($path, Crypto::generateKeyHex() . "\n");
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
        RestrictedFile::put($tmp, $php);
        if (file_exists($path) || !rename($tmp, $path)) {
            if (is_file($tmp)) {
                unlink($tmp);
            }
            throw new \RuntimeException('Die Konfiguration konnte nicht geschrieben werden.');
        }
        chmod($path, 0600);
    }

    /** @param resource $handle */
    private function markInstalled($handle): void
    {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, "eingerichtet\n");
        fflush($handle);
        $this->code->discard();
    }

    /** @return resource */
    private function openLock(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new InstallException('Die Einrichtung konnte nicht abgeschlossen werden. Es wurde keine Konfiguration geschrieben.');
        }
        $previous = umask(0077);
        try {
            $handle = fopen($path, 'c+');
        } finally {
            umask($previous);
        }
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new InstallException('Die Einrichtung konnte nicht abgeschlossen werden. Es wurde keine Konfiguration geschrieben.');
        }
        chmod($path, 0600);

        return $handle;
    }
}
