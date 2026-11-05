<?php

declare(strict_types=1);

namespace Tests\Calendar;

use App\Calendar\GoogleCalendarLinkBuilder;
use App\Calendar\IcsEventBuilder;
use App\Calendar\OutlookWebLinkBuilder;
use App\Core\PlannedEvent;
use PHPUnit\Framework\TestCase;

final class EventLinkBuilderTest extends TestCase
{
    private function event(string $title = 'Fokuszeit', string $notes = ''): PlannedEvent
    {
        $zone = new \DateTimeZone('Europe/Berlin');

        return new PlannedEvent($title, new \DateTimeImmutable('2026-11-10 09:00', $zone), new \DateTimeImmutable('2026-11-10 10:30', $zone), $notes);
    }

    /** @return array<string, string> */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return $q;
    }

    public function testOutlookLink(): void
    {
        $url = (new OutlookWebLinkBuilder())->url($this->event());

        self::assertStringStartsWith('https://outlook.office.com/calendar/0/deeplink/compose?', $url);
        $q = $this->query($url);
        self::assertSame('Fokuszeit', $q['subject']);
        self::assertSame('2026-11-10T09:00:00+01:00', $q['startdt']);
        self::assertSame('2026-11-10T10:30:00+01:00', $q['enddt']);
        self::assertSame('addevent', $q['rru']);
        self::assertSame('/calendar/action/compose', $q['path']);
    }

    public function testOutlookLinkUsesSummerOffsetAndConfigurableHost(): void
    {
        $zone = new \DateTimeZone('Europe/Berlin');
        $event = new PlannedEvent('X', new \DateTimeImmutable('2026-07-10 09:00', $zone), new \DateTimeImmutable('2026-07-10 10:00', $zone));
        $url = (new OutlookWebLinkBuilder('outlook.office365.com'))->url($event);

        self::assertStringStartsWith('https://outlook.office365.com/', $url);
        self::assertSame('2026-07-10T09:00:00+02:00', $this->query($url)['startdt']);
    }

    public function testGoogleLinkUsesUtcRange(): void
    {
        $url = (new GoogleCalendarLinkBuilder())->url($this->event());

        self::assertStringStartsWith('https://calendar.google.com/calendar/render?', $url);
        $q = $this->query($url);
        self::assertSame('TEMPLATE', $q['action']);
        self::assertSame('20261110T080000Z/20261110T093000Z', $q['dates']);
        self::assertSame('Fokuszeit', $q['text']);
    }

    public function testSpecialCharactersAreEncodedAndCannotInjectParameters(): void
    {
        $evil = 'Titel&subject=Hacked#frag?x=1 ä';
        foreach ([new OutlookWebLinkBuilder(), new GoogleCalendarLinkBuilder()] as $builder) {
            $url = $builder->url($this->event($evil, 'Notiz & mehr'));
            $q = $this->query($url);

            self::assertSame($evil, $q['subject'] ?? $q['text'], 'Titel kommt unverändert an');
            self::assertStringNotContainsString('#', $url);
            self::assertStringNotContainsString(' ', $url);
        }
    }

    public function testIcsFileContainsEventAndEscapesText(): void
    {
        $ics = (new IcsEventBuilder())->build($this->event('A, B; C\\D', "Zeile 1\nZeile 2"), 'block-1');

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringContainsString("UID:block-1@personal-hub\r\n", $ics);
        self::assertStringContainsString("DTSTART:20261110T080000Z\r\n", $ics);
        self::assertStringContainsString("DTEND:20261110T093000Z\r\n", $ics);
        self::assertStringContainsString('SUMMARY:A\\, B\; C\\\\D', $ics);
        self::assertStringContainsString('DESCRIPTION:Zeile 1\\nZeile 2', $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
    }

    public function testIcsLinesAreFoldedAndRoundTripThroughParser(): void
    {
        $title = str_repeat('Überlanger Titel mit Umlauten äöüß ', 8);
        $ics = (new IcsEventBuilder())->build($this->event($title), 'block-2');

        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(75, strlen($line), 'Zeile zu lang: ' . $line);
        }
        $zone = new \DateTimeZone('UTC');
        $events = (new \App\Calendar\IcsParser())->parse($ics, new \DateTimeImmutable('2026-11-01', $zone), new \DateTimeImmutable('2026-12-01', $zone));
        self::assertCount(1, $events);
        self::assertSame(trim($title), $events[0]->title);
        self::assertSame('2026-11-10 08:00', $events[0]->startsAt->format('Y-m-d H:i'));
    }
}
