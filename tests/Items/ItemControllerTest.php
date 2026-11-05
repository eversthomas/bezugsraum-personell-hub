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

final class ItemControllerTest extends DbTestCase
{
    private const UUID = '3f2b8c1e-7a4d-4e0b-9c6a-1d2e3f4a5b6c';

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

    public function testEverythingRequiresLogin(): void
    {
        foreach (['/erfassen', '/inbox'] as $path) {
            self::assertSame(404, $this->get($path)->status, $path);
        }
        self::assertSame(404, $this->post('/api/v1/items', ['text' => 'x'])->status);
        self::assertSame(404, $this->post('/inbox/1/loeschen')->status);
    }

    public function testCaptureApiIsIdempotentAndValidates(): void
    {
        $this->login();
        $first = $this->post('/api/v1/items', ['text' => 'Im Funkloch', 'origin' => 'text', 'client_uuid' => self::UUID]);
        $second = $this->post('/api/v1/items', ['text' => 'Im Funkloch', 'origin' => 'text', 'client_uuid' => self::UUID]);

        self::assertSame(201, $first->status);
        self::assertSame(200, $second->status);
        self::assertTrue(json_decode($second->body, true)['duplicate']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM items'));
        self::assertSame(422, $this->post('/api/v1/items', ['text' => '  '])->status);
    }

    public function testCaptureApiRequiresCsrf(): void
    {
        $this->login();

        self::assertSame(403, $this->post('/api/v1/items', ['text' => 'x'], false)->status);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM items'));
    }

    public function testCapturePageIsPrefilledFromShareTarget(): void
    {
        $this->login();
        $page = $this->get('/erfassen', ['title' => 'Artikel', 'text' => 'Lesen', 'url' => 'https://example.test/a?x=1&y=2']);

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Artikel', $page->body);
        self::assertStringContainsString('https://example.test/a?x=1&amp;y=2', $page->body);
        self::assertStringContainsString('value="share"', $page->body);
    }

    public function testInboxEscapesContentAndSortFlowWorks(): void
    {
        $this->login();
        $this->post('/api/v1/items', ['text' => "<script>alert(1)</script>\n<b>fett</b>"]);

        $inbox = $this->get('/inbox');
        self::assertStringNotContainsString('<script>alert(1)', $inbox->body);
        self::assertStringNotContainsString('<b>fett</b>', $inbox->body);
        self::assertStringContainsString('&lt;script&gt;', $inbox->body);

        $id = (int) $this->db->fetchValue('SELECT id FROM items');
        $response = $this->post("/inbox/$id/einsortieren", ['type' => 'task', 'context' => 'dienstlich', 'due' => '2026-12-01']);
        self::assertSame(303, $response->status);
        self::assertSame('task', $this->db->fetchValue('SELECT type FROM items WHERE id = ?', [$id]));
        self::assertStringContainsString('Die Inbox ist leer', $this->get('/inbox')->body);
    }

    public function testSortErrorsAndDelete(): void
    {
        $this->login();
        $this->post('/api/v1/items', ['text' => 'A']);
        $id = (int) $this->db->fetchValue('SELECT id FROM items');

        $this->post("/inbox/$id/einsortieren", ['type' => 'task', 'context' => 'gibtsnicht']);
        self::assertStringContainsString('Unbekannter Kontext', $this->get('/inbox')->body);
        self::assertSame('inbox', $this->db->fetchValue('SELECT type FROM items WHERE id = ?', [$id]));

        $this->post("/inbox/$id/loeschen");
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM items'));
        $this->post('/inbox/999/loeschen');
        self::assertStringContainsString('nicht gefunden', $this->get('/inbox')->body);
    }

    public function testFormFallbackWithoutJavascript(): void
    {
        $this->login();
        $response = $this->post('/erfassen', ['text' => 'Ohne JS', 'origin' => 'text']);

        self::assertSame(303, $response->status);
        self::assertSame('Ohne JS', $this->db->fetchValue('SELECT title FROM items'));
    }
}
