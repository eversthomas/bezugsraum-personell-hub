<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Abbruch einer Migration. Die Meldung nennt nur den Zustand, nie SQL oder Datenbanktexte.
 */
final class MigrationException extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly bool $busy,
        public readonly string $filename,
    ) {
        parent::__construct($message);
    }

    public static function busy(): self
    {
        return new self('Aktualisierung läuft bereits.', true, '');
    }

    public static function failed(string $filename): self
    {
        return new self($filename, false, $filename);
    }
}
