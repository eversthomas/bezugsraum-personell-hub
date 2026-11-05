<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Entscheidet, ob eine IP-Adresse im öffentlichen Internet liegt.
 * Alles Private, Lokale, Reservierte und Multicast ist verboten.
 */
final class IpPolicy
{
    /** @var list<array{string, int}> */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8],        // "dieses Netz"
        ['10.0.0.0', 8],       // privat
        ['100.64.0.0', 10],    // Carrier-Grade NAT
        ['127.0.0.0', 8],      // Loopback
        ['169.254.0.0', 16],   // Link-Local (u. a. Cloud-Metadaten)
        ['172.16.0.0', 12],    // privat
        ['192.0.0.0', 24],     // IETF-Protokolle
        ['192.0.2.0', 24],     // Dokumentation
        ['192.88.99.0', 24],   // 6to4-Relay
        ['192.168.0.0', 16],   // privat
        ['198.18.0.0', 15],    // Benchmarks
        ['198.51.100.0', 24],  // Dokumentation
        ['203.0.113.0', 24],   // Dokumentation
        ['224.0.0.0', 4],      // Multicast
        ['240.0.0.0', 4],      // reserviert, inkl. Broadcast
    ];

    /** @var list<array{string, int}> */
    private const BLOCKED_V6 = [
        ['::', 128],           // unspezifiziert
        ['::1', 128],          // Loopback
        ['100::', 64],         // Discard
        ['2001:db8::', 32],    // Dokumentation
        ['fc00::', 7],         // Unique Local
        ['fe80::', 10],        // Link-Local
        ['ff00::', 8],         // Multicast
    ];

    public function isPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            return !$this->inAny($packed, self::BLOCKED_V4);
        }

        // IPv4 in IPv6 verpackt (::ffff:a.b.c.d, ::a.b.c.d, 64:ff9b::a.b.c.d): die enthaltene v4 prüfen.
        $embedded = $this->embeddedV4($packed);
        if ($embedded !== null) {
            return !$this->inAny($embedded, self::BLOCKED_V4);
        }

        return !$this->inAny($packed, self::BLOCKED_V6);
    }

    private function embeddedV4(string $packed): ?string
    {
        $prefixMapped = str_repeat("\x00", 10) . "\xff\xff";
        if (str_starts_with($packed, $prefixMapped)) {
            return substr($packed, 12);
        }
        if (str_starts_with($packed, str_repeat("\x00", 12))) {
            return substr($packed, 12);
        }
        if (str_starts_with($packed, "\x00\x64\xff\x9b" . str_repeat("\x00", 8))) {
            return substr($packed, 12);
        }

        return null;
    }

    /** @param list<array{string, int}> $ranges */
    private function inAny(string $packed, array $ranges): bool
    {
        foreach ($ranges as [$network, $bits]) {
            $net = (string) inet_pton($network);
            $fullBytes = intdiv($bits, 8);
            if (substr($packed, 0, $fullBytes) !== substr($net, 0, $fullBytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($packed[$fullBytes]) & $mask) === (ord($net[$fullBytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
