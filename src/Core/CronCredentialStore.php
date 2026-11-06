<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Cron-Zugang nur als Hashes, außerhalb der Konfiguration. Die Datei bleibt 0600.
 */
final class CronCredentialStore
{
    public function __construct(private readonly string $path)
    {
    }

    public function save(CronCredentials $credentials): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Das Verzeichnis für den Cron-Zugang fehlt.');
        }
        $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
            'token_hash' => $credentials->tokenHash,
            'basic_user' => $credentials->user,
            'basic_password_hash' => $credentials->passwordHash,
        ], true) . ";\n";
        $tmp = $this->path . '.tmp';
        if (file_put_contents($tmp, $php) === false) {
            throw new \RuntimeException('Der Cron-Zugang konnte nicht geschrieben werden.');
        }
        chmod($tmp, 0600);
        if (!rename($tmp, $this->path)) {
            if (is_file($tmp)) {
                unlink($tmp);
            }
            throw new \RuntimeException('Der Cron-Zugang konnte nicht geschrieben werden.');
        }
        chmod($this->path, 0600);
    }

    /**
     * @return array{token_hash: string, basic_user: string, basic_password_hash: string}|null
     */
    public function read(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->path, true);
        }
        $values = require $this->path;
        if (!is_array($values)) {
            return null;
        }
        foreach (['token_hash', 'basic_user', 'basic_password_hash'] as $key) {
            if (!is_string($values[$key] ?? null)) {
                return null;
            }
        }

        return [
            'token_hash' => $values['token_hash'],
            'basic_user' => $values['basic_user'],
            'basic_password_hash' => $values['basic_password_hash'],
        ];
    }
}
