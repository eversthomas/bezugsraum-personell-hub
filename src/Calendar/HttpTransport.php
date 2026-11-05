<?php

declare(strict_types=1);

namespace App\Calendar;

interface HttpTransport
{
    /**
     * Ein einzelner GET ohne automatische Weiterleitung, verbunden mit genau der geprüften IP.
     *
     * @param array<string, string> $headers
     * @throws FetchException bei Netzwerkfehler, Zeitüberschreitung oder zu großer Antwort
     */
    public function get(Target $target, array $headers, int $timeoutSeconds, int $maxBytes): HttpResponse;
}
