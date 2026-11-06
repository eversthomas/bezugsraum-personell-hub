<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Core\Config;
use App\Core\Db;
use App\Core\Request;
use App\Core\Session;
use App\Install\DatabaseProbe;
use App\Install\InstallCode;
use App\Install\InstallerApp;
use App\Install\InstallTransport;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    private string $dir;

    private Session $session;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ph-transport-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage/sessions', 0700, true);
        mkdir($this->dir . '/config', 0700, true);
        symlink(dirname(__DIR__, 2) . '/templates', $this->dir . '/templates');
        $this->session = new Session(null, false);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        if (is_link($this->dir . '/templates')) {
            unlink($this->dir . '/templates');
        }
        if (!is_dir($this->dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (is_link($path)) {
                unlink($path);
                continue;
            }
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->dir);
    }

    public function testCleartextOnAPublicHostStopsBeforeTheCode(): void
    {
        $response = $this->app()->handle(new Request('GET', '/', server: [
            'HTTP_HOST' => 'hub.example.test',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'REMOTE_ADDR' => '203.0.113.9',
        ]));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('nur über HTTPS', $response->body);
        self::assertStringNotContainsString('<form', $response->body);
        self::assertStringNotContainsString('install-code.txt', $response->body);
    }

    public function testCleartextOnAPublicHostDoesNotAcceptTheCode(): void
    {
        $page = $this->app()->handle(new Request('GET', '/', server: ['HTTP_HOST' => '127.0.0.1']));
        $stored = trim((string) file_get_contents($this->dir . '/storage/install-code.txt'));
        $token = $this->tokenFrom($page->body);

        $response = $this->app()->handle(new Request('POST', '/einrichten/code', post: [
            '_csrf' => $token,
            'code' => $stored,
        ], server: [
            'HTTP_HOST' => 'hub.example.test',
            'REMOTE_ADDR' => '203.0.113.9',
        ]));

        self::assertStringContainsString('nur über HTTPS', $response->body);
        self::assertNotSame(true, $this->session->get('install_unlocked'));
    }

    public function testLocalCleartextAndHttpsMayContinue(): void
    {
        foreach (['localhost', 'localhost:8080', '127.0.0.1', '127.0.0.1:8080', '[::1]', '[::1]:8080'] as $host) {
            $response = $this->app()->handle(new Request('GET', '/einrichten', server: ['HTTP_HOST' => $host]));
            self::assertStringContainsString('<form', $response->body, $host);
        }

        $secure = $this->app()->handle(new Request('GET', '/einrichten', server: [
            'HTTP_HOST' => 'hub.example.test',
            'HTTPS' => 'on',
        ]));
        self::assertStringContainsString('<form', $secure->body);
    }

    public function testHttpAppUrlIsOnlyAllowedForLocalHosts(): void
    {
        $seen = new ProbeCounter();
        $app = $this->unlocked($seen);
        $token = $this->tokenFrom($app->handle(new Request('GET', '/einrichten', server: ['HTTPS' => 'on', 'HTTP_HOST' => 'hub.example.test']))->body);

        $rejected = $app->handle(new Request('POST', '/einrichten/datenbank/testen', post: $this->fields($token, 'http://hub.example.test'), server: [
            'HTTPS' => 'on',
            'HTTP_HOST' => 'hub.example.test',
        ]));
        self::assertStringContainsString('nur für localhost zulässig', $rejected->body);
        self::assertSame(0, $seen->calls);

        $accepted = $app->handle(new Request('POST', '/einrichten/datenbank/testen', post: $this->fields($this->tokenFrom($rejected->body), 'http://[::1]:8080'), server: [
            'HTTPS' => 'on',
            'HTTP_HOST' => 'hub.example.test',
        ]));
        self::assertStringContainsString('Die Verbindung steht.', $accepted->body);
        self::assertSame(1, $seen->calls);
        self::assertTrue((new InstallTransport())->allows(new Request('GET', '/', server: ['HTTP_HOST' => '::1'])));
    }

    public function testAcceptedCodeRenewsTheSessionId(): void
    {
        $session = new Session($this->dir . '/storage/sessions', false);
        $app = new InstallerApp($this->dir, $session);
        $page = $app->handle(new Request('GET', '/', server: ['HTTP_HOST' => '127.0.0.1']));
        $before = session_id();
        self::assertNotSame('', $before);
        $stored = trim((string) file_get_contents($this->dir . '/storage/install-code.txt'));

        $wrong = $app->handle(new Request('POST', '/einrichten/code', post: [
            '_csrf' => $this->tokenFrom($page->body),
            'code' => 'falsch',
        ], server: ['HTTP_HOST' => '127.0.0.1']));
        self::assertSame($before, session_id());

        $done = $app->handle(new Request('POST', '/einrichten/code', post: [
            '_csrf' => $this->tokenFrom($wrong->body),
            'code' => $stored,
        ], server: ['HTTP_HOST' => '127.0.0.1']));

        self::assertSame(303, $done->status);
        self::assertNotSame($before, session_id());
        self::assertTrue($session->get('install_unlocked'));
    }

    public function testProbeStopsAfterThreeSeconds(): void
    {
        $options = Db::options(DatabaseProbe::CONNECT_TIMEOUT_SECONDS);
        self::assertSame(3, $options[\PDO::ATTR_TIMEOUT]);
        self::assertArrayNotHasKey(\PDO::ATTR_TIMEOUT, Db::options(null));

        $started = microtime(true);
        $ok = (new DatabaseProbe())->succeeds('192.0.2.1', 9, 'personal_hub', 'hub', 'geheim');
        $elapsed = microtime(true) - $started;

        self::assertFalse($ok);
        self::assertLessThan(8.0, $elapsed);
    }

    private function app(): InstallerApp
    {
        return new InstallerApp($this->dir, $this->session);
    }

    private function unlocked(ProbeCounter $seen): InstallerApp
    {
        $this->session->set('install_unlocked', true);
        (new InstallCode($this->dir . '/storage/install-code.txt', $this->dir . '/storage/install-attempts.txt'))->ensure();

        return new InstallerApp($this->dir, $this->session, new DatabaseProbe(static function (Config $config) use ($seen): bool {
            $seen->calls++;

            return true;
        }));
    }

    /** @return array<string, string> */
    private function fields(string $token, string $url): array
    {
        return [
            '_csrf' => $token,
            'host' => '127.0.0.1',
            'port' => '3306',
            'name' => 'personal_hub',
            'user' => 'hub',
            'password' => 'geheim',
            'url' => $url,
            'timezone' => 'Europe/Berlin',
        ];
    }

    private function tokenFrom(string $html): string
    {
        self::assertSame(1, preg_match('/name="_csrf" value="([^"]+)"/', $html, $match));

        return $match[1];
    }
}

final class ProbeCounter
{
    public int $calls = 0;
}
