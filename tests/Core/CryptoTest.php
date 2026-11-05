<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    private function crypto(): Crypto
    {
        return new Crypto(sodium_hex2bin(Crypto::generateKeyHex()));
    }

    public function testRoundTrip(): void
    {
        $crypto = $this->crypto();
        $cipher = $crypto->encrypt('https://example.test/geheim.ics');

        self::assertStringNotContainsString('geheim', $cipher);
        self::assertSame('https://example.test/geheim.ics', $crypto->decrypt($cipher));
    }

    public function testEncryptionIsRandomized(): void
    {
        $crypto = $this->crypto();

        self::assertNotSame($crypto->encrypt('a'), $crypto->encrypt('a'));
    }

    public function testTamperedCipherIsRejected(): void
    {
        $crypto = $this->crypto();
        $cipher = $crypto->encrypt('text');
        $cipher[strlen($cipher) - 1] = $cipher[strlen($cipher) - 1] === 'a' ? 'b' : 'a';

        $this->expectException(\RuntimeException::class);
        $crypto->decrypt($cipher);
    }

    public function testWrongKeyIsRejected(): void
    {
        $cipher = $this->crypto()->encrypt('text');

        $this->expectException(\RuntimeException::class);
        $this->crypto()->decrypt($cipher);
    }

    public function testHmacIsStablePerPurpose(): void
    {
        $crypto = $this->crypto();

        self::assertSame($crypto->hmac('ip', '1.2.3.4'), $crypto->hmac('ip', '1.2.3.4'));
        self::assertNotSame($crypto->hmac('ip', '1.2.3.4'), $crypto->hmac('user', '1.2.3.4'));
    }

    public function testInvalidKeyLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Crypto('kurz');
    }
}
