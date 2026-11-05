<?php

declare(strict_types=1);

namespace App\Calendar;

final class FetchResult
{
    public function __construct(
        public readonly bool $notModified,
        public readonly string $body,
        public readonly ?string $etag,
    ) {
    }
}
