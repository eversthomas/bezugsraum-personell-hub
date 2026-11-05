<?php

declare(strict_types=1);

namespace App\Items;

/**
 * Wiederholungsmuster: "daily", "weekly:mon" (Wochentag), "monthly:15" (Tag im Monat; der 31. fällt in kürzeren Monaten auf den letzten Tag).
 */
final class Recurrence
{
    public const DAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

    private function __construct(
        public readonly string $kind,
        public readonly int $value,
    ) {
    }

    public static function parse(?string $pattern): ?self
    {
        if ($pattern === null || $pattern === '') {
            return null;
        }
        if ($pattern === 'daily') {
            return new self('daily', 0);
        }
        if (preg_match('/^weekly:(mon|tue|wed|thu|fri|sat|sun)$/', $pattern, $m) === 1) {
            return new self('weekly', self::DAYS[$m[1]]);
        }
        if (preg_match('/^monthly:(\d{1,2})$/', $pattern, $m) === 1 && (int) $m[1] >= 1 && (int) $m[1] <= 31) {
            return new self('monthly', (int) $m[1]);
        }

        return null;
    }

    public function label(): string
    {
        $days = ['', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

        return match ($this->kind) {
            'daily' => 'täglich',
            'weekly' => 'wöchentlich, ' . $days[$this->value],
            default => 'monatlich, am ' . $this->value . '.',
        };
    }

    /** Erster passender Tag nach $after (immer streng danach). */
    public function next(\DateTimeImmutable $after): \DateTimeImmutable
    {
        $after = $after->setTime(0, 0);

        return match ($this->kind) {
            'daily' => $after->modify('+1 day'),
            'weekly' => $this->nextWeekly($after),
            default => $this->nextMonthly($after),
        };
    }

    private function nextWeekly(\DateTimeImmutable $after): \DateTimeImmutable
    {
        $current = (int) $after->format('N');
        $delta = ($this->value - $current + 7) % 7;

        return $after->modify('+' . ($delta === 0 ? 7 : $delta) . ' days');
    }

    private function nextMonthly(\DateTimeImmutable $after): \DateTimeImmutable
    {
        for ($offset = 0; $offset <= 2; ++$offset) {
            $first = $after->modify('first day of this month')->modify('+' . $offset . ' months');
            $day = min($this->value, (int) $first->format('t'));
            $candidate = $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day);
            if ($candidate > $after) {
                return $candidate;
            }
        }

        throw new \LogicException('Kein nächster Termin gefunden.');
    }
}
