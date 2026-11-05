<?php

declare(strict_types=1);

namespace App\Core;

final class CronJob
{
    /** @param callable(Container): void $run */
    public function __construct(
        public readonly string $name,
        public readonly int $everyMinutes,
        public readonly mixed $run,
    ) {
    }
}
