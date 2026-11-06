<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\AuthService;
use App\Auth\TotpVerifier;
use App\Auth\TrustedDeviceRepository;
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

final class SecurityPagesTest extends DbTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private Kernel $kernel;
    private Session $session;
    private string $keyFile;
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
        $this->kernel->container()->get(AuthService::class)->logout();
        self::assertSame(404, $this->get('/einstellungen/sicherheit')->status);
        self::assertSame(404, $this->post('/einstellungen/sicherheit/geraete/alle/entfernen')->status);
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
}
