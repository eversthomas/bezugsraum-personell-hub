<?php

declare(strict_types=1);

namespace App\Calendar;

final class HttpResponse
{
    /** @param array<string, string> $headers Namen kleingeschrieben */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }
}
