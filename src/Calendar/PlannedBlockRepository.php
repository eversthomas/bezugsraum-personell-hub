<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Core\Db;

final class PlannedBlockRepository
{
    private const FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly Db $db)
    {
    }

    public function create(?int $itemId, string $title, \DateTimeImmutable $start, \DateTimeImmutable $end, string $target, string $now): int
    {
        $utc = new \DateTimeZone('UTC');
        $this->db->execute(
            'INSERT INTO planned_blocks (item_id, title, starts_at, ends_at, target, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$itemId, $title, $start->setTimezone($utc)->format(self::FORMAT), $end->setTimezone($utc)->format(self::FORMAT), $target, $now],
        );

        return $this->db->lastInsertId();
    }

    public function find(int $id): ?PlannedBlock
    {
        $row = $this->db->fetchOne('SELECT * FROM planned_blocks WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /** @return list<PlannedBlock> */
    public function pendingBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $utc = new \DateTimeZone('UTC');
        $rows = $this->db->fetchAll(
            "SELECT * FROM planned_blocks WHERE status = 'pending' AND ends_at > ? AND starts_at < ? ORDER BY starts_at",
            [$from->setTimezone($utc)->format(self::FORMAT), $to->setTimezone($utc)->format(self::FORMAT)],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /** @return list<PlannedBlock> */
    public function allPending(): array
    {
        return array_map($this->hydrate(...), $this->db->fetchAll("SELECT * FROM planned_blocks WHERE status = 'pending' ORDER BY starts_at"));
    }

    public function setStatus(int $id, string $status, string $now): void
    {
        $this->db->execute(
            'UPDATE planned_blocks SET status = ?, confirmed_at = ? WHERE id = ?',
            [$status, $status === 'confirmed' ? $now : null, $id],
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM planned_blocks WHERE id = ?', [$id]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): PlannedBlock
    {
        $utc = new \DateTimeZone('UTC');

        return new PlannedBlock(
            (int) $row['id'],
            $row['item_id'] === null ? null : (int) $row['item_id'],
            (string) $row['title'],
            new \DateTimeImmutable((string) $row['starts_at'], $utc),
            new \DateTimeImmutable((string) $row['ends_at'], $utc),
            (string) $row['target'],
            (string) $row['status'],
        );
    }
}
