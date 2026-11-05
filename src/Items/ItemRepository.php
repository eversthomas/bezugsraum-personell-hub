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

    public function create(string $type, string $title, string $body, ?string $context, ?string $dueDate, ?string $recurrence, string $now): int
    {
        $this->db->execute(
            'INSERT INTO items (type, origin, title, body, context, due_date, recurrence, created_at, updated_at) '
            . "VALUES (?, 'text', ?, ?, ?, ?, ?, ?, ?)",
            [$type, $title, $body, $context, $dueDate, $recurrence, $now, $now],
        );

        return $this->db->lastInsertId();
    }

    public function update(int $id, string $title, string $body, ?string $context, ?string $dueDate, ?string $recurrence, string $now): void
    {
        $this->db->execute(
            'UPDATE items SET title = ?, body = ?, context = ?, due_date = ?, recurrence = ?, updated_at = ? '
            . 'WHERE id = ? AND type IN (\'task\', \'note\')',
            [$title, $body, $context, $dueDate, $recurrence, $now, $id],
        );
    }

    public function markDone(int $id, string $now): void
    {
        $this->db->execute('UPDATE items SET done_at = ?, updated_at = ? WHERE id = ?', [$now, $now, $id]);
    }

    public function reopen(int $id, string $now): void
    {
        $this->db->execute('UPDATE items SET done_at = NULL, updated_at = ? WHERE id = ?', [$now, $id]);
    }

    public function setDue(int $id, string $dueDate, string $now): void
    {
        $this->db->execute('UPDATE items SET due_date = ?, updated_at = ? WHERE id = ?', [$dueDate, $now, $id]);
    }

    /** @return list<Item> offene Aufgaben, optional eines Kontexts */
    public function openTasks(?string $context): array
    {
        $sql = "SELECT * FROM items WHERE type = 'task' AND done_at IS NULL AND archived_at IS NULL";
        $params = [];
        if ($context !== null) {
            $sql .= ' AND context = ?';
            $params[] = $context;
        }
        $sql .= ' ORDER BY due_date IS NULL, due_date, created_at, id';

        return array_map($this->hydrate(...), $this->db->fetchAll($sql, $params));
    }

    /** @return list<Item> */
    public function doneTasks(int $limit = 15): array
    {
        $rows = $this->db->fetchAll(
            "SELECT * FROM items WHERE type = 'task' AND done_at IS NOT NULL AND archived_at IS NULL "
            . 'ORDER BY done_at DESC LIMIT ' . max(1, min(100, $limit)),
        );

        return array_map($this->hydrate(...), $rows);
    }

    /** @return list<Item> */
    public function searchNotes(string $query, ?string $context): array
    {
        $sql = "SELECT * FROM items WHERE type = 'note' AND archived_at IS NULL";
        $params = [];
        if ($query !== '') {
            $like = '%' . addcslashes($query, '\\%_') . '%';
            $sql .= ' AND (title LIKE ? OR body LIKE ?)';
            array_push($params, $like, $like);
        }
        if ($context !== null) {
            $sql .= ' AND context = ?';
            $params[] = $context;
        }
        $sql .= ' ORDER BY updated_at DESC, id DESC LIMIT 300';

        return array_map($this->hydrate(...), $this->db->fetchAll($sql, $params));
    }

    /** @return list<array<string, mixed>> alle Einträge für den Export */
    public function exportAll(): array
    {
        return $this->db->fetchAll('SELECT id, type, origin, title, body, context, due_date, recurrence, done_at, archived_at, created_at, updated_at FROM items ORDER BY id');
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
            $row['recurrence'] === null ? null : (string) $row['recurrence'],
            $row['done_at'] === null ? null : (string) $row['done_at'],
            (string) $row['updated_at'],
        );
    }
}
