<?php

declare(strict_types=1);

namespace Tests\Calendar;

use App\Calendar\CalendarEvent;
use App\Calendar\FetchException;
use App\Calendar\IcsParser;
use PHPUnit\Framework\TestCase;

final class IcsParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/ics/' . $name);
    }

    private function utc(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    /** @param list<CalendarEvent> $events */
    private function find(array $events, string $title): array
    {
        return array_values(array_filter($events, static fn (CalendarEvent $e): bool => $e->title === $title));
    }

    /** @return list<CalendarEvent> */
    private function outlook(): array
    {
        return (new IcsParser())->parse($this->fixture('outlook.ics'), $this->utc('2026-10-01'), $this->utc('2026-12-01'));
    }

    public function testOutlookWindowsTimezoneAndDstChange(): void
    {
        $series = $this->find($this->outlook(), 'Teamsitzung');
        $starts = array_map(static fn (CalendarEvent $e): string => $e->startsAt->format('Y-m-d H:i'), $series);

        // Vor der Zeitumstellung (25.10.) 09:00 MESZ = 07:00 UTC, danach 09:00 MEZ = 08:00 UTC.
        self::assertSame(['2026-10-06 07:00', '2026-10-13 07:00', '2026-10-20 07:00', '2026-10-27 08:00', '2026-11-17 08:00', '2026-11-24 08:00'], $starts);
        self::assertSame('Raum 2.14', $series[0]->location);
        self::assertSame(90, ($series[0]->endsAt->getTimestamp() - $series[0]->startsAt->getTimestamp()) / 60);
    }

    public function testExdateRemovesInstance(): void
    {
        $starts = array_map(static fn (CalendarEvent $e): string => $e->startsAt->format('Y-m-d'), $this->find($this->outlook(), 'Teamsitzung'));

        self::assertNotContains('2026-11-03', $starts);
    }

    public function testRecurrenceOverrideReplacesInstance(): void
    {
        $events = $this->outlook();
        $moved = $this->find($events, 'Teamsitzung (verschoben)');

        self::assertCount(1, $moved);
        self::assertSame('2026-11-10 13:00', $moved[0]->startsAt->format('Y-m-d H:i'));
        self::assertSame('Raum 3.01', $moved[0]->location);
        self::assertNotContains('2026-11-10', array_map(static fn (CalendarEvent $e): string => $e->startsAt->format('Y-m-d'), $this->find($events, 'Teamsitzung')));
    }

    public function testAllDayEventUsesDateBoundaries(): void
    {
        $event = $this->find($this->outlook(), 'Klausurtagung')[0];

        self::assertTrue($event->allDay);
        self::assertSame('2026-11-12 00:00', $event->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-11-14 00:00', $event->endsAt->format('Y-m-d H:i'));
    }

    public function testCancelledAndOutOfWindowEventsAreDropped(): void
    {
        $titles = array_map(static fn (CalendarEvent $e): string => $e->title, $this->outlook());

        self::assertNotContains('Abgesagter Termin', $titles);
        self::assertNotContains('Weit in der Zukunft', $titles);
    }

    public function testDurationAndMissingTitle(): void
    {
        $event = $this->find($this->outlook(), '(ohne Titel)')[0];

        self::assertSame('2026-11-20 08:00', $event->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-11-20 08:45', $event->endsAt->format('Y-m-d H:i'));
    }

    public function testResultIsSortedByStart(): void
    {
        $times = array_map(static fn (CalendarEvent $e): int => $e->startsAt->getTimestamp(), $this->outlook());
        $sorted = $times;
        sort($sorted);

        self::assertSame($sorted, $times);
    }

    public function testGoogleCalendar(): void
    {
        $events = (new IcsParser())->parse($this->fixture('google.ics'), $this->utc('2026-10-01'), $this->utc('2027-02-01'));

        $dentist = $this->find($events, 'Zahnarzt, Kontrolle')[0];
        self::assertSame('2026-11-01 10:00', $dentist->startsAt->format('Y-m-d H:i'));
        self::assertSame('Hauptstr. 1, Musterstadt', $dentist->location);

        // 26.10. 18:00 Berlin: Zeitumstellung war am 25.10., also MEZ = 17:00 UTC.
        $run = $this->find($events, 'Laufen');
        self::assertCount(3, $run);
        self::assertSame('2026-10-26 17:00', $run[0]->startsAt->format('Y-m-d H:i'));

        $nye = $this->find($events, 'Silvester')[0];
        self::assertTrue($nye->allDay);
        self::assertSame('2027-01-01 00:00', $nye->endsAt->format('Y-m-d H:i'));
    }

    public function testWindowIsRespectedForRecurrences(): void
    {
        $events = (new IcsParser())->parse($this->fixture('google.ics'), $this->utc('2026-10-27'), $this->utc('2026-10-28'));

        self::assertCount(1, $this->find($events, 'Laufen'));
    }

    public function testGarbageIsRejectedWithoutLeakingContent(): void
    {
        $this->expectException(FetchException::class);
        (new IcsParser())->parse("BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART:kaputt SECRET\nEND:VCALENDAR", $this->utc('2026-10-01'), $this->utc('2026-12-01'));
    }

    public function testNonCalendarIsRejected(): void
    {
        $this->expectException(FetchException::class);
        (new IcsParser())->parse('<html>nein</html>', $this->utc('2026-10-01'), $this->utc('2026-12-01'));
    }
}
