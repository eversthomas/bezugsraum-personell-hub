<?php

declare(strict_types=1);

namespace App\Calendar;

final class CurlTransport implements HttpTransport
{
    public function get(Target $target, array $headers, int $timeoutSeconds, int $maxBytes): HttpResponse
    {
        $ch = curl_init($target->url);
        if ($ch === false) {
            throw new FetchException('Abruf nicht möglich.');
        }

        $body = '';
        $responseHeaders = [];
        $tooLarge = false;
        $requestHeaders = [];
        foreach ($headers as $name => $value) {
            $requestHeaders[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_USERAGENT => 'PersonalHub/1.0',
            CURLOPT_ENCODING => '',
            // An die geprüfte IP binden, damit ein zweiter DNS-Abruf nichts Anderes liefern kann.
            CURLOPT_RESOLVE => [$target->host . ':443:' . $target->ip],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;

                    return 0; // bricht die Übertragung ab
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($tooLarge) {
            throw new FetchException('Die Antwort ist zu groß.');
        }
        if ($ok === false) {
            throw new FetchException($errno === CURLE_OPERATION_TIMEOUTED ? 'Zeitüberschreitung beim Abruf.' : 'Verbindung fehlgeschlagen.');
        }

        return new HttpResponse($status, $responseHeaders, $body);
    }
}
