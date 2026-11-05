<?php

declare(strict_types=1);

namespace Tests\Calendar;

use App\Calendar\HttpResponse;
use App\Calendar\HttpTransport;
use App\Calendar\Target;

final class FakeTransport implements HttpTransport
{
    /** @var list<Target> */
    public array $targets = [];
    /** @var list<array<string, string>> */
    public array $requestHeaders = [];

    /** @param list<HttpResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    public function push(HttpResponse ...$responses): void
    {
        array_push($this->responses, ...$responses);
    }

    public function get(Target $target, array $headers, int $timeoutSeconds, int $maxBytes): HttpResponse
    {
        $this->targets[] = $target;
        $this->requestHeaders[] = $headers;
        $response = array_shift($this->responses);
        if ($response === null) {
            throw new \LogicException('Keine weitere Antwort vorbereitet.');
        }

        return $response;
    }
}
