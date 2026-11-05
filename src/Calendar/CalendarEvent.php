<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Ein einzelner Termin (eine Wiederholung ist ein eigener Termin). Zeiten in UTC.
 * Ganztägige Termine: Beginn und Ende sind Datumsgrenzen (00:00 UTC), das Ende ist exklusiv.
 */
final class CalendarEvent
{
    public function __construct(
        public readonly string $uid,
        public readonly \DateTimeImmutable $startsAt,
        public readonly \DateTimeImmutable $endsAt,
        public readonly bool $allDay,
        public readonly string $title,
        public readonly string $location,
    ) {
    }
}
