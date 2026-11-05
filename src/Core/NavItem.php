<?php

declare(strict_types=1);

namespace App\Core;

final class NavItem
{
    public function __construct(
        public readonly string $label,
        public readonly string $path,
        public readonly int $sort = 100,
    ) {
    }
}
