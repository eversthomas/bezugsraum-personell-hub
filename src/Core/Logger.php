<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Schreibt Ereignisse ohne Inhalte (keine Titel, Notizen, URLs oder Tokens).
 */
final class Logger
{
    public function __construct(private readonly string $file)
    {
    }

    /** @param array<string, scalar> $context nur technische Kennwerte */
    public function log(string $level, string $event, array $context = []): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $line = sprintf(
            "%s %s %s %s\n",
            gmdate('c'),
            strtoupper($level),
            $event,
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }

    public function error(string $event, \Throwable $e): void
    {
        // Nur Typ und Ort, die Meldung könnte Inhalte enthalten.
        $this->log('error', $event, [
            'type' => $e::class,
            'where' => basename($e->getFile()) . ':' . $e->getLine(),
        ]);
    }
}
