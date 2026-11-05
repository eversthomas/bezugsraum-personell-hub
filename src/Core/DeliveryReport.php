<?php

declare(strict_types=1);

namespace App\Core;

final class DeliveryReport
{
    public function __construct(
        public readonly int $delivered,
        public readonly int $failed,
        public readonly int $removed,
    ) {
    }

    /** Es gab Empfänger, aber keiner hat die Nachricht erhalten. */
    public function failedEntirely(): bool
    {
        return $this->delivered === 0 && $this->failed > 0;
    }
}
