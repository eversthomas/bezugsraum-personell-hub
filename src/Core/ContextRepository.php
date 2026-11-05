<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Kontexte (Dienstlich, Betriebsrat, …) sind gemeinsame Stammdaten aller Module.
 */
final class ContextRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function exists(string $key): bool
    {
        return $this->db->fetchOne('SELECT `key` FROM contexts WHERE `key` = ?', [$key]) !== null;
    }

    /** @return list<array{key: string, label: string, color: string}> */
    public function all(): array
    {
        $result = [];
        foreach ($this->db->fetchAll('SELECT `key`, label, color FROM contexts ORDER BY sort') as $row) {
            $result[] = ['key' => (string) $row['key'], 'label' => (string) $row['label'], 'color' => (string) $row['color']];
        }

        return $result;
    }
}
