<?php

declare(strict_types=1);

namespace Tests\Notify;

use App\Notify\PushSubscriptionRepository;
use App\Notify\SubscriptionService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DbTestCase;

final class SubscriptionServiceTest extends DbTestCase
{
    private const KEY = 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM';
    private const AUTH = 'tBHItJI5svbpez7KI4CCXg';

    private SubscriptionService $service;
    private PushSubscriptionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new PushSubscriptionRepository($this->db);
        $this->service = new SubscriptionService($this->repo);
    }

    /** @return array<string, array{string}> */
    public static function goodEndpoints(): array
    {
        $urls = [
            'https://fcm.googleapis.com/fcm/send/abc',
            'https://updates.push.services.mozilla.com/wpush/v2/xyz',
            'https://web.push.apple.com/QWERTY',
            'https://wns2-par02p.notify.windows.com/w/?token=abc',
        ];

        return array_combine($urls, array_map(static fn (string $u): array => [$u], $urls));
    }

    /** @return array<string, array{string}> */
    public static function badEndpoints(): array
    {
        $urls = [
            'http://fcm.googleapis.com/fcm/send/abc',
            'https://evil.example/push',
            'https://fcm.googleapis.com.evil.example/x',
            'https://evilfcm.googleapis.com.attacker.test/x',
            'https://notfcm.googleapis.comx/x',
            'https://127.0.0.1/x',
            'https://169.254.169.254/latest/meta-data/',
            'https://user:pw@fcm.googleapis.com/x',
            'https://fcm.googleapis.com:8443/x',
            'file:///etc/passwd',
            '',
            'kein url',
        ];

        return array_combine(array_map(static fn (string $u): string => $u === '' ? '(leer)' : $u, $urls), array_map(static fn (string $u): array => [$u], $urls));
    }

    #[DataProvider('goodEndpoints')]
    public function testAcceptsKnownPushServices(string $endpoint): void
    {
        $this->service->subscribe($endpoint, self::KEY, self::AUTH, 'Pixel');

        self::assertCount(1, $this->repo->all());
    }

    #[DataProvider('badEndpoints')]
    public function testRejectsEverythingElse(string $endpoint): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->subscribe($endpoint, self::KEY, self::AUTH, 'x');
    }

    public function testExtraHostsFromConfigAreAllowed(): void
    {
        $service = new SubscriptionService($this->repo, ['push.example.org']);
        $service->subscribe('https://eu.push.example.org/abc', self::KEY, self::AUTH, 'x');

        self::assertCount(1, $this->repo->all());
    }

    public function testSameEndpointUpdatesInsteadOfDuplicating(): void
    {
        $this->service->subscribe('https://fcm.googleapis.com/fcm/send/abc', self::KEY, self::AUTH, 'Alt');
        $this->service->subscribe('https://fcm.googleapis.com/fcm/send/abc', self::KEY, self::AUTH, 'Neu');

        self::assertCount(1, $this->repo->all());
        self::assertSame('Neu', $this->repo->all()[0]['label']);
    }

    public function testInvalidKeysAreRejected(): void
    {
        foreach ([['kurz', self::AUTH], [self::KEY, 'x'], [self::KEY . '!!', self::AUTH]] as [$key, $auth]) {
            try {
                $this->service->subscribe('https://fcm.googleapis.com/fcm/send/abc', $key, $auth, 'x');
                self::fail('Erwartet InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->repo->all());
    }

    public function testUnsubscribe(): void
    {
        $this->service->subscribe('https://fcm.googleapis.com/fcm/send/abc', self::KEY, self::AUTH, 'x');
        $this->service->unsubscribe('https://fcm.googleapis.com/fcm/send/abc');

        self::assertSame([], $this->repo->all());
    }
}
