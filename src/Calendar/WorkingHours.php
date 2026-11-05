<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Arbeitszeiten für die Vorschläge: Wochentage (1 = Montag … 7 = Sonntag), Beginn und Ende in Minuten ab Mitternacht
 * (Zeitzone Europe/Berlin) und Puffer vor und nach Terminen.
 */
final class WorkingHours
{
    /** @param list<int> $days */
    public function __construct(
        public readonly array $days = [1, 2, 3, 4, 5],
        public readonly int $startMinute = 8 * 60,
        public readonly int $endMinute = 17 * 60,
        public readonly int $bufferMinutes = 10,
    ) {
        if ($startMinute < 0 || $endMinute > 24 * 60 || $startMinute >= $endMinute) {
            throw new \InvalidArgumentException('Ungültige Arbeitszeiten.');
        }
        if ($bufferMinutes < 0 || $bufferMinutes > 120) {
            throw new \InvalidArgumentException('Ungültiger Puffer.');
        }
        foreach ($days as $day) {
            if ($day < 1 || $day > 7) {
                throw new \InvalidArgumentException('Ungültiger Wochentag.');
            }
        }
    }
}
