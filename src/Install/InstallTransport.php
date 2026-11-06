<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Request;

/**
 * Die Einrichtung läuft über HTTPS. http:// gilt nur für den lokalen Rechner.
 * X-Forwarded-Proto zählt hier nicht.
 */
final class InstallTransport
{
    public function allows(Request $request): bool
    {
        if ($request->isHttps(false)) {
            return true;
        }
        $host = $this->requestHost($request);
        if ($host !== '') {
            return self::isLocalName($host);
        }

        return $request->ip() === '' || self::isLocalName($request->ip());
    }

    public static function isLocalName(string $host): bool
    {
        $name = strtolower(trim($host));
        if (str_starts_with($name, '[')) {
            $end = strpos($name, ']');
            $name = $end === false ? substr($name, 1) : substr($name, 1, $end - 1);
        } elseif (substr_count($name, ':') === 1) {
            $name = explode(':', $name, 2)[0];
        }

        return in_array($name, ['localhost', '127.0.0.1', '::1'], true);
    }

    public static function allowsHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'http') {
            return true;
        }
        if (!isset($parts['host']) || $parts['host'] === '') {
            return false;
        }

        return self::isLocalName($parts['host']);
    }

    private function requestHost(Request $request): string
    {
        $host = $request->server['HTTP_HOST'] ?? '';

        return is_string($host) ? $host : '';
    }
}
