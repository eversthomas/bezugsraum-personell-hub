<?php

declare(strict_types=1);

namespace Tests\Calendar;

use App\Calendar\HostResolver;

final class FakeResolver implements HostResolver
{
    /** @param array<string, list<string>> $map */
    public function __construct(private array $map = [])
    {
    }

    public function resolve(string $host): array
    {
        return $this->map[$host] ?? [];
    }
}
