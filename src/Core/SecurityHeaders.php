<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Setzt auf jede Antwort die Pflicht-Header (Suchmaschinen, CSP, HSTS, ...).
 */
final class SecurityHeaders
{
    public static function apply(Response $response, bool $https): Response
    {
        $headers = [
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(self), microphone=(self), geolocation=()',
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self'; "
                . "img-src 'self' data:; font-src 'self'; connect-src 'self'; manifest-src 'self'; "
                . "worker-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ];
        if ($https) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        if (!isset($response->headers['Cache-Control'])) {
            $headers['Cache-Control'] = 'no-store';
        }
        $result = $response;
        foreach ($headers as $name => $value) {
            $result = $result->withHeader($name, $value);
        }

        return $result;
    }
}
