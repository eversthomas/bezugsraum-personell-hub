<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Auswahl der Quellfarben. Gespeichert wird der helle Farbwert, die Darstellung läuft über
 * CSS-Klassen (die Content-Security-Policy erlaubt keine Inline-Styles), dort mit dunkler Variante.
 */
final class Palette
{
    /** @var array<string, array{hex: string, label: string}> */
    public const COLORS = [
        'blue' => ['hex' => '#2563EB', 'label' => 'Blau'],
        'orange' => ['hex' => '#C2410C', 'label' => 'Orange'],
        'violet' => ['hex' => '#7C3AED', 'label' => 'Violett'],
        'green' => ['hex' => '#15803D', 'label' => 'Grün'],
        'teal' => ['hex' => '#0F766E', 'label' => 'Petrol'],
        'rose' => ['hex' => '#BE185D', 'label' => 'Rosa'],
    ];

    public static function classFor(string $hex): string
    {
        foreach (self::COLORS as $key => $color) {
            if (strcasecmp($color['hex'], $hex) === 0) {
                return 'src-' . $key;
            }
        }

        return 'src-neutral';
    }

    public static function isKnown(string $hex): bool
    {
        return self::classFor($hex) !== 'src-neutral';
    }
}
