<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Config;
use App\Core\Db;
use App\Core\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * Basis für Tests mit MariaDB. Verbindung über PH_TEST_DB_* (Standard: lokale Testdatenbank).
 * Ohne erreichbare Datenbank werden die Tests übersprungen.
 */
abstract class DbTestCase extends TestCase
{
    protected Db $db;

    protected function setUp(): void
    {
        parent::setUp();
        $config = new Config(['db' => [
            'host' => getenv('PH_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('PH_TEST_DB_PORT') ?: 3306),
            'name' => getenv('PH_TEST_DB_NAME') ?: 'personal_hub_test',
            'user' => getenv('PH_TEST_DB_USER') ?: 'ph',
            'password' => getenv('PH_TEST_DB_PASSWORD') ?: 'ph',
            'charset' => 'utf8mb4',
        ]]);
        try {
            $this->db = Db::connect($config);
        } catch (\PDOException) {
            // markTestSkipped bricht setUp ab, tearDown läuft trotzdem.
            // Unterklassen dürfen dort nur gesetzte Eigenschaften anfassen.
            $this->markTestSkipped('Testdatenbank nicht erreichbar.');
        }

        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->fetchAll('SHOW TABLES') as $row) {
            $this->db->exec('DROP TABLE `' . (string) array_values($row)[0] . '`');
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
        (new Migrator($this->db, dirname(__DIR__) . '/migrations'))->migrate();
    }
}
