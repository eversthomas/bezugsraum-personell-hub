<?php

declare(strict_types=1);

namespace Tests\Core;

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
}
