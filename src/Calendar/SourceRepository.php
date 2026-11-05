<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Core\Db;

final class SourceRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function create(string $name, string $context, string $color, string $urlEncrypted, string $urlHint, int $intervalMin, string $now): int
    {
        $this->db->execute(
            'INSERT INTO calendar_sources (name, context, color, url_encrypted, url_hint, interval_min, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$name, $context, $color, $urlEncrypted, $urlHint, $intervalMin, $now],
        );

        return $this->db->lastInsertId();
    }

    public function find(int $id): ?Source
    {
        $row = $this->db->fetchOne('SELECT * FROM calendar_sources WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /** @return list<Source> */
    public function all(): array
    {
        return array_map($this->hydrate(...), $this->db->fetchAll('SELECT * FROM calendar_sources ORDER BY name'));
    }

    /** @return list<Source> aktive Quellen, deren letzter Versuch länger als ihr Intervall zurückliegt */
    public function due(string $now): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM calendar_sources WHERE active = 1 AND (last_attempt_at IS NULL '
            . 'OR TIMESTAMPDIFF(MINUTE, last_attempt_at, ?) >= interval_min) ORDER BY id',
            [$now],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function updateDetails(int $id, string $name, string $context, string $color, int $intervalMin): void
    {
        $this->db->execute(
            'UPDATE calendar_sources SET name = ?, context = ?, color = ?, interval_min = ? WHERE id = ?',
            [$name, $context, $color, $intervalMin, $id],
        );
    }

    public function replaceUrl(int $id, string $urlEncrypted, string $urlHint): void
    {
        $this->db->execute(
            'UPDATE calendar_sources SET url_encrypted = ?, url_hint = ?, etag = NULL, last_attempt_at = NULL, '
            . 'last_error = NULL WHERE id = ?',
            [$urlEncrypted, $urlHint, $id],
        );
    }

    public function setReminder(int $id, ?int $minutes): void
    {
        $this->db->execute('UPDATE calendar_sources SET remind_before_min = ? WHERE id = ?', [$minutes, $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->execute('UPDATE calendar_sources SET active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    }

    public function markSuccess(int $id, ?string $etag, string $now): void
    {
        $this->db->execute(
            'UPDATE calendar_sources SET etag = ?, last_attempt_at = ?, last_ok_at = ?, last_error = NULL WHERE id = ?',
            [$etag, $now, $now, $id],
        );
    }

    public function markFailure(int $id, string $message, string $now): void
    {
        $this->db->execute(
            'UPDATE calendar_sources SET last_attempt_at = ?, last_error = ? WHERE id = ?',
            [$now, mb_substr($message, 0, 255), $id],
        );
    }

    public function contextExists(string $key): bool
    {
        return $this->db->fetchOne('SELECT `key` FROM contexts WHERE `key` = ?', [$key]) !== null;
    }

    /** @return list<array{key: string, label: string, color: string}> */
    public function contexts(): array
    {
        $result = [];
        foreach ($this->db->fetchAll('SELECT `key`, label, color FROM contexts ORDER BY sort') as $row) {
            $result[] = ['key' => (string) $row['key'], 'label' => (string) $row['label'], 'color' => (string) $row['color']];
        }

        return $result;
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM calendar_sources WHERE id = ?', [$id]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Source
    {
        return new Source(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['context'],
            (string) $row['color'],
            (string) $row['url_encrypted'],
            (string) $row['url_hint'],
            (int) $row['interval_min'],
            (bool) $row['active'],
            $row['etag'] === null ? null : (string) $row['etag'],
            $row['last_attempt_at'] === null ? null : (string) $row['last_attempt_at'],
            $row['last_ok_at'] === null ? null : (string) $row['last_ok_at'],
            $row['last_error'] === null ? null : (string) $row['last_error'],
            $row['remind_before_min'] === null ? null : (int) $row['remind_before_min'],
        );
    }
}
