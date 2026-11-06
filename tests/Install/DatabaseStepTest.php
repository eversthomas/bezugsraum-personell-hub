<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Core\Config;
use App\Core\Request;
use App\Core\Session;
use App\Install\DatabaseProbe;
use App\Install\InstallDraft;
use App\Install\InstallerApp;
use PHPUnit\Framework\TestCase;

final class DatabaseStepTest extends TestCase
{
    private string $dir;
    private Session $session;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ph-dbstep-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage', 0700, true);
        mkdir($this->dir . '/config', 0700, true);
        $root = dirname(__DIR__, 2);
        symlink($root . '/templates', $this->dir . '/templates');
        $this->session = new Session(null, false);
    }

    protected function tearDown(): void
    {
        if (is_link($this->dir . '/templates')) {
            unlink($this->dir . '/templates');
        }
        foreach (glob($this->dir . '/storage/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir . '/storage');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function testDatabaseStepIsHiddenBeforeTheCode(): void
    {
        $app = $this->app(new DatabaseProbe(static fn (Config $config): bool => throw new \RuntimeException('nicht verbinden')));
        $response = $app->handle(new Request('POST', '/einrichten/datenbank/testen', post: $this->fields($this->csrf($app))));

        self::assertSame(404, $response->status);
        self::assertFileDoesNotExist($this->dir . '/config/config.php');
    }

    public function testFailedProbeWritesNothingAndDoesNotRevealThePassword(): void
    {
        $password = 'sichtbar-geheim';
        $app = $this->unlockedApp(new DatabaseProbe(static fn (Config $config): bool => throw new \RuntimeException('Zugang ' . $config->string('db.password'))));
        $token = $this->csrf($app);

        $response = $app->handle(new Request('POST', '/einrichten/datenbank/testen', post: $this->fields($token, $password)));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Die Verbindung ist fehlgeschlagen.', $response->body);
        self::assertStringNotContainsString($password, $response->body);
        self::assertNull((new InstallDraft($this->session))->get());
        self::assertFileDoesNotExist($this->dir . '/config/config.php');
    }

    public function testSaveWithoutProbeIsRejected(): void
    {
        $app = $this->unlockedApp(new DatabaseProbe(static fn (Config $config): bool => throw new \RuntimeException('keine Verbindung')));
        $token = $this->csrf($app);

        $response = $app->handle(new Request('POST', '/einrichten/datenbank', post: $this->fields($token, 'geheim')));

        self::assertStringContainsString('Bitte die Verbindung zuerst prüfen.', $response->body);
        self::assertNull((new InstallDraft($this->session))->get());
        self::assertFileDoesNotExist($this->dir . '/config/config.php');
    }

    public function testSuccessfulProbeCanBeRememberedInTheSessionOnly(): void
    {
        $password = 'sitzung-geheim';
        $connected = false;
        $probe = new DatabaseProbe(static function (Config $config) use (&$connected): bool {
            $connected = $config->string('db.password') === 'sitzung-geheim';

            return $connected;
        });
        $app = $this->unlockedApp($probe);
        $token = $this->csrf($app);
        $tested = $app->handle(new Request('POST', '/einrichten/datenbank/testen', post: $this->fields($token, $password)));
        self::assertTrue($connected);
        self::assertStringContainsString('Die Verbindung steht.', $tested->body);
        self::assertStringNotContainsString($password, $tested->body);
        self::assertFileDoesNotExist($this->dir . '/config/config.php');

        $saved = $app->handle(new Request('POST', '/einrichten/datenbank', post: $this->fields($this->tokenFrom($tested->body), $password)));
        self::assertSame(303, $saved->status);
        $page = $app->handle(new Request('GET', '/einrichten'));
        $draft = (new InstallDraft($this->session))->get();

        self::assertIsArray($draft);
        self::assertSame($password, $draft['password']);
        self::assertSame('Europe/Berlin', $draft['timezone']);
        self::assertSame('https://hub.example.test', $draft['url']);
        self::assertStringNotContainsString($password, $page->body);
        self::assertStringContainsString('Eine Konfigurationsdatei gibt es noch nicht.', $page->body);
        self::assertFileDoesNotExist($this->dir . '/config/config.php');
    }

    public function testRealDatabaseProbeOpensAConnectionWithoutWritingConfig(): void
    {
        $probe = new DatabaseProbe();
        $password = getenv('PH_TEST_DB_PASSWORD') ?: 'ph';
        $ok = $probe->succeeds(
            getenv('PH_TEST_DB_HOST') ?: '127.0.0.1',
            (int) (getenv('PH_TEST_DB_PORT') ?: 3306),
            getenv('PH_TEST_DB_NAME') ?: 'personal_hub_test',
            getenv('PH_TEST_DB_USER') ?: 'ph',
            $password,
        );
        if (!$ok) {
            self::markTestSkipped('Testdatenbank nicht erreichbar.');
        }

        self::assertFileDoesNotExist($this->dir . '/config/config.php');
    }

    /** @return array<string, string> */
    private function fields(string $token, string $password = ''): array
    {
        return [
            '_csrf' => $token,
            'host' => '127.0.0.1',
            'port' => '3306',
            'name' => 'personal_hub',
            'user' => 'hub',
            'password' => $password,
            'url' => 'https://hub.example.test',
            'timezone' => 'Europe/Berlin',
        ];
    }

    private function unlockedApp(DatabaseProbe $probe): InstallerApp
    {
        $this->session->set('install_unlocked', true);

        return $this->app($probe);
    }

    private function app(DatabaseProbe $probe): InstallerApp
    {
        return new InstallerApp($this->dir, $this->session, $probe);
    }

    private function csrf(InstallerApp $app): string
    {
        $response = $app->handle(new Request('GET', '/einrichten', server: ['HTTPS' => 'on', 'HTTP_HOST' => 'hub.example.test']));

        return $this->tokenFrom($response->body);
    }

    private function tokenFrom(string $html): string
    {
        self::assertSame(1, preg_match('/name="_csrf" value="([^"]+)"/', $html, $match));

        return $match[1];
    }
}
