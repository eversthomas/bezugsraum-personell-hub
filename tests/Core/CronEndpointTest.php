<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Config;
use App\Core\Container;
use App\Core\Contracts\ModuleInterface;
use App\Core\CronCredentialStore;
use App\Core\CronCredentials;
use App\Core\CronJob;
use App\Core\CronRunner;
use App\Core\Kernel;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class CronEndpointTest extends TestCase
{
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz0123456789ABCD';
    private const USER = 'cron';
    private const PASSWORD = 'correct-horse-battery';

    private Kernel $kernel;
    private JobList $jobs;
    private string $cronFile;

    protected function setUp(): void
    {
        $this->jobs = new JobList();
        $this->cronFile = sys_get_temp_dir() . '/ph-cron-cred-' . bin2hex(random_bytes(4)) . '.php';
        $this->kernel = new Kernel(dirname(__DIR__, 2), new Config([
            'app' => ['url' => 'https://hub.example.invalid'],
        ]));
        $this->kernel->boot(require dirname(__DIR__, 2) . '/config/modules.php');
        $this->saveCredentials(self::TOKEN, self::PASSWORD);
        $this->kernel->container()->instance(CronRunner::class, $this->recordingRunner($this->jobs));
    }

    protected function tearDown(): void
    {
        if (isset($this->cronFile)) {
            @unlink($this->cronFile);
        }
    }

    public function testWrongTokenLooksLikeAnUnknownRoute(): void
    {
        $response = $this->call('/cron/falsches-token', $this->basic(self::USER, self::PASSWORD));

        self::assertSame(404, $response->status);
        self::assertSame("Nicht gefunden\n", $response->body);
        self::assertArrayNotHasKey('WWW-Authenticate', $response->headers);
        self::assertSame([], $this->jobs->names);
        self::assertSame('noindex, nofollow, noarchive', $response->headers['X-Robots-Tag']);
    }

    public function testEmptyTokenHashRejectsEveryToken(): void
    {
        $kernel = new Kernel(dirname(__DIR__, 2), new Config([
            'app' => ['url' => 'https://hub.example.invalid'],
        ]));
        $kernel->boot(require dirname(__DIR__, 2) . '/config/modules.php');
        $kernel->container()->instance(CronCredentialStore::class, new CronCredentialStore($this->cronFile . '.leer'));
        $kernel->container()->instance(CronRunner::class, $this->recordingRunner($this->jobs));

        $response = $kernel->handle(new Request('GET', '/cron/' . self::TOKEN, server: ['HTTPS' => 'on']));

        self::assertSame(404, $response->status);
        self::assertSame([], $this->jobs->names);
    }

    public function testRightTokenWithoutBasicAuthAsksForCredentials(): void
    {
        $response = $this->call('/cron/' . self::TOKEN);

        self::assertSame(401, $response->status);
        self::assertSame('Basic realm="cron"', $response->headers['WWW-Authenticate']);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertSame([], $this->jobs->names);
    }

    public function testHeadWithValidCredentialsDoesNotRun(): void
    {
        $response = $this->kernel->handle(new Request(
            'HEAD',
            '/cron/' . self::TOKEN,
            headers: ['authorization' => $this->basic(self::USER, self::PASSWORD)],
            server: ['HTTPS' => 'on'],
        ));

        self::assertSame(404, $response->status);
        self::assertSame([], $this->jobs->names);
    }

    public function testRightTokenAndBasicAuthRunsTheRunner(): void
    {
        $response = $this->call('/cron/' . self::TOKEN, $this->basic(self::USER, self::PASSWORD));

        self::assertSame(200, $response->status);
        self::assertSame("ok\n", $response->body);
        self::assertSame(['probe'], $this->jobs->names);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertStringNotContainsString(self::TOKEN, $response->body);
    }

    public function testBasicAuthFromRedirectHeaderIsAccepted(): void
    {
        $response = $this->kernel->handle(new Request(
            'GET',
            '/cron/' . self::TOKEN,
            server: [
                'HTTPS' => 'on',
                'REDIRECT_HTTP_AUTHORIZATION' => $this->basic(self::USER, self::PASSWORD),
            ],
        ));

        self::assertSame(200, $response->status);
        self::assertSame(['probe'], $this->jobs->names);
    }

    public function testOverlappingRunSaysAlreadyRunning(): void
    {
        $lock = tempnam(sys_get_temp_dir(), 'phcron');
        self::assertNotFalse($lock);
        $runner = new CronRunner([], new Container(), new Logger($lock . '.log'), $lock);
        $this->kernel->container()->instance(CronRunner::class, $runner);
        $handle = fopen($lock, 'c');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        try {
            $response = $this->call('/cron/' . self::TOKEN, $this->basic(self::USER, self::PASSWORD));
            self::assertSame(200, $response->status);
            self::assertSame("läuft bereits\n", $response->body);
            self::assertSame([], $this->jobs->names);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($lock);
        }
    }

    public function testDeadlineStopsBeforeTheNextJob(): void
    {
        $reads = 0;
        $module = new class () implements ModuleInterface {
            /** @var list<string> */
            public array $ran = [];

            public function routes(Router $router, Container $container): void
            {
            }

            public function services(Container $container): void
            {
            }

            public function cronJobs(): array
            {
                return [
                    new CronJob('one', 1, function (Container $c): void {
                        $this->ran[] = 'one';
                    }),
                    new CronJob('two', 1, function (Container $c): void {
                        $this->ran[] = 'two';
                    }),
                ];
            }

            public function navItems(): array
            {
                return [];
            }
        };
        $runner = new CronRunner(
            [$module],
            new Container(),
            new Logger(sys_get_temp_dir() . '/ph-cron-deadline.log'),
            sys_get_temp_dir() . '/ph-cron-deadline.lock',
            function () use (&$reads): int {
                $reads++;

                return $reads === 1 || $reads === 2 ? 1_000 : 1_050;
            },
        );

        self::assertSame(CronRunner::STATUS_OK, $runner->run());
        self::assertSame(['one'], $module->ran);
    }

    public function testJobsComeFromActiveModules(): void
    {
        $names = array_map(
            static fn (CronJob $job): string => $job->name,
            CronRunner::collect($this->kernel->modules()),
        );

        self::assertSame(['calendar.refresh', 'notify.reminders'], $names);
    }

    public function testGeneratedCredentialsMatchTheHashes(): void
    {
        $credentials = CronCredentials::generate('https://hub.example.invalid/');

        self::assertStringStartsWith('https://hub.example.invalid/cron/', $credentials->url);
        self::assertSame('cron', $credentials->user);
        self::assertGreaterThanOrEqual(43, strlen(substr($credentials->url, strlen('https://hub.example.invalid/cron/'))));
        $token = substr($credentials->url, strlen('https://hub.example.invalid/cron/'));
        self::assertSame(hash('sha256', $token), $credentials->tokenHash);
        self::assertTrue(password_verify($credentials->password, $credentials->passwordHash));
    }

    public function testPendingMigrationsRunNoJobs(): void
    {
        $runner = new CronRunner(
            [new RecordingModule($this->jobs)],
            new Container(),
            new Logger(sys_get_temp_dir() . '/ph-cron-paused.log'),
            sys_get_temp_dir() . '/ph-cron-paused.lock',
            null,
            CronRunner::BUDGET_SECONDS,
            static fn (): bool => true,
        );
        $this->kernel->container()->instance(CronRunner::class, $runner);

        $response = $this->call('/cron/' . self::TOKEN, $this->basic(self::USER, self::PASSWORD));

        self::assertSame(200, $response->status);
        self::assertSame("Migrationen stehen aus.\n", $response->body);
        self::assertSame([], $this->jobs->names);
    }

    private function saveCredentials(string $token, string $password): void
    {
        $store = new CronCredentialStore($this->cronFile);
        $store->save(new CronCredentials(
            'https://hub.example.invalid/cron/' . $token,
            self::USER,
            $password,
            hash('sha256', $token),
            password_hash($password, PASSWORD_DEFAULT),
        ));
        $this->kernel->container()->instance(CronCredentialStore::class, $store);
    }

    private function recordingRunner(JobList $jobs): CronRunner
    {
        return new CronRunner(
            [new RecordingModule($jobs)],
            new Container(),
            new Logger(sys_get_temp_dir() . '/ph-cron-record.log'),
            sys_get_temp_dir() . '/ph-cron-record.lock',
        );
    }

    private function call(string $path, ?string $authorization = null): Response
    {
        $headers = $authorization === null ? [] : ['authorization' => $authorization];

        return $this->kernel->handle(new Request('GET', $path, headers: $headers, server: ['HTTPS' => 'on']));
    }

    private function basic(string $user, string $password): string
    {
        return 'Basic ' . base64_encode($user . ':' . $password);
    }
}

final class JobList
{
    /** @var list<string> */
    public array $names = [];
}

final class RecordingModule implements ModuleInterface
{
    public function __construct(private readonly JobList $jobs)
    {
    }

    public function routes(Router $router, Container $container): void
    {
    }

    public function services(Container $container): void
    {
    }

    public function cronJobs(): array
    {
        return [
            new CronJob('probe', 1, function (Container $c): void {
                $this->jobs->names[] = 'probe';
            }),
        ];
    }

    public function navItems(): array
    {
        return [];
    }
}
