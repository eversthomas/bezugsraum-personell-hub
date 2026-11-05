<?php

declare(strict_types=1);

namespace App\Items;

use App\Core\Db;

final class ItemRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function insert(string $origin, string $title, string $body, ?string $clientUuid, string $now): int
    {
        $this->db->execute(
            'INSERT INTO items (type, origin, title, body, client_uuid, created_at, updated_at) '
            . "VALUES ('inbox', ?, ?, ?, ?, ?, ?)",
            [$origin, $title, $body, $clientUuid, $now, $now],
        );

        return $this->db->lastInsertId();
    }

    public function findByClientUuid(string $uuid): ?Item
    {
        $row = $this->db->fetchOne('SELECT * FROM items WHERE client_uuid = ?', [$uuid]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function find(int $id): ?Item
    {
        $row = $this->db->fetchOne('SELECT * FROM items WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /** @return list<Item> */
    public function byType(string $type, int $limit = 200): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM items WHERE type = ? AND archived_at IS NULL ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(1000, $limit)),
            [$type],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function countByType(string $type): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM items WHERE type = ? AND archived_at IS NULL', [$type]);
    }

    public function sort(int $id, string $type, ?string $context, ?string $dueDate, string $now): bool
    {
        return $this->db->execute(
            'UPDATE items SET type = ?, context = ?, due_date = ?, updated_at = ? WHERE id = ?',
            [$type, $context, $dueDate, $now, $id],
        ) === 1;
    }

    public function delete(int $id): bool
    {
        return $this->db->execute('DELETE FROM items WHERE id = ?', [$id]) === 1;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Item
    {
        return new Item(
            (int) $row['id'],
            (string) $row['type'],
            (string) $row['origin'],
            (string) $row['title'],
            (string) $row['body'],
            $row['context'] === null ? null : (string) $row['context'],
            $row['due_date'] === null ? null : (string) $row['due_date'],
            (string) $row['created_at'],
        );
    }
}
