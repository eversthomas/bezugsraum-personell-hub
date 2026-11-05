<?php

declare(strict_types=1);

namespace App\Media;

use App\Core\Contracts\MediaStorageInterface;

/**
 * Lokaler Ordner (storage/uploads/). Dateinamen kommen nie vom Nutzer.
 */
final class LocalMediaStorage implements MediaStorageInterface
{
    public function __construct(private readonly string $dir)
    {
    }

    public function put(string $name, string $data): void
    {
        $path = $this->path($name);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Speicherordner nicht beschreibbar.');
        }
        if (file_put_contents($path, $data, LOCK_EX) === false) {
            throw new \RuntimeException('Datei konnte nicht gespeichert werden.');
        }
        chmod($path, 0600);
    }

    public function get(string $name): ?string
    {
        $path = $this->path($name);
        $data = is_file($path) ? file_get_contents($path) : false;

        return $data === false ? null : $data;
    }

    public function delete(string $name): void
    {
        $path = $this->path($name);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function path(string $name): string
    {
        if (preg_match('/^[a-f0-9-]{36}$/', $name) !== 1) {
            throw new \InvalidArgumentException('Ungültiger Dateiname.');
        }

        return $this->dir . '/' . substr($name, 0, 2) . '/' . $name;
    }
}
