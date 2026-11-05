<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimaler Container: Fabriken pro Dienst, Instanzen werden einmal erzeugt.
 * Kein Autowiring, damit nachvollziehbar bleibt, was woher kommt.
 */
final class Container
{
    /** @var array<string, callable(self): mixed> */
    private array $factories = [];
    /** @var array<string, mixed> */
    private array $instances = [];

    /** @param callable(self): mixed $factory */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (!array_key_exists($id, $this->instances)) {
            if (!isset($this->factories[$id])) {
                throw new \RuntimeException('Dienst nicht registriert: ' . $id);
            }
            $this->instances[$id] = ($this->factories[$id])($this);
        }
        $service = $this->instances[$id];
        if (!$service instanceof $id) {
            throw new \RuntimeException('Dienst hat falschen Typ: ' . $id);
        }

        return $service;
    }
}
