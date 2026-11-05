<?php

declare(strict_types=1);

namespace Tests\Calendar;

use App\Calendar\IpPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpPolicyTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function blocked(): array
    {
        $ips = [
            '127.0.0.1', '127.255.255.254', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.1.1',
            '169.254.169.254', '0.0.0.0', '100.64.0.1', '100.127.255.255', '224.0.0.1', '255.255.255.255',
            '198.18.0.1', '192.0.2.5', '::1', '::', 'fe80::1', 'fc00::1', 'fd12:3456::1', 'ff02::1',
            '2001:db8::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '::127.0.0.1',
            '64:ff9b::7f00:1', 'kein-ip', '',
        ];

        return array_combine($ips, array_map(static fn (string $ip): array => [$ip], $ips));
    }

    /** @return array<string, array{string}> */
    public static function allowed(): array
    {
        $ips = ['8.8.8.8', '93.184.216.34', '172.15.0.1', '172.32.0.1', '100.63.255.255', '100.128.0.1',
            '2606:4700:4700::1111', '::ffff:8.8.8.8', '2a00:1450:4001::1'];

        return array_combine($ips, array_map(static fn (string $ip): array => [$ip], $ips));
    }

    #[DataProvider('blocked')]
    public function testBlocked(string $ip): void
    {
        self::assertFalse((new IpPolicy())->isPublic($ip));
    }

    #[DataProvider('allowed')]
    public function testAllowed(string $ip): void
    {
        self::assertTrue((new IpPolicy())->isPublic($ip));
    }
}
