<?php

declare(strict_types=1);

namespace Tests\Links;

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

final class LinkPagesTest extends DbTestCase
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

    private function get(string $path): Response
    {
        return $this->kernel->handle(new Request('GET', $path, server: ['HTTPS' => 'on']));
    }

    public function testRequiresLoginAndCsrf(): void
    {
        self::assertSame(404, $this->get('/einstellungen/links')->status);
        self::assertSame(404, $this->post('/einstellungen/links', ['label' => 'x', 'url' => 'https://a.test/'])->status);

        $this->login();
        self::assertSame(403, $this->post('/einstellungen/links', ['label' => 'x', 'url' => 'https://a.test/'], false)->status);
    }

    public function testLinkAppearsInSidebarWithSafeAttributes(): void
    {
        $this->login();
        $this->post('/einstellungen/links', ['label' => 'Outlook <AWO>', 'url' => 'https://outlook.office.com/mail/', 'context' => 'dienstlich']);

        $page = $this->get('/aufgaben');
        self::assertStringContainsString('Schnellzugriff', $page->body);
        self::assertStringContainsString('href="https://outlook.office.com/mail/"', $page->body);
        self::assertStringContainsString('target="_blank" rel="noopener noreferrer"', $page->body);
        self::assertStringContainsString('src-blue', $page->body, 'Symbol in Kontextfarbe');
        self::assertStringContainsString('Outlook &lt;AWO&gt;', $page->body);
    }

    public function testInvalidLinkShowsError(): void
    {
        $this->login();
        $this->post('/einstellungen/links', ['label' => 'Böse', 'url' => 'javascript:alert(1)']);

        self::assertStringContainsString('Nur https-Adressen', $this->get('/einstellungen/links')->body);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM quick_links'));
    }

    public function testRemoveAndMove(): void
    {
        $this->login();
        $this->post('/einstellungen/links', ['label' => 'A', 'url' => 'https://a.test/']);
        $this->post('/einstellungen/links', ['label' => 'B', 'url' => 'https://b.test/']);
        $b = (int) $this->db->fetchValue("SELECT id FROM quick_links WHERE label = 'B'");

        $this->post("/einstellungen/links/$b/verschieben", ['direction' => 'up']);
        self::assertSame('B', $this->db->fetchValue('SELECT label FROM quick_links ORDER BY sort, id LIMIT 1'));

        $this->post("/einstellungen/links/$b/entfernen");
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM quick_links'));
    }
}
