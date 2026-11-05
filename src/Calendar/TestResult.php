<?php

declare(strict_types=1);

namespace App\Calendar;

final class TestResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly int $eventCount,
        public readonly ?string $error,
    ) {
    }
}
