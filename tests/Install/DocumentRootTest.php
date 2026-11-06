<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Core\Request;
use App\Core\Session;
use App\Install\DocumentRoot;
use App\Install\InstallerApp;
use PHPUnit\Framework\TestCase;

final class DocumentRootTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ph-docroot-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage', 0700, true);
        mkdir($this->dir . '/config', 0700, true);
        mkdir($this->dir . '/public', 0700, true);
        symlink(dirname(__DIR__, 2) . '/templates', $this->dir . '/templates');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir . '/storage')) {
            chmod($this->dir . '/storage', 0700);
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

    public function testEmptyDocumentRootIsAcceptedAndAForeignRootIsNot(): void
    {
        $check = new DocumentRoot();

        self::assertTrue($check->matches($this->dir, ''));
        self::assertTrue($check->matches($this->dir, $this->dir . '/public'));
        self::assertFalse($check->matches($this->dir, $this->dir));
        self::assertFalse($check->matches($this->dir, $this->dir . '/fehlt'));
    }

    public function testWrongDocumentRootStopsWithTheKasInstruction(): void
    {
        $response = $this->app()->handle(new Request('GET', '/einrichten', server: [
            'DOCUMENT_ROOT' => $this->dir,
        ]));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Domainziel', $response->body);
        self::assertStringContainsString('public/', $response->body);
        self::assertStringNotContainsString('<form', $response->body);
        self::assertStringNotContainsString('install-code.txt', $response->body);
        self::assertStringNotContainsString('PHP ist', $response->body);
        self::assertFileDoesNotExist($this->dir . '/storage/install-code.txt');
    }

    public function testPublicDocumentRootStillAsksForTheCode(): void
    {
        $response = $this->app()->handle(new Request('GET', '/', server: [
            'DOCUMENT_ROOT' => $this->dir . '/public',
        ]));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('storage/install-code.txt', $response->body);
        self::assertStringContainsString('<form', $response->body);
    }

    public function testUnwritableStorageShowsNoSystemCheckBeforeTheCode(): void
    {
        chmod($this->dir . '/storage', 0555);

        $response = $this->app()->handle(new Request('GET', '/einrichten'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Die Einrichtung ist gerade nicht möglich.', $response->body);
        self::assertStringNotContainsString('Systemprüfung', $response->body);
        self::assertStringNotContainsString('Erweiterung', $response->body);
        self::assertStringNotContainsString('PHP ist', $response->body);
        self::assertStringNotContainsString('install-code.txt', $response->body);
        self::assertStringNotContainsString('<form', $response->body);
    }

    public function testDeniedHtaccessExistsForEverySecretDirectory(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['.htaccess', 'config/.htaccess', 'storage/.htaccess', 'bin/.htaccess', 'migrations/.htaccess', 'src/.htaccess', 'templates/.htaccess'] as $path) {
            self::assertStringContainsString('Require all denied', (string) file_get_contents($root . '/' . $path), $path);
        }
        self::assertFileDoesNotExist($root . '/.github/workflows/release.yml');
        $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');
        self::assertStringContainsString('tags:', $workflow);
        self::assertStringContainsString('- "v*"', $workflow);
        self::assertStringContainsString('needs: quality', $workflow);
        self::assertStringContainsString('contents: write', $workflow);
        self::assertStringContainsString('vendor/.htaccess', $workflow);
        self::assertStringContainsString('storage/.htaccess', $workflow);
        self::assertStringContainsString('.zip.sha256', $workflow);
        self::assertStringContainsString('GH_REPO: ${{ github.repository }}', $workflow);
        self::assertStringNotContainsString('actions/checkout@v', $workflow);
        self::assertStringNotContainsString('shivammathur/setup-php@v', $workflow);
    }

    private function app(): InstallerApp
    {
        return new InstallerApp($this->dir, new Session(null, false));
    }
}
