<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Install\InstallCode;
use App\Install\InstallController;
use App\Install\InstallerApp;
use App\Install\SystemCheck;
use PHPUnit\Framework\TestCase;

final class InstallCodeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ph-install-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage', 0700, true);
        mkdir($this->dir . '/config', 0700, true);
        mkdir($this->dir . '/templates', 0700, true);
    }

    protected function tearDown(): void
    {
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

    public function testCodeIsTypeableAndReadableOnlyByTheOwner(): void
    {
        $code = $this->code();
        self::assertTrue($code->ensure());
        $stored = trim((string) file_get_contents($this->dir . '/storage/install-code.txt'));

        self::assertSame(20, strlen(InstallCode::normalize($stored)));
        self::assertSame($stored, InstallCode::normalize($stored) === '' ? '' : implode('-', str_split(InstallCode::normalize($stored), 4)));
        self::assertSame(0600, fileperms($this->dir . '/storage/install-code.txt') & 0777);
        self::assertSame('ok', $code->check(strtolower(str_replace('-', ' ', $stored))));
    }

    public function testFifthFailureDelaysAndKeepsTheCode(): void
    {
        $delay = new DelayLog();
        $code = new InstallCode(
            $this->dir . '/storage/install-code.txt',
            $this->dir . '/storage/install-attempts.txt',
            null,
            static function (int $seconds) use ($delay): void {
                $delay->calls++;
                $delay->seconds = $seconds;
            },
        );
        $code->ensure();
        $first = (string) file_get_contents($this->dir . '/storage/install-code.txt');
        $ip = '203.0.113.5';
        for ($i = 0; $i < 4; $i++) {
            self::assertSame('wrong', $code->check('falsch', $ip));
        }
        self::assertSame(0, $delay->calls);
        self::assertSame($first, (string) file_get_contents($this->dir . '/storage/install-code.txt'));

        self::assertSame('wrong', $code->check('falsch', $ip));
        self::assertSame(1, $delay->calls);
        self::assertSame(InstallCode::DELAY_SECONDS, $delay->seconds);
        self::assertSame($first, (string) file_get_contents($this->dir . '/storage/install-code.txt'));
        self::assertSame(InstallCode::MAX_FAILURES, $code->attempts($ip));
        self::assertSame(0, $code->attempts('203.0.113.6'));
        self::assertSame('wrong', $code->check('falsch', '203.0.113.6'));
        self::assertSame(1, $delay->calls);
        $stored = (string) file_get_contents($this->dir . '/storage/install-attempts.txt');
        self::assertStringNotContainsString($ip, $stored);
        self::assertSame('ok', $code->check(trim($first), $ip));
        self::assertSame(0, $code->attempts($ip));
    }

    public function testCodeIsReplacedOnlyAfterFifteenMinutes(): void
    {
        $path = $this->dir . '/storage/install-code.txt';
        $code = $this->code();
        $code->ensure();
        $first = (string) file_get_contents($path);
        $modified = filemtime($path);
        self::assertIsInt($modified);
        $fresh = new InstallCode(
            $path,
            $this->dir . '/storage/install-attempts.txt',
            static fn (): int => $modified + InstallCode::MAX_AGE_SECONDS - 1,
        );
        self::assertSame('wrong', $fresh->check('falsch'));
        self::assertSame($first, (string) file_get_contents($path));

        $stale = new InstallCode(
            $path,
            $this->dir . '/storage/install-attempts.txt',
            static fn (): int => $modified + InstallCode::MAX_AGE_SECONDS,
        );
        self::assertSame('renewed', $stale->check(trim($first)));
        $replacement = (string) file_get_contents($path);
        self::assertNotSame($first, $replacement);
        $replacedAt = filemtime($path);
        self::assertIsInt($replacedAt);
        $settled = new InstallCode(
            $path,
            $this->dir . '/storage/install-attempts.txt',
            static fn (): int => $replacedAt,
        );
        self::assertSame('wrong', $settled->check(trim($first)));
        self::assertSame($replacement, (string) file_get_contents($path));
    }

    public function testPageExplainsWhereToReadTheCodeAndDoesNotRevealIt(): void
    {
        $response = $this->app()->handle(new Request('GET', '/'));
        $stored = trim((string) file_get_contents($this->dir . '/storage/install-code.txt'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Dateimanager des KAS', $response->body);
        self::assertStringContainsString('FTP', $response->body);
        self::assertStringContainsString('storage/install-code.txt', $response->body);
        self::assertStringNotContainsString(InstallCode::normalize($stored), $response->body);
        self::assertSame('noindex, nofollow, noarchive', $response->headers['X-Robots-Tag']);
    }

    public function testWrongCodeStaysOnTheFormWithoutReplacingTheCode(): void
    {
        $app = $this->app();
        $first = $this->csrf($app);
        $stored = (string) file_get_contents($this->dir . '/storage/install-code.txt');
        for ($i = 0; $i < 4; $i++) {
            $response = $this->post($app, $first, 'falsch');
            self::assertSame(401, $response->status);
            self::assertStringContainsString('Der Code stimmt nicht.', $response->body);
            $first = $this->tokenFrom($response->body);
        }

        self::assertSame($stored, (string) file_get_contents($this->dir . '/storage/install-code.txt'));
        self::assertStringNotContainsString('ein neuer Code', $response->body);
    }

    public function testMissingCsrfIsRejected(): void
    {
        $app = $this->app();
        $app->handle(new Request('GET', '/einrichten'));

        $response = $app->handle(new Request('POST', '/einrichten/code', post: ['code' => 'ABC']));

        self::assertSame(403, $response->status);
    }

    public function testAcceptedCodeShowsTheSystemCheckWithoutANextStep(): void
    {
        $app = $this->app();
        $token = $this->csrf($app);
        $stored = trim((string) file_get_contents($this->dir . '/storage/install-code.txt'));
        $posted = $app->handle(new Request('POST', '/einrichten/code', post: ['_csrf' => $token, 'code' => $stored]));
        self::assertSame(303, $posted->status);

        $page = $app->handle(new Request('GET', '/einrichten', server: ['HTTP_HOST' => 'hub.example.test', 'HTTPS' => 'on']));
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Die Systemprüfung ist in Ordnung.', $page->body);
        self::assertStringContainsString('/einrichten/datenbank', $page->body);
        self::assertStringContainsString('value="Europe/Berlin"', $page->body);
        self::assertStringContainsString('value="https://hub.example.test"', $page->body);
        self::assertFileDoesNotExist($this->dir . '/config/config.php');
    }

    public function testFailedCheckOffersNoWayForward(): void
    {
        $session = new Session(null, false);
        $session->set('install_unlocked', true);
        $code = $this->code();
        $code->ensure();
        $check = new SystemCheck($this->dir, 80200, static fn (string $extension): bool => !in_array($extension, ['sodium', 'gmp', 'bcmath'], true));
        $controller = new InstallController(
            $code,
            $check,
            $session,
            new Csrf($session),
            new View(dirname(__DIR__, 2) . '/templates'),
            new \App\Install\InstallDraft($session),
            new \App\Install\DatabaseProbe(),
            new \App\Install\InstallFinisher($this->dir, new \App\Install\DefaultInstallActions($this->dir), $code),
            new \App\Auth\TotpVerifier(),
            new \App\Install\TotpQr(),
        );

        $response = $controller->handle(new Request('GET', '/einrichten'));

        self::assertStringContainsString('PHP ist älter als 8.3.', $response->body);
        self::assertStringContainsString('Die Erweiterung sodium fehlt.', $response->body);
        self::assertStringContainsString('gmp oder bcmath', $response->body);
        self::assertStringNotContainsString('<form', $response->body);
    }

    public function testUnknownPathWhileInstallingIsNotFound(): void
    {
        $response = $this->app()->handle(new Request('GET', '/login'));

        self::assertSame(404, $response->status);
    }

    private function code(): InstallCode
    {
        return new InstallCode(
            $this->dir . '/storage/install-code.txt',
            $this->dir . '/storage/install-attempts.txt',
        );
    }

    private function app(): InstallerApp
    {
        $root = dirname(__DIR__, 2);
        foreach (['templates', 'public'] as $name) {
            $this->link($root . '/' . $name, $this->dir . '/' . $name);
        }

        return new InstallerApp($this->dir, new Session(null, false));
    }

    private function link(string $from, string $to): void
    {
        if (is_dir($to)) {
            rmdir($to);
        }
        symlink($from, $to);
    }

    private function csrf(InstallerApp $app): string
    {
        $response = $app->handle(new Request('GET', '/'));
        self::assertSame(200, $response->status);

        return $this->tokenFrom($response->body);
    }

    private function tokenFrom(string $html): string
    {
        self::assertSame(1, preg_match('/name="_csrf" value="([^"]+)"/', $html, $match));

        return $match[1];
    }

    private function post(InstallerApp $app, string $token, string $code): \App\Core\Response
    {
        return $app->handle(new Request('POST', '/einrichten/code', post: ['_csrf' => $token, 'code' => $code]));
    }
}

final class DelayLog
{
    public int $calls = 0;
    public int $seconds = 0;
}
