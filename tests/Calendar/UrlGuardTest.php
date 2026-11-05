<?php

declare(strict_types=1);

namespace Tests\Calendar;

use App\Calendar\FetchException;
use App\Calendar\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlGuardTest extends TestCase
{
    private function guard(): UrlGuard
    {
        return new UrlGuard(new FakeResolver([
            'kalender.example' => ['93.184.216.34'],
            'intern.example' => ['10.0.0.5'],
            'gemischt.example' => ['93.184.216.34', '127.0.0.1'],
            'v6.example' => ['2606:4700:4700::1111'],
        ]));
    }

    public function testAcceptsPublicHttps(): void
    {
        $target = $this->guard()->check('https://kalender.example/cal.ics?token=abc');

        self::assertSame('kalender.example', $target->host);
        self::assertSame('93.184.216.34', $target->ip);
    }

    public function testWebcalIsTreatedAsHttps(): void
    {
        self::assertSame('https://kalender.example/x.ics', $this->guard()->check('webcal://kalender.example/x.ics')->url);
    }

    public function testAcceptsPublicIpLiteral(): void
    {
        self::assertSame('8.8.8.8', $this->guard()->check('https://8.8.8.8/cal.ics')->ip);
        self::assertSame('2606:4700:4700::1111', $this->guard()->check('https://[2606:4700:4700::1111]/cal.ics')->ip);
    }

    /** @return array<string, array{string}> */
    public static function rejected(): array
    {
        $urls = [
            'http://kalender.example/cal.ics',
            'ftp://kalender.example/cal.ics',
            'file:///etc/passwd',
            'gopher://kalender.example/',
            'javascript:alert(1)',
            'https://user:pass@kalender.example/cal.ics',
            'https://kalender.example:8443/cal.ics',
            'https://kalender.example:22/',
            'https://intern.example/cal.ics',
            'https://gemischt.example/cal.ics',
            'https://unbekannt.example/cal.ics',
            'https://127.0.0.1/cal.ics',
            'https://[::1]/cal.ics',
            'https://169.254.169.254/latest/meta-data/',
            'https://[::ffff:10.0.0.1]/',
            'https://0x7f000001/',
            'https://2130706433/',
            'https:///nohost',
            'kein url',
            '',
            "https://kalender.example/\ncal.ics",
        ];

        return array_combine(array_map(static fn (string $u): string => $u === '' ? '(leer)' : $u, $urls), array_map(static fn (string $u): array => [$u], $urls));
    }

    #[DataProvider('rejected')]
    public function testRejects(string $url): void
    {
        $this->expectException(FetchException::class);
        $this->guard()->check($url);
    }

    public function testErrorMessagesNeverContainTheUrl(): void
    {
        try {
            $this->guard()->check('https://intern.example/geheimer-pfad?token=SECRET');
            self::fail('Erwartet FetchException');
        } catch (FetchException $e) {
            self::assertStringNotContainsString('SECRET', $e->getMessage());
            self::assertStringNotContainsString('intern.example', $e->getMessage());
        }
    }
}
