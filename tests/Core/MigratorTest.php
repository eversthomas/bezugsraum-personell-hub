<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Config;
use App\Core\Db;
use App\Core\MigrationException;
use App\Core\Migrator;
use Tests\DbTestCase;

final class MigratorTest extends DbTestCase
{
    public function testStatementsAreSplitAndCommentsRemoved(): void
    {
        $migrator = new Migrator($this->db, sys_get_temp_dir());
        $sql = "-- Kommentar; mit Semikolon\nCREATE TABLE a (id INT);\n\nINSERT INTO a VALUES (1);\n";

        self::assertSame(['CREATE TABLE a (id INT)', 'INSERT INTO a VALUES (1)'], $migrator->statements($sql));
    }

    public function testMigrationsRunOnlyOnce(): void
    {
        $migrator = new Migrator($this->db, dirname(__DIR__, 2) . '/migrations');

        self::assertSame([], $migrator->migrate(), 'setUp hat bereits migriert');
        self::assertNotNull($this->db->fetchOne('SELECT version FROM schema_migrations WHERE version = ?', ['001_init.sql']));
    }

    public function testPendingListsOnlyUnappliedFiles(): void
    {
        $dir = sys_get_temp_dir() . '/ph-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/009_probe.sql', "SELECT 1;\n");
        try {
            self::assertSame(['009_probe.sql'], (new Migrator($this->db, $dir))->pending());
            self::assertSame([], (new Migrator($this->db, dirname(__DIR__, 2) . '/migrations'))->pending());
        } finally {
            unlink($dir . '/009_probe.sql');
            rmdir($dir);
        }
    }

    public function testFailedMigrationReportsOnlyTheFilenameAndReleasesTheLock(): void
    {
        $dir = sys_get_temp_dir() . '/ph-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $marker = 'ph_marker_nicht_zeigen';
        file_put_contents($dir . '/009_bad.sql', "CREATE TABLE ph_upd_probe (id INT);\nSELECT {$marker} FROM ph_missing_table;\n");
        $migrator = new Migrator($this->db, $dir, 0);
        try {
            try {
                $migrator->migrate();
                self::fail('Die fehlerhafte Migration hätte abbrechen müssen.');
            } catch (MigrationException $e) {
                self::assertFalse($e->busy);
                self::assertSame('009_bad.sql', $e->filename);
                self::assertSame('009_bad.sql', $e->getMessage());
                self::assertNull($e->getPrevious());
                self::assertStringNotContainsString($marker, (string) $e);
                self::assertStringNotContainsString('SQLSTATE', (string) $e);
                self::assertStringNotContainsString('ph_missing_table', (string) $e);
            }
            self::assertNull($this->db->fetchOne(
                'SELECT version FROM schema_migrations WHERE version = ?',
                ['009_bad.sql'],
            ));
            self::assertSame(1, (int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                ['ph_upd_probe'],
            ));
            self::assertSame(1, $this->lockFromOtherConnection());
        } finally {
            $this->db->exec('DROP TABLE IF EXISTS ph_upd_probe');
            unlink($dir . '/009_bad.sql');
            rmdir($dir);
        }
    }

    public function testAHeldLockIsReportedAsBusyAndDoesNotApplyTheFile(): void
    {
        $dir = sys_get_temp_dir() . '/ph-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/009_wait.sql', "SELECT 1;\n");
        $other = $this->otherConnection();
        $other->fetchValue('SELECT GET_LOCK(?, 0)', [Migrator::LOCK_NAME]);
        try {
            try {
                (new Migrator($this->db, $dir, 0))->migrate();
                self::fail('Die belegte Sperre hätte die Migration abweisen müssen.');
            } catch (MigrationException $e) {
                self::assertTrue($e->busy);
                self::assertSame('', $e->filename);
                self::assertSame('Aktualisierung läuft bereits.', $e->getMessage());
            }
            self::assertNull($this->db->fetchOne(
                'SELECT version FROM schema_migrations WHERE version = ?',
                ['009_wait.sql'],
            ));
        } finally {
            $other->fetchValue('SELECT RELEASE_LOCK(?)', [Migrator::LOCK_NAME]);
            unlink($dir . '/009_wait.sql');
            rmdir($dir);
        }
    }

    private function lockFromOtherConnection(): int
    {
        $other = $this->otherConnection();
        $got = $other->fetchValue('SELECT GET_LOCK(?, 0)', [Migrator::LOCK_NAME]);
        if ($got === 1 || $got === '1') {
            $other->fetchValue('SELECT RELEASE_LOCK(?)', [Migrator::LOCK_NAME]);

            return 1;
        }

        return 0;
    }

    private function otherConnection(): Db
    {
        return Db::connect(new Config(['db' => [
            'host' => getenv('PH_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('PH_TEST_DB_PORT') ?: 3306),
            'name' => getenv('PH_TEST_DB_NAME') ?: 'personal_hub_test',
            'user' => getenv('PH_TEST_DB_USER') ?: 'ph',
            'password' => getenv('PH_TEST_DB_PASSWORD') ?: 'ph',
            'charset' => 'utf8mb4',
        ]]));
    }
}
