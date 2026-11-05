<?php

declare(strict_types=1);

namespace Tests\Calendar;

use App\Auth\UserRepository;
use App\Calendar\HostResolver;
use App\Calendar\HttpResponse;
use App\Calendar\HttpTransport;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Tests\DbTestCase;

final class SourceControllerTest extends DbTestCase
{
    private const URL = 'https://kalender.example/geheim/token-SECRET123/cal.ics';

    private Kernel $kernel;
    private Session $session;
    private string $keyFile;
    private FakeTransport $transport;

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
        $this->transport = new FakeTransport([]);
        $c->instance(HttpTransport::class, $this->transport);
        $c->instance(HostResolver::class, new FakeResolver(['kalender.example' => ['93.184.216.34']]));
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
    private function call(string $method, string $path, array $post = [], bool $csrf = true): Response
    {
        if ($csrf) {
            $post['_csrf'] = $this->kernel->container()->get(Csrf::class)->token();
        }

        return $this->kernel->handle(new Request($method, $path, post: $post, server: ['HTTPS' => 'on']));
    }

    private function ics(): HttpResponse
    {
        return new HttpResponse(200, [], (string) file_get_contents(__DIR__ . '/../fixtures/ics/outlook.ics'));
    }

    public function testRequiresLogin(): void
    {
        self::assertSame(404, $this->call('GET', '/einstellungen/quellen')->status);
        self::assertSame(404, $this->call('POST', '/einstellungen/quellen', ['name' => 'x'])->status);
        self::assertSame(404, $this->call('POST', '/api/v1/calendar/test', ['url' => self::URL])->status);
    }

    public function testEmptyListAndCreateFlow(): void
    {
        $this->login();
        $page = $this->call('GET', '/einstellungen/quellen');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Noch keine Quelle eingerichtet', $page->body);

        $this->transport->push($this->ics());
        $create = $this->call('POST', '/einstellungen/quellen', [
            'name' => 'AWO · dienstlich', 'context' => 'dienstlich', 'color' => 'blue', 'interval' => '15', 'url' => self::URL,
        ]);
        self::assertSame(303, $create->status);

        $page = $this->call('GET', '/einstellungen/quellen');
        self::assertStringContainsString('AWO · dienstlich', $page->body);
        self::assertStringContainsString('Aktuell', $page->body);
        self::assertStringContainsString('src-blue', $page->body);
        self::assertStringNotContainsString('SECRET123', $page->body, 'Der Link wird nie wieder angezeigt');
        self::assertStringNotContainsString('kalender.example/geheim', $page->body);
        self::assertStringContainsString('kalender.example/…', $page->body);
        self::assertStringNotContainsString('Quelle gespeichert', $this->call('GET', '/einstellungen/quellen')->body, 'Hinweis erscheint nur einmal');
    }

    public function testCreateWithoutCsrfIsForbidden(): void
    {
        $this->login();
        $response = $this->call('POST', '/einstellungen/quellen', ['name' => 'x', 'url' => self::URL], false);

        self::assertSame(403, $response->status);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM calendar_sources'));
    }

    public function testInvalidInputShowsMessageAndStoresNothing(): void
    {
        $this->login();
        $this->call('POST', '/einstellungen/quellen', ['name' => 'x', 'context' => 'dienstlich', 'color' => 'blue', 'url' => 'http://kalender.example/x.ics']);

        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM calendar_sources'));
        self::assertStringContainsString('https-Adresse', $this->call('GET', '/einstellungen/quellen')->body);
    }

    public function testSourceNameIsEscaped(): void
    {
        $this->login();
        $this->transport->push($this->ics());
        $this->call('POST', '/einstellungen/quellen', ['name' => '<script>alert(1)</script>', 'context' => 'privat', 'color' => 'green', 'url' => self::URL]);
        $body = $this->call('GET', '/einstellungen/quellen')->body;

        self::assertStringNotContainsString('<script>alert(1)', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    public function testFailedFirstRefreshShowsErrorOnSource(): void
    {
        $this->login();
        $this->transport->push(new HttpResponse(404, [], ''));
        $this->call('POST', '/einstellungen/quellen', ['name' => 'Kaputt', 'context' => 'privat', 'color' => 'green', 'url' => self::URL]);
        $body = $this->call('GET', '/einstellungen/quellen')->body;

        self::assertStringContainsString('Abruf fehlgeschlagen', $body);
        self::assertStringContainsString('Kalender nicht gefunden', $body);
        self::assertStringNotContainsString('SECRET123', $body);
    }

    public function testPauseResumeAndRemove(): void
    {
        $this->login();
        $this->transport->push($this->ics(), $this->ics());
        $this->call('POST', '/einstellungen/quellen', ['name' => 'A', 'context' => 'privat', 'color' => 'green', 'url' => self::URL]);
        $id = (int) $this->db->fetchValue('SELECT id FROM calendar_sources');

        $this->call('POST', "/einstellungen/quellen/$id/pausieren");
        self::assertStringContainsString('Pausiert', $this->call('GET', '/einstellungen/quellen')->body);

        $this->call('POST', "/einstellungen/quellen/$id/fortsetzen");
        self::assertStringNotContainsString('badge--muted', $this->call('GET', '/einstellungen/quellen')->body);

        $this->call('POST', "/einstellungen/quellen/$id/entfernen");
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM calendar_sources'));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM calendar_events'));
    }

    public function testUnknownIdIsHandledGracefully(): void
    {
        $this->login();
        $response = $this->call('POST', '/einstellungen/quellen/999/entfernen');

        self::assertSame(303, $response->status);
        self::assertStringContainsString('nicht gefunden', $this->call('GET', '/einstellungen/quellen')->body);
    }

    public function testApiTestEndpoint(): void
    {
        $this->login();
        $this->transport->push($this->ics());
        $ok = $this->call('POST', '/api/v1/calendar/test', ['url' => self::URL]);
        self::assertSame(200, $ok->status);
        $data = json_decode($ok->body, true);
        self::assertTrue($data['ok']);
        self::assertGreaterThan(5, $data['eventCount']);

        $bad = json_decode($this->call('POST', '/api/v1/calendar/test', ['url' => 'https://127.0.0.1/x.ics'])->body, true);
        self::assertFalse($bad['ok']);
        self::assertNotEmpty($bad['error']);
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query): Response
    {
        return $this->kernel->handle(new Request('GET', $path, query: $query, server: ['HTTPS' => 'on']));
    }

    public function testAgendaApi(): void
    {
        $this->login();
        $this->transport->push($this->ics());
        $this->call('POST', '/einstellungen/quellen', ['name' => 'A', 'context' => 'privat', 'color' => 'green', 'url' => self::URL]);

        $response = $this->get('/api/v1/calendar/events', ['from' => '2026-11-09', 'to' => '2026-11-16']);
        self::assertSame(200, $response->status);
        $data = json_decode($response->body, true);

        self::assertCount(1, $data['sources']);
        self::assertSame('src-green', $data['sources'][0]['class']);
        $titles = array_column($data['events'], 'title');
        self::assertContains('Teamsitzung (verschoben)', $titles);
        self::assertContains('Klausurtagung', $titles);
        $allDay = array_values(array_filter($data['events'], static fn (array $e): bool => $e['title'] === 'Klausurtagung'))[0];
        self::assertTrue($allDay['allDay']);
        self::assertSame(['2026-11-12', '2026-11-14'], [$allDay['start'], $allDay['end']]);
        $moved = array_values(array_filter($data['events'], static fn (array $e): bool => $e['title'] === 'Teamsitzung (verschoben)'))[0];
        self::assertSame('2026-11-10T13:00:00Z', $moved['start']);
        self::assertStringNotContainsString('SECRET123', $response->body);
    }

    public function testAgendaApiValidatesInputAndRequiresLogin(): void
    {
        self::assertSame(404, $this->get('/api/v1/calendar/events', ['from' => '2026-11-09', 'to' => '2026-11-16'])->status);

        $this->login();
        foreach ([[], ['from' => 'x', 'to' => 'y'], ['from' => '2026-11-16', 'to' => '2026-11-09'], ['from' => '2026-02-31', 'to' => '2026-03-05'], ['from' => '2026-01-01', 'to' => '2026-12-31']] as $query) {
            self::assertSame(422, $this->get('/api/v1/calendar/events', $query)->status, json_encode($query));
        }
    }

    public function testPausedSourceEventsAreNotReturned(): void
    {
        $this->login();
        $this->transport->push($this->ics());
        $this->call('POST', '/einstellungen/quellen', ['name' => 'A', 'context' => 'privat', 'color' => 'green', 'url' => self::URL]);
        $id = (int) $this->db->fetchValue('SELECT id FROM calendar_sources');
        $this->call('POST', "/einstellungen/quellen/$id/pausieren");

        $data = json_decode($this->get('/api/v1/calendar/events', ['from' => '2026-11-09', 'to' => '2026-11-16'])->body, true);
        self::assertSame([], $data['events']);
        self::assertFalse($data['sources'][0]['active']);
    }
}
