<?php

declare(strict_types=1);

namespace App\Items;

final class Item
{
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $origin,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $context,
        public readonly ?string $dueDate,
        public readonly string $createdAt,
    ) {
    }
}
