<?php

declare(strict_types=1);

namespace Tests\Notify;

use App\Notify\Http\Response;
use App\Notify\MinishlinkPushSender;
use App\Notify\PushResult;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Prüft den echten Web-Push-Versand (VAPID, Verschlüsselung) gegen einen Mitschnitt-Client.
 */
final class MinishlinkPushSenderTest extends TestCase
{
    /** @return array{endpoint: string, p256dh: string, auth: string} */
    private function subscription(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $point = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'p256dh' => $b64($point), 'auth' => $b64(random_bytes(16))];
    }

    private function sender(int $status, ?RequestInterface &$captured = null): MinishlinkPushSender
    {
        $vapid = VAPID::createVapidKeys();
        $client = new class ($status, $captured) implements ClientInterface {
            public function __construct(private int $status, private ?RequestInterface &$captured)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured = $request;

                return new Response($this->status);
            }
        };

        return new MinishlinkPushSender('mailto:admin@example.test', $vapid['publicKey'], $vapid['privateKey'], $client);
    }

    public function testRequestIsEncryptedAndSigned(): void
    {
        $captured = null;
        $result = $this->sender(201, $captured)->send($this->subscription(), '{"title":"Geheimer Titel","body":"Text"}');

        self::assertSame(PushResult::Delivered, $result);
        self::assertNotNull($captured);
        self::assertSame('POST', $captured->getMethod());
        self::assertSame('https://fcm.googleapis.com/fcm/send/abc', (string) $captured->getUri());
        self::assertSame('aes128gcm', $captured->getHeaderLine('Content-Encoding'));
        self::assertStringStartsWith('vapid t=', $captured->getHeaderLine('Authorization'));
        self::assertSame('14400', $captured->getHeaderLine('TTL'));
        $body = (string) $captured->getBody();
        self::assertNotSame('', $body);
        self::assertStringNotContainsString('Geheimer Titel', $body, 'Inhalt ist verschlüsselt');
    }

    public function testStatusCodesMapToResults(): void
    {
        self::assertSame(PushResult::Gone, $this->sender(410)->send($this->subscription(), '{}'));
        self::assertSame(PushResult::Gone, $this->sender(404)->send($this->subscription(), '{}'));
        self::assertSame(PushResult::Failed, $this->sender(500)->send($this->subscription(), '{}'));
        self::assertSame(PushResult::Failed, $this->sender(429)->send($this->subscription(), '{}'));
    }

    public function testBrokenSubscriptionKeysFailGracefully(): void
    {
        $result = $this->sender(201)->send(['endpoint' => 'https://fcm.googleapis.com/x', 'p256dh' => 'kaputt', 'auth' => 'x'], '{}');

        self::assertSame(PushResult::Failed, $result);
    }
}
