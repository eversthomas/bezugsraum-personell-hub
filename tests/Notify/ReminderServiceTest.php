<?php

declare(strict_types=1);

namespace Tests\Notify;

use App\Calendar\CalendarService;
use App\Calendar\EventRepository;
use App\Calendar\HttpResponse;
use App\Calendar\IcsFetcher;
use App\Calendar\IcsParser;
use App\Calendar\SourceRepository;
use App\Calendar\UrlGuard;
use App\Core\Crypto;
use App\Core\Logger;
use App\Items\ItemRepository;
use App\Items\TaskService;
use App\Notify\PushResult;
use App\Notify\PushSubscriptionRepository;
use App\Notify\ReminderService;
use App\Notify\WebPushChannel;
use Tests\Calendar\FakeResolver;
use Tests\Calendar\FakeTransport;
use Tests\DbTestCase;

final class ReminderServiceTest extends DbTestCase
{
    private int $clock;
    private FakePushSender $sender;
    private TaskService $tasks;
    private CalendarService $calendar;
    private ReminderService $reminders;
    private FakeTransport $transport;
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        // Donnerstag 5.11.2026, 06:30 UTC = 07:30 Berlin (vor der 8-Uhr-Erinnerung)
        $this->clock = (int) strtotime('2026-11-05 06:30:00 UTC');
        $clock = fn (): int => $this->clock;
        $this->logFile = tempnam(sys_get_temp_dir(), 'phlog') ?: '';
        $contextExists = fn (string $key): bool => $this->db->fetchOne('SELECT `key` FROM contexts WHERE `key` = ?', [$key]) !== null;
        $this->tasks = new TaskService(new ItemRepository($this->db), $contextExists, $clock);
        $guard = new UrlGuard(new FakeResolver(['kalender.example' => ['93.184.216.34']]));
        $this->transport = new FakeTransport([]);
        $this->calendar = new CalendarService(
            new SourceRepository($this->db),
            new EventRepository($this->db),
            new IcsFetcher($guard, $this->transport),
            $guard,
            new IcsParser(),
            new Crypto(sodium_hex2bin(Crypto::generateKeyHex())),
            new Logger($this->logFile),
            $clock,
        );
        $this->sender = new FakePushSender();
        $subs = new PushSubscriptionRepository($this->db);
        $subs->upsert('https://fcm.googleapis.com/fcm/send/a', 'k', 'a', 'Pixel', '2026-11-01 00:00:00');
        $this->reminders = new ReminderService($this->tasks, $this->calendar, new WebPushChannel($subs, $this->sender, $clock));
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
    }

    /** @return list<string> */
    private function bodies(): array
    {
        return array_column($this->sender->payloads(), 'body');
    }

    public function testTaskReminderIsSentOnceAfterEightBerlinTime(): void
    {
        $this->tasks->createTask('Steuer abgeben', null, '2026-11-05', null);

        self::assertSame(0, $this->reminders->run(), 'vor 8:00 Uhr noch nichts');

        $this->clock = (int) strtotime('2026-11-05 07:05:00 UTC'); // 08:05 Berlin
        self::assertSame(1, $this->reminders->run());
        self::assertSame(['Steuer abgeben'], $this->bodies());
        self::assertSame('Aufgabe fällig', $this->sender->payloads()[0]['title']);

        $this->clock += 900;
        self::assertSame(0, $this->reminders->run(), 'nicht noch einmal');
        $this->clock += 6 * 3600;
        self::assertSame(0, $this->reminders->run());
        self::assertCount(1, $this->sender->sent);
    }

    public function testOverdueTaskGetsReminderOnceButNotForAnAncientBacklog(): void
    {
        $this->tasks->createTask('gestern fällig', null, '2026-11-04', null);
        $this->tasks->createTask('uralt', null, '2026-09-01', null);

        $this->clock = (int) strtotime('2026-11-05 07:05:00 UTC');
        $this->reminders->run();

        self::assertSame(['gestern fällig'], $this->bodies(), 'ältere als 2 Tage werden still übersprungen');
        $this->clock += 3600;
        self::assertSame(0, $this->reminders->run(), 'auch die alte wird nicht nachgeholt');
    }

    public function testCompletedTasksGetNoReminderAndRecurringTaskRemindsAgainNextTime(): void
    {
        $done = $this->tasks->createTask('erledigt', null, '2026-11-05', null);
        $this->tasks->complete($done);
        $weekly = $this->tasks->createTask('Bericht', null, '2026-11-05', 'weekly:thu');

        $this->clock = (int) strtotime('2026-11-05 07:05:00 UTC');
        $this->reminders->run();
        self::assertSame(['Bericht'], $this->bodies());

        $this->tasks->complete($weekly); // rückt auf Donnerstag 12.11.
        $this->clock = (int) strtotime('2026-11-12 07:05:00 UTC');
        $this->reminders->run();
        self::assertSame(['Bericht', 'Bericht'], $this->bodies());
    }

    public function testFailedDeliveryIsRetriedLaterAndThenSentOnce(): void
    {
        $this->tasks->createTask('wichtig', null, '2026-11-05', null);
        $this->clock = (int) strtotime('2026-11-05 07:05:00 UTC');
        $this->sender->results['https://fcm.googleapis.com/fcm/send/a'] = PushResult::Failed;

        self::assertSame(0, $this->reminders->run(), 'Zustellung scheitert komplett');

        $this->sender->results = [];
        $this->clock += 900;
        self::assertSame(1, $this->reminders->run());
        $this->clock += 900;
        self::assertSame(0, $this->reminders->run());
    }

    public function testRetriesAreLimited(): void
    {
        $this->tasks->createTask('hoffnungslos', null, '2026-11-05', null);
        $this->clock = (int) strtotime('2026-11-05 07:05:00 UTC');
        $this->sender->results['https://fcm.googleapis.com/fcm/send/a'] = PushResult::Failed;

        for ($i = 0; $i < 6; ++$i) {
            $this->reminders->run();
            $this->clock += 900;
        }

        self::assertCount(3, $this->sender->sent, 'höchstens 3 Versuche');
    }

    private function sourceWithEvent(string $startUtc, ?int $remind, bool $active = true): int
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:evt-1\r\nSUMMARY:Besprechung\r\nLOCATION:Raum 5\r\n"
            . 'DTSTART:' . gmdate('Ymd\THis\Z', (int) strtotime($startUtc)) . "\r\n"
            . 'DTEND:' . gmdate('Ymd\THis\Z', (int) strtotime($startUtc) + 3600) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->transport->push(new HttpResponse(200, [], $ics));
        $id = $this->calendar->addSource('AWO', 'dienstlich', '#2563EB', 'https://kalender.example/x.ics');
        $this->calendar->refresh($id);
        $this->calendar->setReminder($id, $remind);
        if (!$active) {
            $this->calendar->pause($id);
        }

        return $id;
    }

    public function testEventReminderFiresInsideLeadTimeExactlyOnce(): void
    {
        $this->sourceWithEvent('2026-11-05 07:00:00', 15); // Beginn 08:00 Berlin

        $this->clock = (int) strtotime('2026-11-05 06:40:00 UTC'); // 20 Min. vorher
        self::assertSame(0, $this->reminders->run());

        $this->clock = (int) strtotime('2026-11-05 06:50:00 UTC'); // 10 Min. vorher
        self::assertSame(1, $this->reminders->run());
        self::assertSame('Gleich: Besprechung', $this->sender->payloads()[0]['title']);
        self::assertSame('08:00 Uhr · Raum 5 · AWO', $this->sender->payloads()[0]['body']);

        $this->clock = (int) strtotime('2026-11-05 06:55:00 UTC');
        self::assertSame(0, $this->reminders->run(), 'nicht noch einmal');
    }

    public function testEventReminderSurvivesRefreshWithoutDuplicate(): void
    {
        $id = $this->sourceWithEvent('2026-11-05 07:00:00', 15);
        $this->clock = (int) strtotime('2026-11-05 06:50:00 UTC');
        $this->reminders->run();

        // Neuer Abruf ersetzt die Termine (neue Zeilen-IDs), der Hinweis darf nicht erneut kommen.
        $this->transport->push(new HttpResponse(200, [], $this->icsAt('2026-11-05 07:00:00')));
        $this->calendar->refresh($id);
        $this->clock += 120;

        self::assertSame(0, $this->reminders->run());
        self::assertCount(1, $this->sender->sent);
    }

    private function icsAt(string $startUtc): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:evt-1\r\nSUMMARY:Besprechung\r\n"
            . 'DTSTART:' . gmdate('Ymd\THis\Z', (int) strtotime($startUtc)) . "\r\nDTEND:" . gmdate('Ymd\THis\Z', (int) strtotime($startUtc) + 3600) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    public function testNoEventReminderWithoutSettingOrForPausedSourceOrAfterStart(): void
    {
        $this->sourceWithEvent('2026-11-05 07:00:00', null);
        $this->clock = (int) strtotime('2026-11-05 06:55:00 UTC');
        self::assertSame(0, $this->reminders->run(), 'Hinweis ist aus');

        $this->calendar->setReminder((int) $this->db->fetchValue('SELECT id FROM calendar_sources'), 15);
        $this->clock = (int) strtotime('2026-11-05 07:10:00 UTC');
        self::assertSame(0, $this->reminders->run(), 'Termin hat schon begonnen');

        $this->clock = (int) strtotime('2026-11-05 06:50:00 UTC');
        $this->calendar->pause((int) $this->db->fetchValue('SELECT id FROM calendar_sources'));
        self::assertSame(0, $this->reminders->run(), 'Quelle pausiert');
    }

    public function testInvalidLeadTimeIsRejected(): void
    {
        $id = $this->sourceWithEvent('2026-11-05 07:00:00', null);

        $this->expectException(\InvalidArgumentException::class);
        $this->calendar->setReminder($id, 7);
    }
}
