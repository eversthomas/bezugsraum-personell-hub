<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lesender Zugriff auf die Konfiguration mit Punkt-Schreibweise ("db.host").
 */
final class Config
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Konfigurationsdatei fehlt: ' . basename($path));
        }
        $values = require $path;
        if (!is_array($values)) {
            throw new \RuntimeException('Konfigurationsdatei muss ein Array liefern.');
        }

        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $current = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return $default;
            }
            $current = $current[$part];
        }

        return $current;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }
}
