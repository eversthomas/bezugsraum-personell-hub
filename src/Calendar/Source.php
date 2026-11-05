<?php

declare(strict_types=1);

namespace App\Calendar;

final class Source
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $context,
        public readonly string $color,
        public readonly string $urlEncrypted,
        public readonly string $urlHint,
        public readonly int $intervalMin,
        public readonly bool $active,
        public readonly ?string $etag,
        public readonly ?string $lastAttemptAt,
        public readonly ?string $lastOkAt,
        public readonly ?string $lastError,
        public readonly ?int $remindBeforeMin = null,
    ) {
    }
}
