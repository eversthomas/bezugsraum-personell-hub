<?php

declare(strict_types=1);

namespace App\Calendar;

final class PlannedBlock
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $itemId,
        public readonly string $title,
        public readonly \DateTimeImmutable $startsAt,
        public readonly \DateTimeImmutable $endsAt,
        public readonly string $target,
        public readonly string $status,
    ) {
    }
}
