<?php

declare(strict_types=1);

/**
 * Einzige globale Funktion: HTML-Ausgabe escapen.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
