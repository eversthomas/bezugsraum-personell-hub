<?php

declare(strict_types=1);

namespace App\Links;

final class QuickLink
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly string $url,
        public readonly ?string $context,
        public readonly string $symbol,
    ) {
    }
}
