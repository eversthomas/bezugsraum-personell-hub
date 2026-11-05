<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Core\Db;

final class EventRepository
{
    private const FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Ersetzt alle Termine einer Quelle in einer Transaktion. Schlägt etwas fehl, bleibt der alte Stand.
     *
     * @param list<CalendarEvent> $events
     */
    public function replaceForSource(int $sourceId, array $events, string $now): void
    {
        $this->db->transaction(function () use ($sourceId, $events, $now): void {
            $this->db->execute('DELETE FROM calendar_events WHERE source_id = ?', [$sourceId]);
            foreach ($events as $event) {
                $this->db->execute(
                    'INSERT INTO calendar_events (source_id, uid_hash, starts_at, ends_at, all_day, title, location, fetched_at) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE ends_at = VALUES(ends_at), '
                    . 'all_day = VALUES(all_day), title = VALUES(title), location = VALUES(location), fetched_at = VALUES(fetched_at)',
                    [
                        $sourceId,
                        sha1($event->uid),
                        $event->startsAt->format(self::FORMAT),
                        $event->endsAt->format(self::FORMAT),
                        $event->allDay ? 1 : 0,
                        $event->title,
                        $event->location,
                        $now,
                    ],
                );
            }
        });
    }

    /**
     * Termine, die das Zeitfenster [from, to) berühren, optional nur bestimmter Quellen.
     *
     * @param list<int>|null $sourceIds
     * @return list<array{source_id: int, starts_at: string, ends_at: string, all_day: bool, title: string, location: string}>
     */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to, ?array $sourceIds = null): array
    {
        $sql = 'SELECT e.source_id, e.starts_at, e.ends_at, e.all_day, e.title, e.location FROM calendar_events e '
            . 'JOIN calendar_sources s ON s.id = e.source_id WHERE s.active = 1 AND e.ends_at > ? AND e.starts_at < ?';
        $params = [$from->format(self::FORMAT), $to->format(self::FORMAT)];
        if ($sourceIds !== null) {
            if ($sourceIds === []) {
                return [];
            }
            $sql .= ' AND e.source_id IN (' . implode(',', array_fill(0, count($sourceIds), '?')) . ')';
            array_push($params, ...$sourceIds);
        }
        $sql .= ' ORDER BY e.starts_at, e.id';

        $result = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $result[] = [
                'source_id' => (int) $row['source_id'],
                'starts_at' => (string) $row['starts_at'],
                'ends_at' => (string) $row['ends_at'],
                'all_day' => (bool) $row['all_day'],
                'title' => (string) $row['title'],
                'location' => (string) $row['location'],
            ];
        }

        return $result;
    }

    /**
     * Beansprucht Terminhinweise: Termine aktiver Quellen mit eingestelltem Vorlauf, deren Hinweiszeit erreicht
     * ist und die noch nicht begonnen haben. Jeder Termin wird genau einmal beansprucht.
     *
     * @return list<array{source_id: int, uid_hash: string, starts_at: string, title: string, location: string, source: string, minutes: int}>
     */
    public function claimReminders(\DateTimeImmutable $now): array
    {
        $stamp = $now->format(self::FORMAT);
        $rows = $this->db->fetchAll(
            'SELECT e.source_id, e.uid_hash, e.starts_at, e.title, e.location, s.name AS source, s.remind_before_min AS minutes '
            . 'FROM calendar_events e JOIN calendar_sources s ON s.id = e.source_id '
            . 'WHERE s.active = 1 AND s.remind_before_min IS NOT NULL AND e.all_day = 0 AND e.starts_at > ? '
            . 'AND e.starts_at <= DATE_ADD(?, INTERVAL s.remind_before_min MINUTE) ORDER BY e.starts_at LIMIT 50',
            [$stamp, $stamp],
        );
        $claims = [];
        foreach ($rows as $row) {
            $inserted = $this->db->execute(
                'INSERT IGNORE INTO event_reminders_sent (source_id, uid_hash, starts_at, sent_at) VALUES (?, ?, ?, ?)',
                [$row['source_id'], $row['uid_hash'], $row['starts_at'], $stamp],
            );
            if ($inserted === 1) {
                $claims[] = [
                    'source_id' => (int) $row['source_id'],
                    'uid_hash' => (string) $row['uid_hash'],
                    'starts_at' => (string) $row['starts_at'],
                    'title' => (string) $row['title'],
                    'location' => (string) $row['location'],
                    'source' => (string) $row['source'],
                    'minutes' => (int) $row['minutes'],
                ];
            }
        }

        return $claims;
    }

    public function releaseReminder(int $sourceId, string $uidHash, string $startsAt): void
    {
        $this->db->execute(
            'DELETE FROM event_reminders_sent WHERE source_id = ? AND uid_hash = ? AND starts_at = ?',
            [$sourceId, $uidHash, $startsAt],
        );
    }

    public function purgeReminderLog(\DateTimeImmutable $before): void
    {
        $this->db->execute('DELETE FROM event_reminders_sent WHERE starts_at < ?', [$before->format(self::FORMAT)]);
    }

    public function purgeEndedBefore(\DateTimeImmutable $cutoff): int
    {
        return $this->db->execute('DELETE FROM calendar_events WHERE ends_at < ?', [$cutoff->format(self::FORMAT)]);
    }
}
