<?php

declare(strict_types=1);

namespace App\Calendar;

interface HostResolver
{
    /** @return list<string> IPv4/IPv6-Adressen des Hosts (leer, wenn nicht auflösbar) */
    public function resolve(string $host): array;
}
