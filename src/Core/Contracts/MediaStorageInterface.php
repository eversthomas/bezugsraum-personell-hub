<?php

declare(strict_types=1);

namespace App\Core\Contracts;

interface MediaStorageInterface
{
    /** Speichert Daten unter einem vom System vergebenen Namen (nur [a-f0-9-]). */
    public function put(string $name, string $data): void;

    public function get(string $name): ?string;

    public function delete(string $name): void;
}
