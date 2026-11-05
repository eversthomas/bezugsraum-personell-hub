<?php

declare(strict_types=1);

namespace App\Calendar;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/**
 * Liest ICS mit sabre/vobject und löst Wiederholungen für ein Zeitfenster in Einzeltermine auf.
 */
final class IcsParser
{
    public const MAX_EVENTS = 5000;
    private const MAX_TEXT = 500;

    /**
     * @return list<CalendarEvent> nach Beginn sortiert
     * @throws FetchException wenn der Inhalt kein lesbarer Kalender ist
     */
    public function parse(string $ics, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        try {
            $calendar = Reader::read($ics, Reader::OPTION_FORGIVING);
            if (!$calendar instanceof VCalendar) {
                throw new FetchException('Die Datei ist kein Kalender.');
            }
            $utc = new \DateTimeZone('UTC');
            $expanded = $calendar->expand(
                \DateTime::createFromImmutable($from),
                \DateTime::createFromImmutable($to),
                $utc,
            );
        } catch (FetchException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new FetchException('Der Kalender konnte nicht gelesen werden.');
        }

        $events = [];
        foreach ($expanded->select('VEVENT') as $vevent) {
            if (!$vevent instanceof VEvent) {
                continue;
            }
            $event = $this->convert($vevent);
            if ($event === null || $event->endsAt <= $from || $event->startsAt >= $to) {
                continue;
            }
            $events[] = $event;
            if (count($events) >= self::MAX_EVENTS) {
                break;
            }
        }
        usort($events, static fn (CalendarEvent $a, CalendarEvent $b): int => $a->startsAt <=> $b->startsAt);

        return $events;
    }

    private function convert(VEvent $vevent): ?CalendarEvent
    {
        $status = strtoupper(trim((string) ($vevent->STATUS ?? '')));
        if ($status === 'CANCELLED' || !isset($vevent->DTSTART)) {
            return null;
        }

        $utc = new \DateTimeZone('UTC');
        $allDay = !$vevent->DTSTART->hasTime();
        $start = \DateTimeImmutable::createFromInterface($vevent->DTSTART->getDateTime());
        if ($allDay) {
            $start = new \DateTimeImmutable($start->format('Y-m-d') . ' 00:00:00', $utc);
        } else {
            $start = $start->setTimezone($utc);
        }

        if (isset($vevent->DTEND)) {
            $end = \DateTimeImmutable::createFromInterface($vevent->DTEND->getDateTime());
            $end = $allDay ? new \DateTimeImmutable($end->format('Y-m-d') . ' 00:00:00', $utc) : $end->setTimezone($utc);
        } elseif (isset($vevent->DURATION)) {
            $interval = \Sabre\VObject\DateTimeParser::parseDuration((string) $vevent->DURATION);
            $end = $start->add($interval);
        } else {
            $end = $allDay ? $start->modify('+1 day') : $start;
        }
        if ($end < $start) {
            $end = $start;
        }

        $title = $this->text((string) ($vevent->SUMMARY ?? ''));

        return new CalendarEvent(
            $this->text((string) ($vevent->UID ?? '')),
            $start,
            $end,
            $allDay,
            $title === '' ? '(ohne Titel)' : $title,
            $this->text((string) ($vevent->LOCATION ?? '')),
        );
    }

    private function text(string $value): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $value) ?? '');

        return mb_substr($value, 0, self::MAX_TEXT);
    }
}
