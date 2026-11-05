<?php

declare(strict_types=1);

namespace Tests\Calendar;

use App\Calendar\CalendarService;
use App\Calendar\EventRepository;
use App\Calendar\HttpResponse;
use App\Calendar\IcsFetcher;
use App\Calendar\IcsParser;
use App\Calendar\SourceRepository;
use App\Calendar\UrlGuard;
use App\Core\Crypto;
use App\Core\Logger;
use Tests\DbTestCase;

final class CalendarServiceTest extends DbTestCase
{
    private const URL = 'https://kalender.example/geheim/token-SECRET123/cal.ics';

    private int $clock;
    private Crypto $crypto;
    private SourceRepository $sources;
    private EventRepository $events;
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = (int) strtotime('2026-11-05 08:00:00 UTC');
        $this->crypto = new Crypto(sodium_hex2bin(Crypto::generateKeyHex()));
        $this->sources = new SourceRepository($this->db);
        $this->events = new EventRepository($this->db);
        $this->logFile = tempnam(sys_get_temp_dir(), 'phlog') ?: '';
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
    }

    private function ics(string $name = 'outlook.ics'): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/ics/' . $name);
    }

    /** @param list<HttpResponse> $responses */
    private function service(array $responses): CalendarService
    {
        $guard = new UrlGuard(new FakeResolver(['kalender.example' => ['93.184.216.34']]));

        return new CalendarService(
            $this->sources,
            $this->events,
            new IcsFetcher($guard, new FakeTransport($responses)),
            $guard,
            new IcsParser(),
            $this->crypto,
            new Logger($this->logFile),
            fn (): int => $this->clock,
        );
    }

    private function eventCount(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM calendar_events');
    }

    public function testUrlIsStoredEncryptedAndHintHidesTheSecret(): void
    {
        $id = $this->service([])->addSource('AWO', 'dienstlich', '#2563eb', self::URL);
        $row = $this->db->fetchOne('SELECT * FROM calendar_sources WHERE id = ?', [$id]);

        self::assertNotNull($row);
        self::assertStringNotContainsString('SECRET123', (string) $row['url_encrypted']);
        self::assertStringNotContainsString('geheim', (string) $row['url_encrypted']);
        self::assertSame('kalender.example/…', $row['url_hint']);
        self::assertSame('#2563EB', $row['color']);
        self::assertSame(self::URL, $this->crypto->decrypt((string) $row['url_encrypted']));
    }

    public function testRefreshStoresEventsAndMarksSuccess(): void
    {
        $service = $this->service([new HttpResponse(200, ['etag' => '"e1"'], $this->ics())]);
        $id = $service->addSource('AWO', 'dienstlich', '#2563EB', self::URL);

        self::assertTrue($service->refresh($id));

        self::assertGreaterThan(5, $this->eventCount());
        $source = $this->sources->find($id);
        self::assertNotNull($source);
        self::assertSame('"e1"', $source->etag);
        self::assertSame('2026-11-05 08:00:00', $source->lastOkAt);
        self::assertNull($source->lastError);
    }

    public function testFailedRefreshKeepsOldEventsAndRecordsError(): void
    {
        $service = $this->service([
            new HttpResponse(200, [], $this->ics()),
            new HttpResponse(404, [], ''),
        ]);
        $id = $service->addSource('AWO', 'dienstlich', '#2563EB', self::URL);
        $service->refresh($id);
        $before = $this->eventCount();

        self::assertFalse($service->refresh($id));

        self::assertSame($before, $this->eventCount());
        $source = $this->sources->find($id);
        self::assertNotNull($source);
        self::assertNotNull($source->lastError);
        self::assertStringNotContainsString('SECRET123', (string) $source->lastError);
        self::assertSame('2026-11-05 08:00:00', $source->lastOkAt);
        self::assertStringNotContainsString('SECRET123', (string) file_get_contents($this->logFile));
    }

    public function testRefreshReplacesEventsInsteadOfAccumulating(): void
    {
        $service = $this->service([
            new HttpResponse(200, [], $this->ics('outlook.ics')),
            new HttpResponse(200, [], $this->ics('google.ics')),
        ]);
        $id = $service->addSource('AWO', 'dienstlich', '#2563EB', self::URL);
        $service->refresh($id);
        $service->refresh($id);

        $titles = array_column($this->db->fetchAll('SELECT title FROM calendar_events'), 'title');
        self::assertContains('Zahnarzt, Kontrolle', $titles);
        self::assertNotContains('Teamsitzung', $titles);
    }

    public function testNotModifiedKeepsEvents(): void
    {
        $service = $this->service([
            new HttpResponse(200, ['etag' => '"e1"'], $this->ics()),
            new HttpResponse(304, [], ''),
        ]);
        $id = $service->addSource('AWO', 'dienstlich', '#2563EB', self::URL);
        $service->refresh($id);
        $before = $this->eventCount();
        $this->clock += 900;

        self::assertTrue($service->refresh($id));
        self::assertSame($before, $this->eventCount());
        self::assertSame('2026-11-05 08:15:00', $this->sources->find($id)?->lastOkAt);
    }

    public function testDueRespectsIntervalAndPause(): void
    {
        $service = $this->service([new HttpResponse(200, [], $this->ics()), new HttpResponse(200, [], $this->ics())]);
        $a = $service->addSource('A', 'dienstlich', '#2563EB', self::URL, 15);
        $b = $service->addSource('B', 'privat', '#15803D', self::URL, 15);
        $service->pause($b);

        self::assertSame(1, $service->refreshDue(), 'A war nie abgerufen, B ist pausiert');
        self::assertSame(0, $service->refreshDue(), 'A wurde gerade abgerufen');

        $this->clock += 14 * 60;
        self::assertSame(0, $service->refreshDue());
        $this->clock += 60;
        self::assertSame(1, $service->refreshDue(), 'Intervall abgelaufen');
        self::assertNotNull($this->sources->find($a)?->lastOkAt);
    }

    public function testPausedSourceIsHiddenFromQueries(): void
    {
        $service = $this->service([new HttpResponse(200, [], $this->ics())]);
        $id = $service->addSource('A', 'dienstlich', '#2563EB', self::URL);
        $service->refresh($id);
        $range = [new \DateTimeImmutable('2026-11-01 UTC'), new \DateTimeImmutable('2026-12-01 UTC')];

        self::assertNotEmpty($this->events->between(...$range));
        $service->pause($id);
        self::assertSame([], $this->events->between(...$range));
        $service->resume($id);
        self::assertNotEmpty($this->events->between(...$range));
        self::assertSame([], $this->events->between($range[0], $range[1], []));
    }

    public function testReplaceUrlResetsEtagAndRemoveDeletesEvents(): void
    {
        $service = $this->service([new HttpResponse(200, ['etag' => '"e1"'], $this->ics())]);
        $id = $service->addSource('A', 'dienstlich', '#2563EB', self::URL);
        $service->refresh($id);

        $service->replaceUrl($id, 'webcal://kalender.example/neu/cal.ics');
        $source = $this->sources->find($id);
        self::assertNotNull($source);
        self::assertNull($source->etag);
        self::assertSame('https://kalender.example/neu/cal.ics', $this->crypto->decrypt($source->urlEncrypted));

        $service->remove($id);
        self::assertSame(0, $this->eventCount());
        self::assertSame([], $service->sources());
    }

    public function testTestUrlReportsCountOrError(): void
    {
        $ok = $this->service([new HttpResponse(200, [], $this->ics())])->test(self::URL);
        self::assertTrue($ok->ok);
        self::assertGreaterThan(5, $ok->eventCount);

        $bad = $this->service([])->test('https://intern.example/x.ics');
        self::assertFalse($bad->ok);
        self::assertNotNull($bad->error);
        self::assertSame(0, $this->eventCount(), 'Test speichert nichts');
    }

    public function testValidation(): void
    {
        $service = $this->service([]);
        $cases = [
            ['', 'dienstlich', '#2563EB', self::URL],
            ['A', 'gibtsnicht', '#2563EB', self::URL],
            ['A', 'dienstlich', 'blau', self::URL],
            ['A', 'dienstlich', '#2563EB', 'http://kalender.example/x.ics'],
            ['A', 'dienstlich', '#2563EB', 'kein url'],
        ];
        foreach ($cases as [$name, $context, $color, $url]) {
            try {
                $service->addSource($name, $context, $color, $url);
                self::fail('Erwartet InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame([], $service->sources());
    }

    public function testOldEventsArePurged(): void
    {
        $service = $this->service([new HttpResponse(200, [], $this->ics())]);
        $id = $service->addSource('A', 'dienstlich', '#2563EB', self::URL);
        $service->refresh($id);
        $this->db->execute(
            "INSERT INTO calendar_events (source_id, uid_hash, starts_at, ends_at, all_day, title, fetched_at) VALUES (?, 'x', '2026-08-01 10:00:00', '2026-08-01 11:00:00', 0, 'alt', '2026-08-01 00:00:00')",
            [$id],
        );

        $this->clock += 900;
        $service->refreshDue();

        self::assertNull($this->db->fetchOne("SELECT id FROM calendar_events WHERE title = 'alt'"));
    }
}
