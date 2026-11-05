<?php

declare(strict_types=1);

namespace Tests\Calendar;

use App\Calendar\CalendarEvent;
use App\Calendar\EventRepository;
use App\Calendar\GoogleCalendarLinkBuilder;
use App\Calendar\OutlookWebLinkBuilder;
use App\Calendar\PlannedBlockRepository;
use App\Calendar\PlanningService;
use App\Calendar\PlanningSettings;
use App\Calendar\SourceRepository;
use App\Core\SettingsRepository;
use Tests\DbTestCase;

final class PlanningServiceTest extends DbTestCase
{
    private int $clock;
    private PlanningService $service;
    private PlanningSettings $settings;
    private EventRepository $events;
    private int $sourceId;

    protected function setUp(): void
    {
        parent::setUp();
        // Montag 9.11.2026, 06:30 Berlin
        $this->clock = (int) (new \DateTimeImmutable('2026-11-09 06:30', new \DateTimeZone('Europe/Berlin')))->format('U');
        $this->events = new EventRepository($this->db);
        $this->settings = new PlanningSettings(new SettingsRepository($this->db));
        $this->service = new PlanningService(
            $this->settings,
            $this->events,
            new PlannedBlockRepository($this->db),
            ['outlook' => new OutlookWebLinkBuilder(), 'google' => new GoogleCalendarLinkBuilder()],
            clock: fn (): int => $this->clock,
        );
        $this->sourceId = (new SourceRepository($this->db))->create('AWO', 'dienstlich', '#2563EB', 'x', 'h', 15, '2026-11-01 00:00:00');
    }

    private function berlin(string $local): \DateTimeImmutable
    {
        return new \DateTimeImmutable($local, new \DateTimeZone('Europe/Berlin'));
    }

    private function addEvent(string $from, string $to, string $title = 'Termin', bool $allDay = false): void
    {
        $utc = new \DateTimeZone('UTC');
        $this->events->replaceForSource($this->sourceId, [
            ...$this->currentEvents(),
            new CalendarEvent('uid-' . $from, $this->berlin($from)->setTimezone($utc), $this->berlin($to)->setTimezone($utc), $allDay, $title, ''),
        ], '2026-11-09 05:00:00');
    }

    /** @return list<CalendarEvent> */
    private function currentEvents(): array
    {
        $utc = new \DateTimeZone('UTC');
        $out = [];
        foreach ($this->db->fetchAll('SELECT * FROM calendar_events') as $row) {
            $out[] = new CalendarEvent((string) $row['uid_hash'], new \DateTimeImmutable((string) $row['starts_at'], $utc), new \DateTimeImmutable((string) $row['ends_at'], $utc), (bool) $row['all_day'], (string) $row['title'], '');
        }

        return $out;
    }

    /** @param list<\App\Calendar\Slot> $slots @return list<string> */
    private function fmt(array $slots): array
    {
        return array_map(static fn ($s): string => $s->start->format('D H:i') . '-' . $s->end->format('H:i'), $slots);
    }

    public function testSuggestionsAvoidEventsOfAllSources(): void
    {
        $this->addEvent('2026-11-09 08:00', '2026-11-09 12:00');
        $second = (new SourceRepository($this->db))->create('Privat', 'privat', '#15803D', 'x', 'h', 15, '2026-11-01 00:00:00');
        $this->db->execute("INSERT INTO calendar_events (source_id, uid_hash, starts_at, ends_at, all_day, title, location, fetched_at) VALUES (?, 'b', ?, ?, 0, 'Zahnarzt', '', NOW())", [
            $second, $this->berlin('2026-11-09 13:00')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $this->berlin('2026-11-09 15:00')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);

        $slots = $this->service->suggestions(30, 1);

        self::assertSame('Mon 12:15-12:45', $this->fmt($slots)[0], 'Puffer von 10 Minuten nach dem ersten, vor dem zweiten Termin');
        self::assertSame('Mon 15:15-15:45', $this->fmt($slots)[1], 'Termine der zweiten Quelle sperren ebenfalls');
        self::assertSame('Mon 15:15-16:15', $this->fmt($this->service->suggestions(60, 1))[0], 'eine Stunde passt nicht in die Lücke');
    }

    public function testAllDayEventsDoNotBlockTheDay(): void
    {
        $this->addEvent('2026-11-09 00:00', '2026-11-10 00:00', 'Geburtstag', true);

        self::assertNotEmpty($this->service->suggestions(60, 1));
    }

    public function testPausedSourceDoesNotBlock(): void
    {
        $this->addEvent('2026-11-09 08:00', '2026-11-09 17:00');
        (new SourceRepository($this->db))->setActive($this->sourceId, false);

        self::assertSame('Mon 08:00-09:00', $this->fmt($this->service->suggestions(60, 1))[0]);
    }

    public function testReservedBlockIsNotSuggestedAgain(): void
    {
        $first = $this->service->suggestions(60, 1)[0];
        $this->service->reserve('Fokuszeit', $first->start, $first->end, 'outlook');

        $next = $this->service->suggestions(60, 1)[0];
        self::assertGreaterThanOrEqual($first->end->getTimestamp() + 600, $next->start->getTimestamp(), 'mindestens Puffer Abstand');
    }

    public function testReserveValidation(): void
    {
        $start = $this->berlin('2026-11-10 09:00');
        $cases = [
            ['', $start, $start->modify('+1 hour'), 'outlook'],
            ['X', $start, $start->modify('+1 hour'), 'fax'],
            ['X', $start, $start->modify('+1 minute'), 'outlook'],
            ['X', $start, $start->modify('+9 hours'), 'outlook'],
            ['X', $this->berlin('2026-11-01 09:00'), $this->berlin('2026-11-01 10:00'), 'outlook'],
            ['X', $this->berlin('2027-06-01 09:00'), $this->berlin('2027-06-01 10:00'), 'outlook'],
            [str_repeat('a', 201), $start, $start->modify('+1 hour'), 'outlook'],
        ];
        foreach ($cases as $args) {
            try {
                $this->service->reserve(...$args);
                self::fail('Erwartet InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM planned_blocks'));
    }

    public function testLinkAndIcsForBlock(): void
    {
        $id = $this->service->reserve('Fokuszeit', $this->berlin('2026-11-10 09:00'), $this->berlin('2026-11-10 10:00'), 'outlook');
        $block = $this->service->find($id);
        self::assertNotNull($block);

        self::assertStringContainsString('deeplink/compose', (string) $this->service->linkFor($block));
        self::assertStringContainsString('DTSTART:20261110T080000Z', $this->service->icsFor($block));

        $icsId = $this->service->reserve('Nur Datei', $this->berlin('2026-11-10 11:00'), $this->berlin('2026-11-10 12:00'), 'ics');
        self::assertNull($this->service->linkFor($this->service->find($icsId) ?? throw new \LogicException()), 'ics hat keinen Link');
    }

    public function testBlockIsConfirmedAfterEventAppearsAndExpiresOtherwise(): void
    {
        $id = $this->service->reserve('Fokuszeit', $this->berlin('2026-11-10 09:00'), $this->berlin('2026-11-10 10:00'), 'outlook');
        $old = $this->service->reserve('Nie angelegt', $this->berlin('2026-11-09 08:00'), $this->berlin('2026-11-09 09:00'), 'outlook');

        self::assertSame(0, $this->service->reconcile(), 'noch kein Termin im Kalender');
        self::assertSame('pending', $this->service->find($id)?->status);

        $this->addEvent('2026-11-10 09:00', '2026-11-10 10:00', 'Fokuszeit');
        self::assertSame(1, $this->service->reconcile());
        self::assertSame('confirmed', $this->service->find($id)?->status);
        self::assertNotNull($this->db->fetchValue('SELECT confirmed_at FROM planned_blocks WHERE id = ?', [$id]));

        $this->clock += 3 * 86400;
        $this->service->reconcile();
        self::assertSame('expired', $this->service->find($old)?->status);
        self::assertSame([], $this->service->pendingBetween($this->berlin('2026-11-01'), $this->berlin('2026-12-01')));
    }

    public function testConfirmationWorksWhenTitleIsMasked(): void
    {
        $id = $this->service->reserve('Fokuszeit', $this->berlin('2026-11-10 09:00'), $this->berlin('2026-11-10 10:00'), 'outlook');
        $this->addEvent('2026-11-10 09:00', '2026-11-10 10:00', 'Beschäftigt');

        self::assertSame(1, $this->service->reconcile());
        self::assertSame('confirmed', $this->service->find($id)?->status);
    }

    public function testSettingsDefaultsAndPersistence(): void
    {
        self::assertSame('Fokuszeit', $this->settings->defaultTitle());
        self::assertSame('google', $this->settings->targetFor('privat'));
        self::assertSame('outlook', $this->settings->targetFor('betriebsrat'));
        self::assertSame('ics', $this->settings->targetFor(null));

        $this->settings->save([1, 2, 3], 9 * 60, 16 * 60, 5, 'Blockzeit', ['privat' => 'ics']);

        self::assertSame('Blockzeit', $this->settings->defaultTitle());
        self::assertSame('ics', $this->settings->targetFor('privat'));
        self::assertSame('outlook', $this->settings->targetFor('dienstlich'), 'nicht gesetzte Kontexte behalten den Standard');
        self::assertSame([1, 2, 3], $this->settings->workingHours()->days);
        self::assertSame(5, $this->settings->workingHours()->bufferMinutes);
    }

    public function testSettingsValidation(): void
    {
        foreach ([
            [[1], 17 * 60, 8 * 60, 10, 'X', []],
            [[1], 8 * 60, 17 * 60, 500, 'X', []],
            [[9], 8 * 60, 17 * 60, 10, 'X', []],
            [[1], 8 * 60, 17 * 60, 10, '', []],
            [[1], 8 * 60, 17 * 60, 10, 'X', ['privat' => 'fax']],
        ] as $args) {
            try {
                $this->settings->save(...$args);
                self::fail('Erwartet InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
