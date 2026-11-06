<?php

declare(strict_types=1);

namespace Tests\Notify;

use App\Auth\UserRepository;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Notify\PushSender;
use Tests\DbTestCase;

final class PushControllerTest extends DbTestCase
{
    private const KEY = 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM';

    private Kernel $kernel;
    private Session $session;
    private FakePushSender $sender;
    private string $keyFile;

    private function boot(string $vapidPublic): void
    {
        $this->keyFile = tempnam(sys_get_temp_dir(), 'phkey') ?: '';
        file_put_contents($this->keyFile, Crypto::generateKeyHex());
        $this->session = new Session(null, true);
        $this->sender = new FakePushSender();
        $this->kernel = new Kernel(dirname(__DIR__, 2), new Config([
            'app' => ['url' => 'https://hub.example.invalid'],
            'crypto' => ['key_path' => $this->keyFile],
            'vapid' => ['subject' => 'mailto:a@example.test', 'public_key' => $vapidPublic, 'private_key' => 'geheim'],
        ]));
        $c = $this->kernel->container();
        $c->instance(Session::class, $this->session);
        $c->instance(Db::class, $this->db);
        $c->instance(PushSender::class, $this->sender);
        $this->kernel->boot(require dirname(__DIR__, 2) . '/config/modules.php');
        $id = $c->get(UserRepository::class)->create('thomas', 'x', $c->get(Crypto::class)->encrypt('S'), gmdate('Y-m-d H:i:s'));
        $this->session->set('uid', $id);
    }

    protected function tearDown(): void
    {
        if (isset($this->keyFile)) {
            @unlink($this->keyFile);
        }
    }

    /** @param array<string, mixed> $json */
    private function postJson(string $path, array $json, bool $csrf = true): Response
    {
        $headers = ['content-type' => 'application/json'];
        if ($csrf) {
            $headers['x-csrf-token'] = $this->kernel->container()->get(Csrf::class)->token();
        }

        return $this->kernel->handle(new Request('POST', $path, headers: $headers, server: ['HTTPS' => 'on'], body: json_encode($json, JSON_THROW_ON_ERROR)));
    }

    private function get(string $path): Response
    {
        return $this->kernel->handle(new Request('GET', $path, server: ['HTTPS' => 'on']));
    }

    public function testPublicKeyIsServedButPrivateKeyNeverAppears(): void
    {
        $this->boot(self::KEY);

        $key = $this->get('/api/v1/push/key');
        self::assertSame(self::KEY, json_decode($key->body, true)['publicKey']);
        self::assertStringNotContainsString('geheim', $key->body . $this->get('/einstellungen/benachrichtigungen')->body);
    }

    public function testUnconfiguredShowsHintAndKeyEndpointFails(): void
    {
        $this->boot('replace-me');

        self::assertSame(503, $this->get('/api/v1/push/key')->status);
        self::assertStringContainsString('create-vapid.php', $this->get('/einstellungen/benachrichtigungen')->body);
    }

    public function testRequiresLoginAndCsrf(): void
    {
        $this->boot(self::KEY);
        $payload = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => ['p256dh' => self::KEY, 'auth' => 'tBHItJI5svbpez7KI4CCXg']];

        self::assertSame(403, $this->postJson('/api/v1/push/subscribe', $payload, false)->status);
        self::assertSame(403, $this->postJson('/api/v1/push/test', [], false)->status);

        $this->kernel->container()->get(\App\Auth\AuthService::class)->logout();
        self::assertSame(404, $this->get('/api/v1/push/key')->status);
        self::assertSame(404, $this->get('/einstellungen/benachrichtigungen')->status);
    }

    public function testSubscribeTestAndRemove(): void
    {
        $this->boot(self::KEY);
        $payload = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => ['p256dh' => self::KEY, 'auth' => 'tBHItJI5svbpez7KI4CCXg'], 'label' => 'Chrome auf Android'];

        self::assertSame(201, $this->postJson('/api/v1/push/subscribe', $payload)->status);
        self::assertStringContainsString('Chrome auf Android', $this->get('/einstellungen/benachrichtigungen')->body);

        $test = json_decode($this->postJson('/api/v1/push/test', [])->body, true);
        self::assertSame(1, $test['delivered']);
        self::assertSame('Testnachricht', $this->sender->payloads()[0]['title']);

        $id = (int) $this->db->fetchValue('SELECT id FROM push_subscriptions');
        $remove = $this->kernel->handle(new Request('POST', "/einstellungen/benachrichtigungen/$id/entfernen", post: ['_csrf' => $this->kernel->container()->get(Csrf::class)->token()], server: ['HTTPS' => 'on']));
        self::assertSame(303, $remove->status);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM push_subscriptions'));
    }

    public function testSubscribeRejectsForeignEndpointsAndGarbage(): void
    {
        $this->boot(self::KEY);

        foreach ([
            ['endpoint' => 'https://169.254.169.254/latest', 'keys' => ['p256dh' => self::KEY, 'auth' => 'tBHItJI5svbpez7KI4CCXg']],
            ['endpoint' => 'https://evil.example/x', 'keys' => ['p256dh' => self::KEY, 'auth' => 'tBHItJI5svbpez7KI4CCXg']],
            ['endpoint' => 'https://fcm.googleapis.com/x'],
            ['endpoint' => ['x'], 'keys' => 'y'],
            [],
        ] as $payload) {
            self::assertSame(422, $this->postJson('/api/v1/push/subscribe', $payload)->status, json_encode($payload));
        }
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM push_subscriptions'));
    }

    public function testUnsubscribeApi(): void
    {
        $this->boot(self::KEY);
        $payload = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => ['p256dh' => self::KEY, 'auth' => 'tBHItJI5svbpez7KI4CCXg']];
        $this->postJson('/api/v1/push/subscribe', $payload);

        self::assertSame(200, $this->postJson('/api/v1/push/unsubscribe', ['endpoint' => $payload['endpoint']])->status);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM push_subscriptions'));
    }
}
