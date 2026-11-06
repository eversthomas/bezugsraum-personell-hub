<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Auth\TotpVerifier;
use App\Core\Config;
use App\Core\CronCredentials;
use App\Core\Crypto;
use App\Core\Request;
use App\Core\Session;
use App\Install\InstallActions;
use App\Install\InstallCode;
use App\Install\InstallDraft;
use App\Install\InstallException;
use App\Install\InstallFinisher;
use App\Install\InstallerApp;
use PHPUnit\Framework\TestCase;

final class FinishStepTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ph-finish-' . bin2hex(random_bytes(4));
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

    public function testFailedMigrationRemovesOnlyAKeyFromThisAttempt(): void
    {
        $actions = new RecordingInstallActions();
        $actions->failMigrate = true;
        $finisher = $this->finisher($actions);

        try {
            $finisher->finish($this->database("a'; system('id'); //"), 'tom', 'benutzer-passwort', 'GEHEIMNIS');
            self::fail('Abschluss hätte scheitern müssen.');
        } catch (InstallException $e) {
            self::assertStringNotContainsString("system('id')", $e->getMessage());
        }

        self::assertFileDoesNotExist($this->dir . '/config/config.php');
        self::assertFileDoesNotExist($this->dir . '/config/keys/app.key');
    }

    public function testExistingKeyStaysWhenALaterStepFails(): void
    {
        $key = $this->dir . '/config/keys/app.key';
        mkdir(dirname($key), 0700);
        file_put_contents($key, Crypto::generateKeyHex() . "\n");
        chmod($key, 0600);
        $before = (string) file_get_contents($key);
        $actions = new RecordingInstallActions();
        $actions->failMigrate = true;

        try {
            $this->finisher($actions)->finish($this->database('datenbank-test-passwort'), 'tom', 'benutzer-passwort', 'GEHEIMNIS');
            self::fail('Abschluss hätte scheitern müssen.');
        } catch (InstallException) {
        }

        self::assertSame($before, (string) file_get_contents($key));
        self::assertFileDoesNotExist($this->dir . '/config/config.php');
    }

    public function testFailedConfigWriteRollsBackTheUserAndTheNewKey(): void
    {
        mkdir($this->dir . '/config/config.php', 0700);
        $actions = new RecordingInstallActions();

        try {
            $this->finisher($actions)->finish($this->database('datenbank-test-passwort'), 'tom', 'benutzer-passwort', 'GEHEIMNIS');
            self::fail('Abschluss hätte scheitern müssen.');
        } catch (InstallException) {
        }

        self::assertTrue($actions->userCreated);
        self::assertTrue($actions->userRemoved);
        self::assertFileDoesNotExist($this->dir . '/config/keys/app.key');
        self::assertFileDoesNotExist($this->dir . '/storage/cron-credentials.php');
        self::assertFileDoesNotExist($this->dir . '/config/config.php.tmp');
        self::assertDirectoryExists($this->dir . '/config/config.php');
    }

    public function testSuccessfulFinishWritesConfigLastAndKeepsSecretsOutOfThePageSourceShape(): void
    {
        $actions = new RecordingInstallActions();
        $password = "a'; system('id'); //";
        $result = $this->finisher($actions)->finish($this->database($password), 'tom', 'benutzer-passwort', 'GEHEIMNIS');
        $config = require $this->dir . '/config/config.php';

        self::assertSame($password, $config['db']['password']);
        self::assertSame('https://hub.example.test', $config['app']['url']);
        self::assertFalse($config['app']['trust_proxy']);
        self::assertArrayNotHasKey('cron', $config);
        $cronFile = (string) file_get_contents($this->dir . '/storage/cron-credentials.php');
        self::assertStringNotContainsString('cron-passwort-test', $cronFile);
        self::assertStringNotContainsString('cron-token-test-wert', $cronFile);
        self::assertSame(0600, fileperms($this->dir . '/storage/cron-credentials.php') & 0777);
        $stored = require $this->dir . '/storage/cron-credentials.php';
        self::assertSame('cron', $stored['basic_user']);
        self::assertSame(hash('sha256', 'cron-token-test-wert'), $stored['token_hash']);
        self::assertTrue(password_verify('cron-passwort-test', $stored['basic_password_hash']));
        self::assertSame('vapid-public-test', $config['vapid']['public_key']);
        self::assertStringContainsString("__DIR__ . '/keys/app.key'", (string) file_get_contents($this->dir . '/config/config.php'));
        $configPhp = (string) file_get_contents($this->dir . '/config/config.php');
        self::assertStringNotContainsString('cron-passwort-test', $configPhp);
        self::assertStringNotContainsString('cron-token-test-wert', $configPhp);
        self::assertSame(0600, fileperms($this->dir . '/config/config.php') & 0777);
        self::assertSame(0600, fileperms($this->dir . '/config/keys/app.key') & 0777);
        self::assertFileExists($this->dir . '/storage/install.lock');
        self::assertFileDoesNotExist($this->dir . '/storage/install-code.txt');
        self::assertSame('https://hub.example.test/cron/cron-token-test-wert', $result->cronUrl);
        self::assertSame('cron-passwort-test', $result->cronPassword);
        self::assertFalse($actions->configExistedBeforeMigrate);
        self::assertFalse($actions->configExistedBeforeUser);
        self::assertFileDoesNotExist($this->dir . '/storage/install-attempts.txt');
    }

    public function testBrowserFinishShowsCronOnceAndDestroysTheSession(): void
    {
        $sessionDir = $this->dir . '/storage/sessions';
        mkdir($sessionDir, 0700);
        $session = new Session($sessionDir, false);
        $dbPassword = 'datenbank-test-passwort';
        $accountPassword = 'benutzer-passwort';
        $draft = new InstallDraft($session);
        $session->set('install_unlocked', true);
        $draft->save($this->database($dbPassword));
        file_put_contents($this->dir . '/storage/install-code.txt', "ABCD-EFGH-JKMN-PQRS-TUVW\n");
        $app = new InstallerApp($this->dir, $session, null, $this->finisher(new RecordingInstallActions()));

        $page = $app->handle(new Request('GET', '/einrichten'));
        $token = $this->tokenFrom($page->body);
        $saved = $app->handle(new Request('POST', '/einrichten/benutzer', post: [
            '_csrf' => $token,
            'username' => 'tom',
            'password' => $accountPassword,
        ]));
        self::assertSame(303, $saved->status);

        $totp = $app->handle(new Request('GET', '/einrichten'));
        $secret = $draft->account()['secret'] ?? '';
        self::assertNotSame('', $secret);
        self::assertStringContainsString($secret, $totp->body);
        self::assertStringContainsString('data:image/svg+xml;base64,', $totp->body);
        self::assertStringNotContainsString($dbPassword, $totp->body);
        self::assertStringNotContainsString($accountPassword, $totp->body);

        $done = $app->handle(new Request('POST', '/einrichten/abschluss', post: [
            '_csrf' => $this->tokenFrom($totp->body),
            'code' => (new TotpVerifier())->codeAt($secret, time()),
        ]));

        self::assertSame(200, $done->status);
        self::assertStringContainsString('cron-passwort-test', $done->body);
        self::assertStringContainsString('https://hub.example.test/cron/cron-token-test-wert', $done->body);
        self::assertStringContainsString('Im KAS einen Cronjob anlegen', $done->body);
        self::assertStringContainsString('href="/login"', $done->body);
        self::assertStringNotContainsString($dbPassword, $done->body);
        self::assertStringNotContainsString($accountPassword, $done->body);
        self::assertStringNotContainsString($secret, $done->body);
        self::assertSame(Session::NAME, $done->cookies[0]['name']);
        self::assertSame('', $done->cookies[0]['value']);
        self::assertSame(1, $done->cookies[0]['options']['expires']);
        self::assertTrue($done->cookies[0]['options']['httponly']);
        self::assertSame('Lax', $done->cookies[0]['options']['samesite']);
        self::assertFalse($done->cookies[0]['options']['secure']);
        self::assertFileExists($this->dir . '/config/config.php');
        self::assertFileDoesNotExist($this->dir . '/storage/install-code.txt');
        foreach (glob($sessionDir . '/sess_*') ?: [] as $file) {
            $raw = (string) file_get_contents($file);
            self::assertStringNotContainsString($dbPassword, $raw);
            self::assertStringNotContainsString($accountPassword, $raw);
            self::assertStringNotContainsString($secret, $raw);
        }
        self::assertNull($draft->get());
        self::assertNull($draft->account());
    }

    public function testWrongCodeWritesNothingAndKeepsTheSession(): void
    {
        $session = new Session(null, false);
        $draft = new InstallDraft($session);
        $session->set('install_unlocked', true);
        $draft->save($this->database('datenbank-test-passwort'));
        $secret = (new TotpVerifier())->generateSecret();
        $draft->saveAccount('tom', 'benutzer-passwort', $secret);
        $app = new InstallerApp($this->dir, $session, null, $this->finisher(new RecordingInstallActions()));
        $token = $this->tokenFrom($app->handle(new Request('GET', '/einrichten'))->body);
        $code = '000000';
        if ((new TotpVerifier())->codeAt($secret, time()) === $code) {
            $code = '111111';
        }

        $response = $app->handle(new Request('POST', '/einrichten/abschluss', post: [
            '_csrf' => $token,
            'code' => $code,
        ]));

        self::assertSame(401, $response->status);
        self::assertStringContainsString('Der Code stimmt nicht.', $response->body);
        self::assertFileDoesNotExist($this->dir . '/config/config.php');
        self::assertSame('datenbank-test-passwort', $draft->get()['password'] ?? null);
        self::assertSame([], $response->cookies);
    }

    public function testShortPasswordDoesNotOpenTheSecondFactor(): void
    {
        $session = new Session(null, false);
        $session->set('install_unlocked', true);
        (new InstallDraft($session))->save($this->database('datenbank-test-passwort'));
        $app = new InstallerApp($this->dir, $session, null, $this->finisher(new RecordingInstallActions()));
        $token = $this->tokenFrom($app->handle(new Request('GET', '/einrichten'))->body);

        $response = $app->handle(new Request('POST', '/einrichten/benutzer', post: [
            '_csrf' => $token,
            'username' => 'tom',
            'password' => 'kurz',
        ]));

        self::assertStringContainsString('mindestens 12 Zeichen', $response->body);
        self::assertNull((new InstallDraft($session))->account());
        self::assertFileDoesNotExist($this->dir . '/config/config.php');
    }

    private function finisher(RecordingInstallActions $actions): InstallFinisher
    {
        $actions->root = $this->dir;
        $code = new InstallCode($this->dir . '/storage/install-code.txt', $this->dir . '/storage/install-attempts.txt');
        $code->ensure();

        return new InstallFinisher($this->dir, $actions, $code);
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

    private function tokenFrom(string $html): string
    {
        self::assertSame(1, preg_match('/name="_csrf" value="([^"]+)"/', $html, $match));

        return $match[1];
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

final class RecordingInstallActions implements InstallActions
{
    public string $root = '';
    public bool $failMigrate = false;
    public bool $userCreated = false;
    public bool $userRemoved = false;
    public bool $configExistedBeforeMigrate = false;
    public bool $configExistedBeforeUser = false;

    public function migrate(Config $config): void
    {
        $this->configExistedBeforeMigrate = is_file($this->root . '/config/config.php');
        if ($this->failMigrate) {
            throw new \RuntimeException('Migration kaputt');
        }
    }

    public function createUser(Config $config, Crypto $crypto, string $username, string $passwordHash, string $totpSecret): void
    {
        $this->configExistedBeforeUser = is_file($this->root . '/config/config.php');
        $this->userCreated = true;
    }

    public function removeUser(Config $config, string $username): void
    {
        $this->userRemoved = true;
    }

    public function vapid(): array
    {
        return ['publicKey' => 'vapid-public-test', 'privateKey' => 'vapid-private-test'];
    }

    public function cron(string $appUrl): CronCredentials
    {
        return new CronCredentials(
            $appUrl . '/cron/cron-token-test-wert',
            'cron',
            'cron-passwort-test',
            hash('sha256', 'cron-token-test-wert'),
            password_hash('cron-passwort-test', PASSWORD_DEFAULT),
        );
    }
}
