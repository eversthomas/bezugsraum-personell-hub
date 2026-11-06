<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Auth\TotpVerifier;
use App\Install\DefaultInstallActions;
use App\Install\InstallCode;
use App\Install\InstallException;
use App\Install\InstallFinisher;
use Tests\DbTestCase;

/**
 * Abschluss gegen die Testdatenbank. Die Projektdatei config/config.php bleibt unberührt.
 */
final class RealFinishTest extends DbTestCase
{
    private string $dir;

    private string $username = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ph-realfinish-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage', 0700, true);
        mkdir($this->dir . '/config', 0700, true);
        symlink(dirname(__DIR__, 2) . '/migrations', $this->dir . '/migrations');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->username !== '' && isset($this->db)) {
            $this->db->execute('DELETE FROM users WHERE username = ?', [$this->username]);
        }
        $migrations = $this->dir . '/migrations';
        if (is_link($migrations)) {
            unlink($migrations);
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

    public function testFinishCreatesTheUserAfterMigrationsAreAlreadyApplied(): void
    {
        $applied = (int) $this->db->fetchValue('SELECT COUNT(*) FROM schema_migrations');
        self::assertGreaterThan(0, $applied);
        $this->username = 'probe' . bin2hex(random_bytes(3));
        $password = 'install-probe-pass';
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $result = $this->finisher()->finish($this->database(), $this->username, $hash, (new TotpVerifier())->generateSecret());

        $row = $this->db->fetchOne('SELECT username, password_hash FROM users WHERE username = ?', [$this->username]);
        self::assertNotNull($row);
        self::assertSame($this->username, $row['username']);
        self::assertSame($hash, (string) $row['password_hash']);
        self::assertTrue(password_verify($password, (string) $row['password_hash']));
        self::assertSame($applied, (int) $this->db->fetchValue('SELECT COUNT(*) FROM schema_migrations'));
        self::assertSame($this->username, $result->username);
        $config = require $this->dir . '/config/config.php';
        self::assertIsArray($config);
        self::assertArrayNotHasKey('cron', $config);
        self::assertSame($this->database()['name'], $config['db']['name']);
        self::assertNotSame('', $config['db']['password']);
        self::assertFileExists($this->dir . '/storage/install.lock');
        self::assertFileDoesNotExist($this->dir . '/storage/install-code.txt');
        self::assertFileDoesNotExist($this->dir . '/storage/install-attempts.txt');
        self::assertFileExists($this->dir . '/storage/cron-credentials.php');
    }

    public function testRetrySucceedsWhenMigrationsWereAlreadyApplied(): void
    {
        $this->username = 'probe' . bin2hex(random_bytes(3));
        $password = 'install-probe-pass';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $secret = (new TotpVerifier())->generateSecret();
        $applied = (int) $this->db->fetchValue('SELECT COUNT(*) FROM schema_migrations');
        mkdir($this->dir . '/config/config.php', 0700);

        try {
            $this->finisher()->finish($this->database(), $this->username, $hash, $secret);
            self::fail('Der erste Abschluss hätte scheitern müssen.');
        } catch (InstallException $e) {
            self::assertSame(
                'Die Einrichtung konnte nicht abgeschlossen werden. Es wurde keine Konfiguration geschrieben.',
                $e->getMessage(),
            );
        }

        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM users WHERE username = ?', [$this->username]));
        self::assertSame($applied, (int) $this->db->fetchValue('SELECT COUNT(*) FROM schema_migrations'));
        self::assertDirectoryExists($this->dir . '/config/config.php');
        self::assertFileDoesNotExist($this->dir . '/config/keys/app.key');
        self::assertFileDoesNotExist($this->dir . '/storage/cron-credentials.php');

        rmdir($this->dir . '/config/config.php');
        $result = $this->finisher()->finish($this->database(), $this->username, $hash, $secret);

        $row = $this->db->fetchOne('SELECT password_hash FROM users WHERE username = ?', [$this->username]);
        self::assertNotNull($row);
        self::assertSame($hash, (string) $row['password_hash']);
        self::assertTrue(password_verify($password, (string) $row['password_hash']));
        self::assertSame($applied, (int) $this->db->fetchValue('SELECT COUNT(*) FROM schema_migrations'));
        self::assertFileExists($this->dir . '/config/config.php');
        self::assertSame($this->username, $result->username);
    }

    private function finisher(): InstallFinisher
    {
        $code = new InstallCode(
            $this->dir . '/storage/install-code.txt',
            $this->dir . '/storage/install-attempts.txt',
        );
        $code->ensure();

        return new InstallFinisher($this->dir, new DefaultInstallActions($this->dir), $code);
    }

    /** @return array{host: string, port: int, name: string, user: string, password: string, url: string, timezone: string} */
    private function database(): array
    {
        return [
            'host' => getenv('PH_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('PH_TEST_DB_PORT') ?: 3306),
            'name' => getenv('PH_TEST_DB_NAME') ?: 'personal_hub_test',
            'user' => getenv('PH_TEST_DB_USER') ?: 'ph',
            'password' => getenv('PH_TEST_DB_PASSWORD') ?: 'ph',
            'url' => 'https://hub.example.test',
            'timezone' => 'Europe/Berlin',
        ];
    }
}
