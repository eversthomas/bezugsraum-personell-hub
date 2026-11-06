<?php

declare(strict_types=1);

namespace App\Install;

/**
 * Prüft, ob dieser Rechner die Anwendung einrichten kann. Leere Liste heißt: alles in Ordnung.
 */
final class SystemCheck
{
    /** @var list<string> */
    public const EXTENSIONS = ['pdo_mysql', 'sodium', 'gd', 'intl', 'curl', 'mbstring', 'openssl'];

    /** @var (callable(string): bool)|null */
    private $loaded;

    /**
     * @param (callable(string): bool)|null $loaded
     */
    public function __construct(
        private readonly string $root,
        private readonly ?int $phpVersionId = null,
        ?callable $loaded = null,
    ) {
        $this->loaded = $loaded;
    }

    /** @return list<string> */
    public function problems(): array
    {
        $problems = [];
        $version = $this->phpVersionId ?? PHP_VERSION_ID;
        if ($version < 80300) {
            $problems[] = 'PHP ist älter als 8.3.';
        }
        foreach (self::EXTENSIONS as $extension) {
            if (!$this->isLoaded($extension)) {
                $problems[] = 'Die Erweiterung ' . $extension . ' fehlt.';
            }
        }
        if (!$this->isLoaded('gmp') && !$this->isLoaded('bcmath')) {
            $problems[] = 'Für den Push-Versand fehlt gmp oder bcmath.';
        }
        if (!is_dir($this->root . '/storage') || !is_writable($this->root . '/storage')) {
            $problems[] = 'Das Verzeichnis storage/ fehlt oder ist nicht beschreibbar.';
        }
        if (!is_dir($this->root . '/config') || !is_writable($this->root . '/config')) {
            $problems[] = 'Das Verzeichnis config/ fehlt oder ist nicht beschreibbar.';
        }

        return $problems;
    }

    private function isLoaded(string $extension): bool
    {
        if ($this->loaded === null) {
            return extension_loaded($extension);
        }

        return ($this->loaded)($extension);
    }
}
