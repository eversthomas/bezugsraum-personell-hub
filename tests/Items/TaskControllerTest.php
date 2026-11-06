<?php

declare(strict_types=1);

namespace Tests\Items;

use App\Auth\UserRepository;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Tests\DbTestCase;

final class TaskControllerTest extends DbTestCase
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
        if (isset($this->keyFile)) {
            @unlink($this->keyFile);
        }
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

    public function testEverythingRequiresLogin(): void
    {
        foreach (['/aufgaben', '/aufgaben/1', '/notizen', '/notizen/neu', '/notizen/1', '/export.json', '/api/v1/tasks/due'] as $path) {
            self::assertSame(404, $this->get($path)->status, $path);
        }
        foreach (['/aufgaben', '/aufgaben/1/erledigt', '/notizen', '/api/v1/tasks/1/complete'] as $path) {
            self::assertSame(404, $this->post($path, ['title' => 'x'])->status, $path);
        }
    }

    public function testTaskLifecycle(): void
    {
        $this->login();
        self::assertSame(303, $this->post('/aufgaben', ['title' => 'Steuer', 'context' => 'privat', 'due' => '2026-12-01', 'recurrence' => ''])->status);
        $id = (int) $this->db->fetchValue('SELECT id FROM items');

        $page = $this->get('/aufgaben');
        self::assertStringContainsString('Steuer', $page->body);
        self::assertStringContainsString('01.12.2026', $page->body);

        $this->post("/aufgaben/$id", ['title' => 'Steuer 2026', 'body' => 'Belege', 'context' => 'privat', 'due' => '2026-12-05', 'recurrence' => '']);
        self::assertSame('Steuer 2026', $this->db->fetchValue('SELECT title FROM items WHERE id = ?', [$id]));

        $this->post("/aufgaben/$id/erledigt");
        self::assertStringContainsString('Zuletzt erledigt', $this->get('/aufgaben')->body);

        $this->post("/aufgaben/$id/wieder-oeffnen");
        self::assertNull($this->db->fetchValue('SELECT done_at FROM items WHERE id = ?', [$id]));

        $this->post("/aufgaben/$id/loeschen");
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM items'));
    }

    public function testRecurringTaskKeepsOpenAfterCompletion(): void
    {
        $this->login();
        $this->post('/aufgaben', ['title' => 'Bericht', 'due' => '2020-01-06', 'recurrence' => 'weekly:mon']);
        $id = (int) $this->db->fetchValue('SELECT id FROM items');

        $this->post("/aufgaben/$id/erledigt");

        self::assertNull($this->db->fetchValue('SELECT done_at FROM items WHERE id = ?', [$id]));
        self::assertGreaterThan('2020-01-06', $this->db->fetchValue('SELECT due_date FROM items WHERE id = ?', [$id]));
    }

    public function testApiSummaryAndComplete(): void
    {
        $this->login();
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $this->post('/aufgaben', ['title' => 'Heute fällig', 'due' => $today]);
        $this->post('/aufgaben', ['title' => 'Irgendwann', 'due' => '2099-01-01']);
        $id = (int) $this->db->fetchValue("SELECT id FROM items WHERE title = 'Heute fällig'");

        $summary = json_decode($this->get('/api/v1/tasks/due')->body, true);
        self::assertSame(1, $summary['today']);
        self::assertSame(['Heute fällig'], array_column($summary['items'], 'title'));

        $response = $this->post("/api/v1/tasks/$id/complete");
        self::assertSame(200, $response->status);
        self::assertSame(0, json_decode($this->get('/api/v1/tasks/due')->body, true)['today']);
        self::assertSame(404, $this->post('/api/v1/tasks/9999/complete')->status);
        self::assertSame(403, $this->post("/api/v1/tasks/$id/complete", [], false)->status);
    }

    public function testValidationErrorIsShown(): void
    {
        $this->login();
        $this->post('/aufgaben', ['title' => 'x', 'recurrence' => 'daily']);

        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM items'));
        self::assertStringContainsString('brauchen ein Fälligkeitsdatum', $this->get('/aufgaben')->body);
    }

    public function testNotesWithMarkdownAndSearch(): void
    {
        $this->login();
        $this->post('/notizen', ['title' => 'Protokoll', 'body' => "# Beschluss\n\n**wichtig** <script>alert(1)</script>\n\n[Link](javascript:alert(1))", 'context' => 'betriebsrat']);
        $id = (int) $this->db->fetchValue('SELECT id FROM items');

        $page = $this->get("/notizen/$id");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('<h1>Beschluss</h1>', $page->body);
        self::assertStringContainsString('<strong>wichtig</strong>', $page->body);
        self::assertStringNotContainsString('<script>alert(1)', $page->body, 'weder gerendert noch ungeschützt im Formular');
        self::assertStringNotContainsString('href="javascript:', $page->body);

        self::assertStringContainsString('Protokoll', $this->get('/notizen', ['q' => 'beschluss'])->body);
        self::assertStringContainsString('Keine Notiz gefunden', $this->get('/notizen', ['q' => 'gibtsnicht'])->body);
        self::assertStringContainsString('Protokoll', $this->get('/notizen', ['kontext' => 'betriebsrat'])->body);
        self::assertStringNotContainsString('Protokoll', $this->get('/notizen', ['kontext' => 'privat'])->body);
    }

    public function testNoteTitleIsEscapedAndWrongTypeIs404(): void
    {
        $this->login();
        $this->post('/notizen', ['title' => '<img src=x onerror=alert(1)>', 'body' => 'x']);
        $id = (int) $this->db->fetchValue('SELECT id FROM items');

        self::assertStringNotContainsString('<img src=x', $this->get('/notizen')->body);
        self::assertStringNotContainsString('<img src=x', $this->get("/notizen/$id")->body);
        self::assertSame(404, $this->get("/aufgaben/$id")->status, 'Eine Notiz ist keine Aufgabe');
        self::assertSame(404, $this->get('/notizen/99999')->status);
    }

    public function testExportContainsItemsButIsAttachment(): void
    {
        $this->login();
        $this->post('/aufgaben', ['title' => 'Export-Test']);
        $response = $this->get('/export.json');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('attachment', $response->headers['Content-Disposition']);
        $data = json_decode($response->body, true);
        self::assertSame('Export-Test', $data['items'][0]['title']);
        self::assertArrayHasKey('contexts', $data);
    }

    public function testNotesAndTasksPostRequireCsrf(): void
    {
        $this->login();

        self::assertSame(403, $this->post('/aufgaben', ['title' => 'x'], false)->status);
        self::assertSame(403, $this->post('/notizen', ['title' => 'x'], false)->status);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM items'));
    }
}
