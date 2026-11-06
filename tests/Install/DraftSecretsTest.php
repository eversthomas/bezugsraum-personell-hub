<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Core\Config;
use App\Core\CronCredentialStore;
use App\Core\CronCredentials;
use App\Core\Crypto;
use App\Core\Request;
use App\Core\RestrictedFile;
use App\Core\Session;
use App\Install\InstallActions;
use App\Install\InstallCode;
use App\Install\InstallDraft;
use App\Install\InstallerApp;
use App\Install\InstallFinisher;
use PHPUnit\Framework\TestCase;

final class DraftSecretsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ph-draft-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage', 0700, true);
        mkdir($this->dir . '/config', 0700, true);
        symlink(dirname(__DIR__, 2) . '/templates', $this->dir . '/templates');
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
        $this->remove($this->dir);
    }

    public function testAccountPasswordIsStoredOnlyAsHash(): void
    {
        $sessionDir = $this->dir . '/storage/sessions';
        mkdir($sessionDir, 0700);
        $session = new Session($sessionDir, false);
        $password = 'benutzer-passwort';
        $draft = new InstallDraft($session);
        $draft->saveAccount('tom', $password, 'GEHEIMNIS');
        $account = $draft->account();

        self::assertIsArray($account);
        self::assertTrue(password_verify($password, $account['password_hash']));
        self::assertNotSame($password, $account['password_hash']);
        session_write_close();
        $raw = $this->sessionFile($sessionDir);
        self::assertStringNotContainsString($password, $raw);
        self::assertStringContainsString('GEHEIMNIS', $raw);
    }

    public function testDraftExpiresAfterThirtyMinutesButKeepsTheUnlock(): void
    {
        $now = 1_700_000_000;
        $session = new Session(null, false);
        $session->set('install_unlocked', true);
        $draft = new InstallDraft($session, static function () use (&$now): int {
            return $now;
        });
        $draft->rememberProbe('127.0.0.1', 3306, 'personal_hub', 'hub', 'datenbank-geheim');
        $draft->save($this->database('datenbank-geheim'));
        $draft->saveAccount('tom', 'benutzer-passwort', 'GEHEIMNIS');

        $now += InstallDraft::LIFETIME_SECONDS;
        $values = $draft->get();
        $account = $draft->account();
        self::assertIsArray($values);
        self::assertIsArray($account);
        self::assertSame('datenbank-geheim', $values['password']);
        self::assertTrue(password_verify('benutzer-passwort', $account['password_hash']));
        self::assertTrue($draft->probeMatches('127.0.0.1', 3306, 'personal_hub', 'hub', 'datenbank-geheim'));

        $now += 1;
        self::assertNull($draft->get());
        self::assertNull($draft->account());
        self::assertFalse($draft->saved());
        self::assertFalse($draft->probeMatches('127.0.0.1', 3306, 'personal_hub', 'hub', 'datenbank-geheim'));
        self::assertTrue($session->get('install_unlocked'));
    }

    public function testExpiredDraftReturnsToTheDatabaseForm(): void
    {
        $session = new Session(null, false);
        $session->set('install_unlocked', true);
        $draft = new InstallDraft($session);
        $draft->save($this->database('datenbank-geheim'));
        $draft->saveAccount('tom', 'benutzer-passwort', 'GEHEIMNIS-QR');
        $session->set('install_draft_until', 1);
        $app = new InstallerApp($this->dir, $session);

        $response = $app->handle(new Request('GET', '/einrichten'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Als Nächstes der Datenbankzugang.', $response->body);
        self::assertStringNotContainsString('Der Datenbankzugang ist gemerkt.', $response->body);
        self::assertStringNotContainsString('Einrichtungscode', $response->body);
        self::assertStringNotContainsString('datenbank-geheim', $response->body);
        self::assertStringNotContainsString('benutzer-passwort', $response->body);
        self::assertStringNotContainsString('GEHEIMNIS-QR', $response->body);
        self::assertTrue($session->get('install_unlocked'));
        self::assertNull($draft->get());
        self::assertNull($draft->account());
    }

    public function testSensitiveFilesAreOwnerOnlyEvenWithALooseUmask(): void
    {
        $previous = umask(0022);
        try {
            $path = $this->dir . '/storage/restricted.txt';
            RestrictedFile::put($path, "geheim\n");
            self::assertSame(0600, fileperms($path) & 0777);

            $code = new InstallCode(
                $this->dir . '/storage/install-code.txt',
                $this->dir . '/storage/install-attempts.txt',
            );
            self::assertTrue($code->ensure());
            self::assertSame(0600, fileperms($this->dir . '/storage/install-code.txt') & 0777);

            $cron = $this->dir . '/storage/cron-credentials.php';
            (new CronCredentialStore($cron))->save(new CronCredentials(
                'https://hub.example.test/cron/token',
                'cron',
                'cron-passwort-test',
                hash('sha256', 'token'),
                password_hash('cron-passwort-test', PASSWORD_DEFAULT),
            ));
            self::assertSame(0600, fileperms($cron) & 0777);
            self::assertStringNotContainsString('cron-passwort-test', (string) file_get_contents($cron));

            (new InstallFinisher($this->dir, new DraftSecretActions(), $code))->finish(
                $this->database('datenbank-geheim'),
                'tom',
                password_hash('benutzer-passwort', PASSWORD_DEFAULT),
                'GEHEIMNIS',
            );
            self::assertSame(0600, fileperms($this->dir . '/config/keys/app.key') & 0777);
            self::assertSame(0600, fileperms($this->dir . '/config/config.php') & 0777);
            self::assertSame(0600, fileperms($this->dir . '/storage/install.lock') & 0777);
        } finally {
            umask($previous);
        }
    }

    /** @return array{host: string, port: int, name: string, user: string, password: string, url: string, timezone: string} */
    private function database(string $password): array
    {
        return [
            'host' => '127.0.0.1',
            'port' => 3306,
            'name' => 'personal_hub',
            'user' => 'hub',
            'password' => $password,
            'url' => 'https://hub.example.test',
            'timezone' => 'Europe/Berlin',
        ];
    }

    private function sessionFile(string $dir): string
    {
        $files = glob($dir . '/sess_*') ?: [];
        self::assertCount(1, $files);

        return (string) file_get_contents($files[0]);
    }

    private function remove(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            unlink($dir);

            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->remove($dir . '/' . $entry);
        }
        rmdir($dir);
    }
}

final class DraftSecretActions implements InstallActions
{
    public function migrate(Config $config): void
    {
    }

    public function createUser(Config $config, Crypto $crypto, string $username, string $passwordHash, string $totpSecret): void
    {
    }

    public function removeUser(Config $config, string $username): void
    {
    }

    public function vapid(): array
    {
        return ['publicKey' => 'vapid-public-test', 'privateKey' => 'vapid-private-test'];
    }

    public function cron(string $appUrl): CronCredentials
    {
        return new CronCredentials(
            $appUrl . '/cron/token',
            'cron',
            'cron-passwort-test',
            hash('sha256', 'token'),
            password_hash('cron-passwort-test', PASSWORD_DEFAULT),
        );
    }
}
