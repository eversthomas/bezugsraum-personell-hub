<?php

declare(strict_types=1);

namespace App\Core;

/** Ein vorgeschlagener Zeitblock, der in einem Fremdkalender angelegt werden soll. */
final class PlannedEvent
{
    public function __construct(
        public readonly string $title,
        public readonly \DateTimeImmutable $start,
        public readonly \DateTimeImmutable $end,
        public readonly string $notes = '',
    ) {
    }
}
