<?php

declare(strict_types=1);

namespace App\Notify\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18-Client auf Basis von cURL: nur https, keine Weiterleitungen, kurze Zeitlimits, Antwort höchstens 64 KB.
 */
final class CurlClient implements ClientInterface
{
    public function __construct(private readonly int $timeoutSeconds = 10)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($request->getUri()->getScheme() !== 'https') {
            throw new ClientException('Nur https ist erlaubt.');
        }
        $ch = curl_init((string) $request->getUri());
        if ($ch === false) {
            throw new ClientException('Anfrage nicht möglich.');
        }

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (strtolower($name) !== 'host') {
                $headers[] = $name . ': ' . implode(', ', $values);
            }
        }
        $body = '';
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => (string) $request->getBody(),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSeconds),
            CURLOPT_USERAGENT => 'PersonalHub/1.0',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 65536) {
                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($ok === false && $status === 0) {
            throw new ClientException('Verbindung zum Push-Dienst fehlgeschlagen.');
        }

        return new Response($status, $responseHeaders, $body);
    }
}
