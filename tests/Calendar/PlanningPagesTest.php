<?php

declare(strict_types=1);

namespace Tests\Calendar;

use App\Auth\UserRepository;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Items\TaskService;
use Tests\DbTestCase;

final class PlanningPagesTest extends DbTestCase
{
    private Kernel $kernel;
    private Session $session;
    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keyFile = tempnam(sys_get_temp_dir(), 'phkey') ?: '';
        file_put_contents($this->keyFile, Crypto::generateKeyHex());
        $this->session = new Session(null, true);
        $this->kernel = new Kernel(dirname(__DIR__, 2), new Config([
            'app' => ['url' => 'https://hub.example.invalid'],
            'crypto' => ['key_path' => $this->keyFile],
        ]));
        $c = $this->kernel->container();
        $c->instance(Session::class, $this->session);
        $c->instance(Db::class, $this->db);
        $this->kernel->boot(require dirname(__DIR__, 2) . '/config/modules.php');
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
    }

    private function login(): void
    {
        $c = $this->kernel->container();
        $id = $c->get(UserRepository::class)->create('thomas', 'x', $c->get(Crypto::class)->encrypt('S'), gmdate('Y-m-d H:i:s'));
        $this->session->set('uid', $id);
    }

    /** @param array<string, mixed> $post */
    private function post(string $path, array $post = [], bool $csrf = true): Response
    {
        if ($csrf) {
            $post['_csrf'] = $this->kernel->container()->get(Csrf::class)->token();
        }

        return $this->kernel->handle(new Request('POST', $path, post: $post, server: ['HTTPS' => 'on']));
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): Response
    {
        return $this->kernel->handle(new Request('GET', $path, query: $query, server: ['HTTPS' => 'on']));
    }

    public function testRequiresLogin(): void
    {
        foreach (['/reservieren', '/reservieren/1', '/reservieren/1/termin.ics', '/einstellungen/planung'] as $path) {
            self::assertSame(404, $this->get($path)->status, $path);
        }
        self::assertSame(404, $this->post('/reservieren', ['title' => 'x'])->status);
        self::assertSame(404, $this->post('/einstellungen/planung')->status);
    }

    public function testSuggestionsForTaskAndFullReservationFlow(): void
    {
        $this->login();
        $taskId = $this->kernel->container()->get(TaskService::class)->createTask('Konzept schreiben', 'privat', null, null);

        $page = $this->get('/reservieren', ['item' => (string) $taskId, 'dauer' => '90']);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Konzept schreiben', $page->body);
        self::assertStringContainsString('Zeit reservieren', $page->body);
        self::assertSame(1, preg_match('/name="start" value="(\d+)"/', $page->body, $start), 'mindestens ein Vorschlag');
        preg_match('/name="end" value="(\d+)"/', $page->body, $end);
        self::assertSame(5400, (int) $end[1] - (int) $start[1], '90 Minuten');
        self::assertMatchesRegularExpression('/<option value="google" selected>/', $page->body, 'Privat → Google');

        $reserve = $this->post('/reservieren', ['start' => $start[1], 'end' => $end[1], 'title' => 'Fokuszeit', 'target' => 'google', 'item' => (string) $taskId]);
        self::assertSame(303, $reserve->status);
        $blockId = (int) $this->db->fetchValue('SELECT id FROM planned_blocks');
        self::assertSame('/reservieren/' . $blockId, $reserve->headers['Location']);
        self::assertSame($taskId, (int) $this->db->fetchValue('SELECT item_id FROM planned_blocks'));

        $done = $this->get('/reservieren/' . $blockId);
        self::assertStringContainsString('calendar.google.com/calendar/render', $done->body);
        self::assertStringContainsString('target="_blank" rel="noopener noreferrer"', $done->body);

        $ics = $this->get("/reservieren/$blockId/termin.ics");
        self::assertStringStartsWith('text/calendar', $ics->headers['Content-Type']);
        self::assertStringContainsString('BEGIN:VEVENT', $ics->body);
        self::assertStringContainsString('attachment', $ics->headers['Content-Disposition']);

        // Im Leitstand als vorgemerkt sichtbar
        $date = gmdate('Y-m-d', (int) $start[1]);
        $api = json_decode($this->get('/api/v1/calendar/events', ['from' => $date, 'to' => gmdate('Y-m-d', (int) $start[1] + 2 * 86400)])->body, true);
        self::assertCount(1, $api['planned']);
        self::assertSame('Fokuszeit', $api['planned'][0]['title']);

        $this->post("/reservieren/$blockId/verwerfen");
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM planned_blocks'));
    }

    public function testReserveRejectsBadInputAndRequiresCsrf(): void
    {
        $this->login();
        $future = time() + 86400;

        self::assertSame(403, $this->post('/reservieren', ['start' => $future, 'end' => $future + 3600, 'title' => 'x', 'target' => 'ics'], false)->status);
        foreach ([
            ['start' => $future, 'end' => $future + 3600, 'title' => '', 'target' => 'ics'],
            ['start' => $future, 'end' => $future + 3600, 'title' => 'x', 'target' => 'fax'],
            ['start' => 1000, 'end' => 4600, 'title' => 'x', 'target' => 'ics'],
            ['start' => $future, 'end' => $future, 'title' => 'x', 'target' => 'ics'],
        ] as $post) {
            self::assertSame(303, $this->post('/reservieren', $post)->status);
        }
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM planned_blocks'));
    }

    public function testUnknownItemAndBlockAre404(): void
    {
        $this->login();

        self::assertSame(404, $this->get('/reservieren', ['item' => '999'])->status);
        self::assertSame(404, $this->get('/reservieren/999')->status);
        self::assertSame(404, $this->get('/reservieren/999/termin.ics')->status);
    }

    public function testTitleIsEscaped(): void
    {
        $this->login();
        $future = time() + 86400;
        $this->post('/reservieren', ['start' => $future, 'end' => $future + 3600, 'title' => '<script>alert(1)</script>', 'target' => 'outlook']);
        $id = (int) $this->db->fetchValue('SELECT id FROM planned_blocks');

        $body = $this->get('/reservieren/' . $id)->body;
        self::assertStringNotContainsString('<script>alert(1)', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    public function testSettingsPage(): void
    {
        $this->login();
        self::assertStringContainsString('Fokuszeit', $this->get('/einstellungen/planung')->body);

        $this->post('/einstellungen/planung', [
            'days' => ['1', '2', '3'], 'start' => '09:00', 'end' => '16:30', 'buffer' => '5', 'title' => 'Blockzeit',
            'target' => ['privat' => 'ics', 'dienstlich' => 'google'],
        ]);
        $page = $this->get('/einstellungen/planung');
        self::assertStringContainsString('Einstellungen gespeichert', $page->body);
        self::assertStringContainsString('value="Blockzeit"', $page->body);
        self::assertStringContainsString('value="09:00"', $page->body);
        self::assertStringContainsString('value="16:30"', $page->body);

        $this->post('/einstellungen/planung', ['days' => ['1'], 'start' => 'xx', 'end' => '17:00', 'buffer' => '10', 'title' => 'T']);
        self::assertStringContainsString('Uhrzeiten wie 08:00', $this->get('/einstellungen/planung')->body);
    }
}
