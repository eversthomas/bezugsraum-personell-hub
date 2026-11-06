<?php

declare(strict_types=1);

namespace App\Install;

/**
 * Der Dokumentenstamm muss der Ordner public/ sein. Sonst wären Schlüssel und Code abrufbar.
 */
final class DocumentRoot
{
    public function matches(string $root, string $documentRoot): bool
    {
        if ($documentRoot === '') {
            return true;
        }
        $public = realpath($root . '/public');
        $current = realpath($documentRoot);

        return $public !== false && $current !== false && $public === $current;
    }
}
