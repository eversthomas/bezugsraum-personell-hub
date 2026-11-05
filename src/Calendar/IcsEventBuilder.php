<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Core\PlannedEvent;

/** Baut eine .ics-Datei für einen Termin (Rückfallweg für jeden Kalender). */
final class IcsEventBuilder
{
    public function build(PlannedEvent $event, string $uid): string
    {
        $utc = new \DateTimeZone('UTC');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Personal Hub//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $uid . '@personal-hub',
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $event->start->setTimezone($utc)->format('Ymd\THis\Z'),
            'DTEND:' . $event->end->setTimezone($utc)->format('Ymd\THis\Z'),
            'SUMMARY:' . $this->escape($event->title),
        ];
        if ($event->notes !== '') {
            $lines[] = 'DESCRIPTION:' . $this->escape($event->notes);
        }
        array_push($lines, 'TRANSP:OPAQUE', 'END:VEVENT', 'END:VCALENDAR');

        return implode("\r\n", array_map($this->fold(...), $lines)) . "\r\n";
    }

    private function escape(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\\,', '\\n'], $text);
    }

    /** Zeilen höchstens 75 Byte lang, Fortsetzung mit führendem Leerzeichen (RFC 5545), ohne Zeichen zu zerschneiden. */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $current = '';
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > 75) {
                $out .= $current . "\r\n";
                $current = ' ';
            }
            $current .= $char;
        }

        return $out . $current;
    }
}
