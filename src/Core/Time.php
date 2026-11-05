<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Zeiten werden in UTC gespeichert und in Europe/Berlin angezeigt.
 */
final class Time
{
    public const DISPLAY_ZONE = 'Europe/Berlin';

    public static function berlin(?string $utc, string $format = 'd.m.Y H:i'): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        $date = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));

        return $date->setTimezone(new \DateTimeZone(self::DISPLAY_ZONE))->format($format);
    }
}
