<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * SSRF-Schutz: nur https auf Port 443, keine Zugangsdaten in der URL,
 * der Host darf nur auf öffentliche Adressen zeigen.
 */
final class UrlGuard
{
    public function __construct(
        private readonly HostResolver $resolver,
        private readonly IpPolicy $policy = new IpPolicy(),
    ) {
    }

    /** webcal:// ist nur eine Schreibweise für https:// (Google, Apple). */
    public static function normalize(string $url): string
    {
        $url = trim($url);
        if (stripos($url, 'webcal://') === 0) {
            return 'https://' . substr($url, 9);
        }

        return $url;
    }

    /** @throws FetchException */
    public function check(string $url): Target
    {
        $url = self::normalize($url);
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new FetchException('Ungültige Adresse.');
        }
        if (strtolower($parts['scheme']) !== 'https') {
            throw new FetchException('Nur https-Adressen sind erlaubt.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new FetchException('Adressen mit Zugangsdaten sind nicht erlaubt.');
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            throw new FetchException('Nur der Standard-Port 443 ist erlaubt.');
        }
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new FetchException('Ungültige Adresse.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->resolve($host);
        if ($ips === []) {
            throw new FetchException('Der Servername ist nicht auflösbar.');
        }
        // Eine einzige verbotene Adresse genügt, um abzulehnen (kein Auswählen der „guten“).
        foreach ($ips as $ip) {
            if (!$this->policy->isPublic($ip)) {
                throw new FetchException('Die Adresse zeigt nicht auf das öffentliche Internet.');
            }
        }

        return new Target($url, $host, $ips[0]);
    }
}
