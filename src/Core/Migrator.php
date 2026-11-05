<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Spielt nummerierte SQL-Dateien aus migrations/ nur vorwärts ein.
 */
final class Migrator
{
    public function __construct(private readonly Db $db, private readonly string $dir)
    {
    }

    /** @return list<string> Namen der neu eingespielten Migrationen */
    public function migrate(): array
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'version VARCHAR(190) NOT NULL PRIMARY KEY, '
            . 'applied_at DATETIME NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        $done = array_column($this->db->fetchAll('SELECT version FROM schema_migrations'), 'version');

        $files = glob($this->dir . '/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            foreach ($this->statements((string) file_get_contents($file)) as $statement) {
                $this->db->exec($statement);
            }
            $this->db->execute(
                'INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)',
                [$name, gmdate('Y-m-d H:i:s')],
            );
            $applied[] = $name;
        }

        return $applied;
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
