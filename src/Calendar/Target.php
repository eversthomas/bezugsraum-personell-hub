<?php

declare(strict_types=1);

namespace App\Calendar;

/** Geprüftes Abrufziel: die URL und die IP, an die verbunden werden muss (gegen DNS-Rebinding). */
final class Target
{
    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly string $ip,
    ) {
    }
}
