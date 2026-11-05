<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Einziger Weg, ICS-Adressen abzurufen. Jeder Schritt (auch jede Weiterleitung) wird neu geprüft.
 */
final class IcsFetcher
{
    public const MAX_REDIRECTS = 3;
    public const TIMEOUT_SECONDS = 10;
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly UrlGuard $guard,
        private readonly HttpTransport $transport,
    ) {
    }

    /** @throws FetchException */
    public function fetch(string $url, ?string $etag = null): FetchResult
    {
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            $target = $this->guard->check($current);
            $headers = ['Accept' => 'text/calendar, text/plain;q=0.8, */*;q=0.1'];
            if ($etag !== null && $etag !== '') {
                $headers['If-None-Match'] = $etag;
            }
            $response = $this->transport->get($target, $headers, self::TIMEOUT_SECONDS, self::MAX_BYTES);

            if (in_array($response->status, [301, 302, 303, 307, 308], true)) {
                $location = $response->headers['location'] ?? '';
                if ($location === '') {
                    throw new FetchException('Ungültige Weiterleitung.');
                }
                $current = $this->resolveLocation($target->url, $location);
                continue;
            }
            if ($response->status === 304) {
                return new FetchResult(true, '', $etag);
            }
            if ($response->status === 401 || $response->status === 403) {
                throw new FetchException('Zugriff verweigert. Ist die Adresse noch gültig?');
            }
            if ($response->status === 404 || $response->status === 410) {
                throw new FetchException('Kalender nicht gefunden. Ist die Adresse noch gültig?');
            }
            if ($response->status < 200 || $response->status >= 300) {
                throw new FetchException('Der Server antwortet mit einem Fehler (' . $response->status . ').');
            }
            if (!str_contains($response->body, 'BEGIN:VCALENDAR')) {
                throw new FetchException('Die Antwort ist kein Kalender.');
            }

            return new FetchResult(false, $response->body, $response->headers['etag'] ?? null);
        }

        throw new FetchException('Zu viele Weiterleitungen.');
    }

    private function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = 'https://' . ($parts['host'] ?? '');
        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $origin . $dir . '/' . $location;
    }
}
