<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\AuthService;
use App\Auth\TotpVerifier;
use App\Auth\TrustedDeviceRepository;
use App\Auth\UserRepository;
use App\Core\Config;
use App\Core\CronCredentialStore;
use App\Core\CronCredentials;
use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Tests\DbTestCase;

final class SecurityPagesTest extends DbTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private Kernel $kernel;
    private Session $session;
    private string $keyFile;
    private string $cronFile;
    private int $userId;

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
        $this->cronFile = sys_get_temp_dir() . '/ph-sec-cron-' . bin2hex(random_bytes(4)) . '.php';
        $c->instance(CronCredentialStore::class, new CronCredentialStore($this->cronFile));
        $this->userId = $c->get(UserRepository::class)->create(
            'thomas',
            password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            $c->get(Crypto::class)->encrypt($c->get(TotpVerifier::class)->generateSecret()),
            gmdate('Y-m-d H:i:s'),
        );
        $this->session->set('uid', $this->userId);
    }

    protected function tearDown(): void
    {
        if (isset($this->keyFile)) {
            @unlink($this->keyFile);
        }
        if (isset($this->cronFile)) {
            @unlink($this->cronFile);
        }
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

    private function addDevice(string $label = 'Chrome auf Android'): int
    {
        return $this->kernel->container()->get(TrustedDeviceRepository::class)->create(
            $this->userId,
            hash('sha256', $label),
            $label,
            gmdate('Y-m-d H:i:s'),
            gmdate('Y-m-d H:i:s', time() + 86400),
        );
    }

    public function testRequiresLoginAndCsrf(): void
    {
        self::assertSame(403, $this->post('/einstellungen/sicherheit/passwort', [], false)->status);
        self::assertSame(403, $this->post('/einstellungen/sicherheit/cron', [], false)->status);
        $this->kernel->container()->get(AuthService::class)->logout();
        self::assertSame(404, $this->get('/einstellungen/sicherheit')->status);
        self::assertSame(404, $this->post('/einstellungen/sicherheit/geraete/alle/entfernen')->status);
        self::assertSame(404, $this->post('/einstellungen/sicherheit/cron')->status);
    }

    public function testDevicesAreListedAndCanBeRevokedIndividually(): void
    {
        $a = $this->addDevice('Chrome auf Android');
        $this->addDevice('Firefox auf Windows');

        $page = $this->get('/einstellungen/sicherheit');
        self::assertStringContainsString('Chrome auf Android', $page->body);
        self::assertStringContainsString('Firefox auf Windows', $page->body);

        $this->post("/einstellungen/sicherheit/geraete/$a/entfernen");
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM trusted_devices'));

        $this->post('/einstellungen/sicherheit/geraete/alle/entfernen');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM trusted_devices'));
    }

    public function testCannotRevokeUnknownDevice(): void
    {
        $this->post('/einstellungen/sicherheit/geraete/999/entfernen');

        self::assertStringContainsString('nicht gefunden', $this->get('/einstellungen/sicherheit')->body);
    }

    public function testPasswordChangeRevokesDevicesAndWorks(): void
    {
        $this->addDevice();
        $this->post('/einstellungen/sicherheit/passwort', ['current' => self::PASSWORD, 'new' => 'ein-ganz-neues-passwort', 'confirm' => 'ein-ganz-neues-passwort']);

        $user = $this->kernel->container()->get(UserRepository::class)->findById($this->userId);
        self::assertNotNull($user);
        self::assertTrue(password_verify('ein-ganz-neues-passwort', $user->passwordHash));
        self::assertFalse(password_verify(self::PASSWORD, $user->passwordHash));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM trusted_devices'));
        self::assertStringContainsString('Passwort geändert', $this->get('/einstellungen/sicherheit')->body);
    }

    public function testPasswordChangeValidation(): void
    {
        $cases = [
            ['falsch', 'ein-ganz-neues-passwort', 'ein-ganz-neues-passwort', 'stimmt nicht'],
            [self::PASSWORD, 'kurz', 'kurz', 'mindestens'],
            [self::PASSWORD, 'ein-ganz-neues-passwort', 'etwas-anderes-12345', 'stimmen nicht überein'],
            [self::PASSWORD, self::PASSWORD, self::PASSWORD, 'unterscheiden'],
        ];
        foreach ($cases as [$current, $new, $confirm, $message]) {
            $this->post('/einstellungen/sicherheit/passwort', ['current' => $current, 'new' => $new, 'confirm' => $confirm]);
            self::assertStringContainsString($message, $this->get('/einstellungen/sicherheit')->body);
        }
        $user = $this->kernel->container()->get(UserRepository::class)->findById($this->userId);
        self::assertNotNull($user);
        self::assertTrue(password_verify(self::PASSWORD, $user->passwordHash), 'Passwort blieb unverändert');
    }

    public function testCronRegenerateReplacesTheHashFileAndShowsTheSecretOnce(): void
    {
        $store = $this->kernel->container()->get(CronCredentialStore::class);
        $store->save(CronCredentials::generate('https://hub.example.invalid'));
        $before = $store->read();
        self::assertIsArray($before);

        $this->post('/einstellungen/sicherheit/cron', ['password' => 'falsch']);
        self::assertSame($before['token_hash'], $store->read()['token_hash'] ?? null);
        self::assertStringContainsString('stimmt nicht', $this->get('/einstellungen/sicherheit')->body);

        $this->post('/einstellungen/sicherheit/cron', ['password' => self::PASSWORD]);
        $page = $this->get('/einstellungen/sicherheit');
        $after = $store->read();
        self::assertNotSame($before['token_hash'], $after['token_hash']);
        self::assertSame(0600, fileperms($this->cronFile) & 0777);
        self::assertStringContainsString('nur dieses eine Mal', $page->body);
        self::assertSame(1, preg_match('/Passwort<\/span><code class="secret">([^<]+)</', $page->body, $match));
        self::assertStringNotContainsString($match[1], (string) file_get_contents($this->cronFile));
        self::assertTrue(password_verify($match[1], $after['basic_password_hash']));
        self::assertStringNotContainsString($match[1], $this->get('/einstellungen/sicherheit')->body);
    }

    public function testWrongPasswordsShareTheLoginLockAndEndTheSession(): void
    {
        $store = $this->kernel->container()->get(CronCredentialStore::class);
        $store->save(CronCredentials::generate('https://hub.example.invalid'));
        $before = $store->read();
        self::assertIsArray($before);

        for ($i = 0; $i < AuthService::MAX_FAILURES - 2; ++$i) {
            $this->post('/einstellungen/sicherheit/cron', ['password' => 'falsch-' . $i]);
        }
        $this->post('/einstellungen/sicherheit/passwort', [
            'current' => 'falsch-konto',
            'new' => 'ein-ganz-neues-passwort',
            'confirm' => 'ein-ganz-neues-passwort',
        ]);
        $this->post('/einstellungen/sicherheit/cron', ['password' => 'noch-falsch']);

        self::assertSame(AuthService::MAX_FAILURES, $this->failures());
        self::assertSame($this->userId, $this->session->get('uid'));
        $unchanged = $store->read();
        self::assertIsArray($unchanged);
        self::assertSame($before['token_hash'], $unchanged['token_hash']);

        $this->post('/einstellungen/sicherheit/cron', ['password' => self::PASSWORD]);

        self::assertNull($this->session->get('uid'));
        $locked = $store->read();
        self::assertIsArray($locked);
        self::assertSame($before['token_hash'], $locked['token_hash']);
        self::assertSame(AuthService::MAX_FAILURES, $this->failures());
        self::assertSame(404, $this->post('/einstellungen/sicherheit/cron', ['password' => self::PASSWORD])->status);

        $this->session->set('uid', $this->userId);
        $this->post('/einstellungen/sicherheit/passwort', [
            'current' => self::PASSWORD,
            'new' => 'ein-ganz-neues-passwort',
            'confirm' => 'ein-ganz-neues-passwort',
        ]);
        $user = $this->kernel->container()->get(UserRepository::class)->findById($this->userId);
        self::assertNotNull($user);
        self::assertTrue(password_verify(self::PASSWORD, $user->passwordHash));
        self::assertNull($this->session->get('uid'));
        self::assertSame(404, $this->get('/einstellungen/sicherheit')->status);
    }

    public function testACorrectPasswordClearsTheFailureCounters(): void
    {
        for ($i = 0; $i < AuthService::MAX_FAILURES - 1; ++$i) {
            $this->post('/einstellungen/sicherheit/cron', ['password' => 'falsch-' . $i]);
        }
        self::assertSame(AuthService::MAX_FAILURES - 1, $this->failures());

        $this->post('/einstellungen/sicherheit/cron', ['password' => self::PASSWORD]);

        self::assertSame(0, $this->failures());
        self::assertSame($this->userId, $this->session->get('uid'));
        for ($i = 0; $i < AuthService::MAX_FAILURES - 1; ++$i) {
            $this->post('/einstellungen/sicherheit/cron', ['password' => 'danach-' . $i]);
        }
        self::assertSame(AuthService::MAX_FAILURES - 1, $this->failures());
        self::assertNotNull($this->session->get('uid'));
    }

    private function failures(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM login_attempts WHERE success = 0');
    }
}
