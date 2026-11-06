<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Auth\TotpVerifier;
use App\Auth\UserRepository;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\Csrf;
use App\Core\Kernel;
use App\Core\Migrator;
use App\Core\MigratorInterface;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Tests\DbTestCase;

final class UpdatePageTest extends DbTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private Kernel $kernel;
    private Session $session;
    private GateMigrator $gate;
    private string $keyFile;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keyFile = tempnam(sys_get_temp_dir(), 'phkey') ?: '';
        file_put_contents($this->keyFile, Crypto::generateKeyHex());
        $this->session = new Session(null, true);
        $this->gate = new GateMigrator(['009_probe.sql']);
        $this->kernel = new Kernel(dirname(__DIR__, 2), new Config([
            'app' => ['url' => 'https://hub.example.invalid'],
            'crypto' => ['key_path' => $this->keyFile],
        ]));
        $c = $this->kernel->container();
        $c->instance(Session::class, $this->session);
        $c->instance(Db::class, $this->db);
        $c->instance(MigratorInterface::class, $this->gate);
        $this->kernel->boot(require dirname(__DIR__, 2) . '/config/modules.php');
        $this->userId = $c->get(UserRepository::class)->create(
            'thomas',
            password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            $c->get(Crypto::class)->encrypt($c->get(TotpVerifier::class)->generateSecret()),
            gmdate('Y-m-d H:i:s'),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->keyFile)) {
            @unlink($this->keyFile);
        }
    }

    public function testLoggedOutVisitorsGetNoHint(): void
    {
        $update = $this->get('/aktualisieren');
        $login = $this->get('/login');

        self::assertSame(404, $update->status);
        self::assertSame("Nicht gefunden\n", $update->body);
        self::assertStringNotContainsString('Jetzt aktualisieren', $update->body);
        self::assertSame(200, $login->status);
        self::assertStringNotContainsString('Jetzt aktualisieren', $login->body);
        self::assertSame(0, $this->gate->migrated);
    }

    public function testNothingPendingLooksLikeAnUnknownPage(): void
    {
        $this->session->set('uid', $this->userId);
        $this->gate->pending = [];

        $response = $this->get('/aktualisieren');

        self::assertSame(404, $response->status);
        self::assertStringNotContainsString('Jetzt aktualisieren', $response->body);
    }

    public function testSignedInUsersSeeOnlyTheUpdatePageUntilItRuns(): void
    {
        $this->session->set('uid', $this->userId);
        $home = $this->get('/');
        self::assertSame(303, $home->status);
        self::assertSame('/aktualisieren', $home->headers['Location']);

        $blocked = $this->post('/einstellungen/sicherheit/passwort', [
            'current' => self::PASSWORD,
            'new' => 'ein-ganz-neues-passwort',
            'confirm' => 'ein-ganz-neues-passwort',
        ]);
        self::assertSame(303, $blocked->status);
        self::assertSame('/aktualisieren', $blocked->headers['Location']);
        $user = $this->kernel->container()->get(UserRepository::class)->findById($this->userId);
        self::assertNotNull($user);
        self::assertTrue(password_verify(self::PASSWORD, $user->passwordHash));

        $page = $this->get('/aktualisieren');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Sicherung der Datenbank', $page->body);
        self::assertStringContainsString('Jetzt aktualisieren', $page->body);
        self::assertStringNotContainsString('class="shell"', $page->body);
        self::assertSame(0, $this->gate->migrated);

        $denied = $this->call('POST', '/aktualisieren', []);
        self::assertSame(403, $denied->status);
        self::assertSame(0, $this->gate->migrated);

        $done = $this->post('/aktualisieren');
        self::assertSame(200, $done->status);
        self::assertStringContainsString('009_probe.sql', $done->body);
        self::assertStringContainsString('Weiter zum Leitstand', $done->body);
        self::assertSame(1, $this->gate->migrated);

        $after = $this->get('/');
        self::assertSame(200, $after->status);
        self::assertStringContainsString('Leitstand', $after->body);
    }

    public function testABrokenMigrationStaysOnThePageAndHidesTheSql(): void
    {
        $dir = sys_get_temp_dir() . '/ph-upd-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $marker = 'ph_marker_nicht_zeigen';
        file_put_contents($dir . '/009_bad.sql', "CREATE TABLE ph_page_probe (id INT);\nSELECT {$marker} FROM ph_missing_table;\n");
        $this->kernel->container()->instance(MigratorInterface::class, new Migrator($this->db, $dir, 0));
        $this->session->set('uid', $this->userId);
        try {
            $response = $this->post('/aktualisieren');

            self::assertSame(200, $response->status);
            self::assertStringContainsString('009_bad.sql', $response->body);
            self::assertStringContainsString('Spiele die Sicherung der Datenbank ein', $response->body);
            self::assertStringContainsString('Jetzt aktualisieren', $response->body);
            self::assertStringNotContainsString($marker, $response->body);
            self::assertStringNotContainsString('SQLSTATE', $response->body);
            self::assertStringNotContainsString('ph_missing_table', $response->body);
            self::assertStringNotContainsString('Die Anfrage konnte nicht verarbeitet werden.', $response->body);
        } finally {
            $this->db->exec('DROP TABLE IF EXISTS ph_page_probe');
            unlink($dir . '/009_bad.sql');
            rmdir($dir);
        }
    }

    public function testABusyMigrationSaysSoWithoutFailingTheRequest(): void
    {
        $dir = sys_get_temp_dir() . '/ph-upd-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/009_wait.sql', "SELECT 1;\n");
        $other = Db::connect(new Config(['db' => [
            'host' => getenv('PH_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('PH_TEST_DB_PORT') ?: 3306),
            'name' => getenv('PH_TEST_DB_NAME') ?: 'personal_hub_test',
            'user' => getenv('PH_TEST_DB_USER') ?: 'ph',
            'password' => getenv('PH_TEST_DB_PASSWORD') ?: 'ph',
            'charset' => 'utf8mb4',
        ]]));
        $this->kernel->container()->instance(MigratorInterface::class, new Migrator($this->db, $dir, 0));
        $this->session->set('uid', $this->userId);
        $other->fetchValue('SELECT GET_LOCK(?, 0)', [Migrator::LOCK_NAME]);
        try {
            $response = $this->post('/aktualisieren');

            self::assertSame(200, $response->status);
            self::assertStringContainsString('Aktualisierung läuft bereits.', $response->body);
            self::assertStringContainsString('Jetzt aktualisieren', $response->body);
            self::assertStringNotContainsString('009_wait.sql', $response->body);
            self::assertStringNotContainsString('Die Anfrage konnte nicht verarbeitet werden.', $response->body);
        } finally {
            $other->fetchValue('SELECT RELEASE_LOCK(?)', [Migrator::LOCK_NAME]);
            unlink($dir . '/009_wait.sql');
            rmdir($dir);
        }
    }

    private function get(string $path): Response
    {
        return $this->call('GET', $path);
    }

    /** @param array<string, mixed> $post */
    private function post(string $path, array $post = []): Response
    {
        $post['_csrf'] = $this->kernel->container()->get(Csrf::class)->token();

        return $this->call('POST', $path, $post);
    }

    /** @param array<string, mixed> $post */
    private function call(string $method, string $path, array $post = []): Response
    {
        return $this->kernel->handle(new Request($method, $path, post: $post, server: ['HTTPS' => 'on']));
    }
}

final class GateMigrator implements MigratorInterface
{
    public int $migrated = 0;

    /** @param list<string> $pending */
    public function __construct(public array $pending)
    {
    }

    public function pending(): array
    {
        return $this->pending;
    }

    public function migrate(): array
    {
        $this->migrated++;
        $applied = $this->pending;
        $this->pending = [];

        return $applied;
    }
}
