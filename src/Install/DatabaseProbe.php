<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Config;
use App\Core\Db;

/**
 * Prüft einen Datenbankzugang, ohne etwas anzulegen oder zu speichern.
 */
final class DatabaseProbe
{
    /** @var (callable(Config): bool)|null */
    private $connect;

    /** @param (callable(Config): bool)|null $connect */
    public function __construct(?callable $connect = null)
    {
        $this->connect = $connect;
    }

    public function succeeds(string $host, int $port, string $name, string $user, string $password): bool
    {
        $config = new Config(['db' => [
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'password' => $password,
            'charset' => 'utf8mb4',
        ]]);
        try {
            if ($this->connect !== null) {
                return ($this->connect)($config);
            }
            Db::connect($config)->fetchValue('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
