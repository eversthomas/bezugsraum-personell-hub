<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Db;
use PHPUnit\Framework\TestCase;

/**
 * public/index.php: mit config.php der Kernel, ohne sie der Installer.
 */
final class FrontControllerTest extends TestCase
{
    private string $dir;

    /** @var list<array{0: string, 1: string}> */
    private const INSTALLER_ROUTES = [
        ['GET', '/einrichten'],
        ['POST', '/einrichten/code'],
        ['POST', '/einrichten/datenbank/testen'],
        ['POST', '/einrichten/datenbank'],
        ['POST', '/einrichten/benutzer'],
        ['POST', '/einrichten/abschluss'],
    ];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ph-front-' . bin2hex(random_bytes(4));
        $project = dirname(__DIR__, 2);
        mkdir($this->dir . '/public', 0700, true);
        mkdir($this->dir . '/config', 0700, true);
        mkdir($this->dir . '/storage', 0700, true);
        copy($project . '/public/index.php', $this->dir . '/public/index.php');
        symlink($project . '/vendor', $this->dir . '/vendor');
        symlink($project . '/templates', $this->dir . '/templates');
        symlink($project . '/config/modules.php', $this->dir . '/config/modules.php');
        $database = [
            'host' => getenv('PH_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('PH_TEST_DB_PORT') ?: 3306),
            'name' => getenv('PH_TEST_DB_NAME') ?: 'personal_hub_test',
            'user' => getenv('PH_TEST_DB_USER') ?: 'ph',
            'password' => getenv('PH_TEST_DB_PASSWORD') ?: 'ph',
            'charset' => 'utf8mb4',
        ];
        try {
            Db::connect(new Config(['db' => $database]));
        } catch (\PDOException) {
            $this->markTestSkipped('Testdatenbank nicht erreichbar.');
        }
        $config = [
            'db' => $database,
            'app' => [
                'url' => 'http://127.0.0.1',
                'timezone' => 'Europe/Berlin',
                'trust_proxy' => false,
            ],
            'crypto' => ['key_path' => $this->dir . '/config/keys/app.key'],
            'push' => ['allowed_hosts' => []],
            'vapid' => ['subject' => 'http://127.0.0.1', 'public_key' => '', 'private_key' => ''],
        ];
        file_put_contents(
            $this->dir . '/config/config.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n",
        );
        mkdir($this->dir . '/config/keys', 0700);
        file_put_contents($this->dir . '/config/keys/app.key', Crypto::generateKeyHex() . "\n");
        file_put_contents($this->dir . '/run.php', <<<'PHP'
<?php

declare(strict_types=1);

$_SERVER['REQUEST_METHOD'] = $argv[1];
$_SERVER['REQUEST_URI'] = $argv[2];
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
ob_start();
require __DIR__ . '/public/index.php';
file_put_contents(__DIR__ . '/last-body.txt', (string) ob_get_clean());
file_put_contents(__DIR__ . '/last-status.txt', (string) http_response_code());
PHP);
    }

    protected function tearDown(): void
    {
        foreach (['vendor', 'templates', 'config/modules.php'] as $link) {
            $path = $this->dir . '/' . $link;
            if (is_link($path)) {
                unlink($path);
            }
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

    public function testInstallerRoutesAreNotFoundWhenConfigExists(): void
    {
        self::assertFileDoesNotExist($this->dir . '/storage/install.lock');

        foreach (self::INSTALLER_ROUTES as [$method, $path]) {
            $response = $this->front($method, $path);
            self::assertSame(404, $response['status'], $method . ' ' . $path);
            self::assertSame("Nicht gefunden\n", $response['body']);
            self::assertStringNotContainsString('Einrichten', $response['body']);
        }

        self::assertFileDoesNotExist($this->dir . '/storage/install-code.txt');

        $home = $this->front('GET', '/');
        self::assertSame(303, $home['status']);
        self::assertSame('', $home['body']);
        self::assertFileDoesNotExist($this->dir . '/storage/install-code.txt');
    }

    public function testMissingConfigUsesTheInstaller(): void
    {
        unlink($this->dir . '/config/config.php');

        $response = $this->front('GET', '/einrichten');

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('storage/install-code.txt', $response['body']);
        self::assertFileExists($this->dir . '/storage/install-code.txt');
    }

    /** @return array{status: int, body: string} */
    private function front(string $method, string $path): array
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', $this->dir . '/run.php', $method, $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertSame(0, $exit, $stderr);
        self::assertSame('', $stdout);

        return [
            'status' => (int) file_get_contents($this->dir . '/last-status.txt'),
            'body' => (string) file_get_contents($this->dir . '/last-body.txt'),
        ];
    }
}
