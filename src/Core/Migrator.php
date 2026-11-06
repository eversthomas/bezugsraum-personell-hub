<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Spielt nummerierte SQL-Dateien aus migrations/ nur vorwärts ein.
 */
final class Migrator implements MigratorInterface
{
    public const LOCK_NAME = 'personal_hub_migrations';

    public function __construct(
        private readonly Db $db,
        private readonly string $dir,
        private readonly int $lockTimeoutSeconds = 10,
    ) {
    }

    public function pending(): array
    {
        $files = $this->fileNames();
        if (!$this->hasTable()) {
            return $files;
        }
        $done = array_column($this->db->fetchAll('SELECT version FROM schema_migrations'), 'version');

        return array_values(array_filter(
            $files,
            static fn (string $name): bool => !in_array($name, $done, true),
        ));
    }

    /**
     * @return list<string> Namen der neu eingespielten Migrationen
     * @throws MigrationException
     */
    public function migrate(): array
    {
        if (!$this->acquireLock()) {
            throw MigrationException::busy();
        }
        try {
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations ('
                . 'version VARCHAR(190) NOT NULL PRIMARY KEY, '
                . 'applied_at DATETIME NOT NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            );
            $done = array_column($this->db->fetchAll('SELECT version FROM schema_migrations'), 'version');

            $applied = [];
            foreach ($this->fileNames() as $name) {
                if (in_array($name, $done, true)) {
                    continue;
                }
                $this->apply($name);
                $applied[] = $name;
            }

            return $applied;
        } finally {
            $this->releaseLock();
        }
    }

    /** @return list<string> */
    private function fileNames(): array
    {
        $files = glob($this->dir . '/*.sql') ?: [];
        sort($files);

        return array_map(static fn (string $file): string => basename($file), $files);
    }

    private function apply(string $name): void
    {
        $file = $this->dir . '/' . $name;
        try {
            foreach ($this->statements((string) file_get_contents($file)) as $statement) {
                $this->db->exec($statement);
            }
            $this->db->execute(
                'INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)',
                [$name, gmdate('Y-m-d H:i:s')],
            );
        } catch (\Throwable) {
            throw MigrationException::failed($name);
        }
    }

    private function acquireLock(): bool
    {
        $got = $this->db->fetchValue('SELECT GET_LOCK(?, ?)', [self::LOCK_NAME, $this->lockTimeoutSeconds]);

        return $got === 1 || $got === '1';
    }

    private function releaseLock(): void
    {
        try {
            $this->db->fetchValue('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        } catch (\Throwable) {
            // Die Verbindung gibt die Sperre beim Schließen frei.
        }
    }

    private function hasTable(): bool
    {
        $found = $this->db->fetchValue(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            ['schema_migrations'],
        );

        return (int) $found > 0;
    }

    /** @return list<string> */
    public function statements(string $sql): array
    {
        $lines = [];
        foreach (explode("\n", $sql) as $line) {
            if (!str_starts_with(ltrim($line), '--')) {
                $lines[] = $line;
            }
        }
        $parts = preg_split('/;\s*(?:\n|$)/', implode("\n", $lines)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }
}
