<?php

declare(strict_types=1);

namespace Tests;

use App\Auth\TotpVerifier;
use App\Auth\UserRepository;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Ende-zu-Ende durch den Kernel (ohne Webserver).
 */
final class KernelTest extends DbTestCase
{
    private Kernel $kernel;
    private string $secret;
    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keyFile = tempnam(sys_get_temp_dir(), 'phkey');
        file_put_contents($this->keyFile, Crypto::generateKeyHex());
        $config = new Config([
            'db' => [
                'host' => getenv('PH_TEST_DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('PH_TEST_DB_PORT') ?: 3306),
                'name' => getenv('PH_TEST_DB_NAME') ?: 'personal_hub_test',
                'user' => getenv('PH_TEST_DB_USER') ?: 'ph',
                'password' => getenv('PH_TEST_DB_PASSWORD') ?: 'ph',
            ],
            'app' => ['url' => 'https://hub.example.invalid'],
            'crypto' => ['key_path' => $this->keyFile],
        ]);
        $this->kernel = new Kernel(dirname(__DIR__), $config);
        $this->kernel->container()->instance(Session::class, new Session(null, true));
        $this->kernel->container()->instance(Db::class, $this->db);
        $this->kernel->boot(require dirname(__DIR__) . '/config/modules.php');

        $c = $this->kernel->container();
        $this->secret = $c->get(TotpVerifier::class)->generateSecret();
        $c->get(UserRepository::class)->create(
            'thomas',
            password_hash('correct-horse-battery', PASSWORD_DEFAULT),
            $c->get(Crypto::class)->encrypt($this->secret),
            gmdate('Y-m-d H:i:s'),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->keyFile)) {
            @unlink($this->keyFile);
        }
    }

    /** @param array<string, mixed> $post */
    private function call(string $method, string $path, array $post = []): Response
    {
        return $this->kernel->handle(new Request($method, $path, post: $post, server: ['HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.9']));
    }

    private function csrf(): string
    {
        $html = $this->call('GET', '/login')->body;
        self::assertSame(1, preg_match('/name="_csrf" value="([0-9a-f]+)"/', $html, $m));

        return $m[1];
    }

    public function testUnauthenticatedGetsNothingButLogin(): void
    {
        foreach (['/api/v1/anything', '/items', '/admin', '/logout', '/.env', '/kalender'] as $path) {
            self::assertSame(404, $this->call('GET', $path)->status, $path);
        }
        self::assertSame(404, $this->call('POST', '/logout')->status);

        $home = $this->call('GET', '/');
        self::assertSame(303, $home->status);
        self::assertSame('/login', $home->headers['Location']);
        $login = $this->call('GET', '/login');
        self::assertSame(200, $login->status);
        self::assertStringNotContainsString('data-clockbar', $login->body);
    }

    public function testEveryResponseCarriesNoindexHeader(): void
    {
        foreach (['/login', '/nicht-da', '/'] as $path) {
            self::assertSame('noindex, nofollow, noarchive', $this->call('GET', $path)->headers['X-Robots-Tag'], $path);
        }
    }

    public function testLoginPostWithoutCsrfIsForbidden(): void
    {
        $response = $this->call('POST', '/login', ['username' => 'thomas', 'password' => 'correct-horse-battery']);

        self::assertSame(403, $response->status);
    }

    public function testFullLoginThroughKernel(): void
    {
        $token = $this->csrf();
        $response = $this->call('POST', '/login', ['_csrf' => $token, 'username' => 'thomas', 'password' => 'correct-horse-battery']);
        self::assertSame(303, $response->status);
        self::assertSame('/login/code', $response->headers['Location']);
        self::assertSame(404, $this->call('GET', '/logout')->status);
        self::assertSame(303, $this->call('GET', '/')->status, 'Nach dem Passwort allein noch kein Zugang');

        $code = (new TotpVerifier())->codeAt($this->secret, time());
        $response = $this->call('POST', '/login/code', ['_csrf' => $token, 'code' => $code]);
        self::assertSame(303, $response->status);
        self::assertSame('/', $response->headers['Location']);

        $home = $this->call('GET', '/');
        self::assertSame(200, $home->status);
        self::assertStringContainsString('data-leitstand', $home->body);
        self::assertStringContainsString('data-calendar', $home->body);
        self::assertStringContainsString('Schnell erfassen', $home->body);
        self::assertStringContainsString('href="/" aria-current="page"', $home->body);
        self::assertStringNotContainsString('href="/kalender" aria-current="page"', $home->body);

        $calendar = $this->call('GET', '/kalender');
        self::assertSame(200, $calendar->status);
        self::assertStringContainsString('data-calendar', $calendar->body);
        self::assertStringContainsString('data-views', $calendar->body);
        self::assertStringContainsString('data-sources', $calendar->body);
        self::assertStringContainsString('leitstand.js', $calendar->body);
        self::assertStringContainsString('href="/kalender" aria-current="page"', $calendar->body);
        self::assertStringNotContainsString('Schnell erfassen', $calendar->body);
        self::assertStringNotContainsString('data-taskcard', $calendar->body);
        self::assertStringNotContainsString('hero__clock', $home->body);

        $tasks = $this->call('GET', '/aufgaben');
        foreach (['/' => $home, '/kalender' => $calendar, '/aufgaben' => $tasks] as $path => $page) {
            self::assertSame(200, $page->status, $path);
            self::assertStringContainsString('data-clockbar', $page->body, $path);
            self::assertStringContainsString('data-now', $page->body, $path);
            self::assertStringContainsString('href="/kalender"', $page->body, $path);
            self::assertStringContainsString('href="/aufgaben"', $page->body, $path);
            self::assertStringContainsString('>Leitstand<', $page->body, $path);
        }

        // Nach dem Login ist das CSRF-Token erneuert: das alte gilt nicht mehr.
        self::assertSame(403, $this->call('POST', '/logout', ['_csrf' => $token])->status);
        preg_match('/name="csrf" content="([0-9a-f]+)"/', $home->body, $m);
        self::assertSame(303, $this->call('POST', '/logout', ['_csrf' => $m[1]])->status);
        self::assertSame(303, $this->call('GET', '/')->status);
    }

    public function testBadLoginShowsNeutralErrorWithoutLeakingWhichPartIsWrong(): void
    {
        $token = $this->csrf();
        $unknown = $this->call('POST', '/login', ['_csrf' => $token, 'username' => 'niemand', 'password' => 'x']);
        $wrong = $this->call('POST', '/login', ['_csrf' => $token, 'username' => 'thomas', 'password' => 'x']);

        self::assertSame(401, $unknown->status);
        self::assertSame(401, $wrong->status);
        self::assertStringContainsString('Anmeldung nicht möglich', $wrong->body);
    }

    public function testPagesEscapeUserInput(): void
    {
        $token = $this->csrf();
        $response = $this->call('POST', '/login', ['_csrf' => $token, 'username' => '"><script>alert(1)</script>', 'password' => 'x']);

        self::assertStringNotContainsString('<script>alert(1)', $response->body);
        self::assertStringContainsString('&lt;script&gt;', $response->body);
    }
}
