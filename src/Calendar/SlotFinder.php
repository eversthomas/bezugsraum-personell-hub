<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Findet freie Zeitfenster innerhalb der Arbeitszeiten, bezogen auf die Termine aller Quellen.
 * Reine Rechenlogik ohne Datenbank, damit die Randfälle gut testbar sind.
 */
final class SlotFinder
{
    public const GRANULARITY_MINUTES = 15;
    public const LEAD_MINUTES = 15;

    public function __construct(
        private readonly WorkingHours $hours,
        private readonly \DateTimeZone $zone = new \DateTimeZone('Europe/Berlin'),
    ) {
    }

    /**
     * @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $busy belegte Zeiten (alle Quellen)
     * @param int $perDay höchstens so viele Vorschläge je Tag
     * @return list<Slot> nach Beginn sortiert
     */
    public function find(array $busy, \DateTimeImmutable $now, int $durationMinutes, int $days = 7, int $perDay = 3): array
    {
        if ($durationMinutes < 5 || $durationMinutes > 8 * 60 || $days < 1 || $perDay < 1) {
            throw new \InvalidArgumentException('Ungültige Suchparameter.');
        }

        $buffer = $this->hours->bufferMinutes * 60;
        $blocked = [];
        foreach ($busy as $interval) {
            $blocked[] = [$interval['start']->getTimestamp() - $buffer, $interval['end']->getTimestamp() + $buffer];
        }
        $blocked = $this->merge($blocked);

        $earliest = $now->getTimestamp() + self::LEAD_MINUTES * 60;
        $slots = [];
        $day = $now->setTimezone($this->zone)->setTime(0, 0);
        for ($i = 0; $i < $days; ++$i, $day = $day->modify('+1 day')) {
            if (!in_array((int) $day->format('N'), $this->hours->days, true)) {
                continue;
            }
            $windowStart = max($this->at($day, $this->hours->startMinute), $earliest);
            $windowEnd = $this->at($day, $this->hours->endMinute);
            $found = 0;
            foreach ($this->gaps($windowStart, $windowEnd, $blocked) as [$from, $to]) {
                $start = $this->roundUp($from);
                if ($start + $durationMinutes * 60 > $to) {
                    continue;
                }
                $slots[] = new Slot(
                    (new \DateTimeImmutable('@' . $start))->setTimezone($this->zone),
                    (new \DateTimeImmutable('@' . ($start + $durationMinutes * 60)))->setTimezone($this->zone),
                );
                if (++$found >= $perDay) {
                    break;
                }
            }
        }

        return $slots;
    }

    private function at(\DateTimeImmutable $day, int $minute): int
    {
        // setTime auf der Berliner Ortszeit, damit auch Tage mit Zeitumstellung stimmen
        return $day->setTime(intdiv($minute, 60) % 24, $minute % 60)->modify($minute >= 24 * 60 ? '+1 day' : '+0 day')->getTimestamp();
    }

    private function roundUp(int $timestamp): int
    {
        $step = self::GRANULARITY_MINUTES * 60;

        return (int) (ceil($timestamp / $step) * $step);
    }

    /**
     * @param list<array{int, int}> $intervals
     * @return list<array{int, int}>
     */
    private function merge(array $intervals): array
    {
        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($intervals as [$from, $to]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $from <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $to);
            } else {
                $merged[] = [$from, $to];
            }
        }

        return $merged;
    }

    /**
     * Freie Lücken im Fenster [from, to].
     *
     * @param list<array{int, int}> $blocked
     * @return list<array{int, int}>
     */
    private function gaps(int $from, int $to, array $blocked): array
    {
        $gaps = [];
        $cursor = $from;
        foreach ($blocked as [$blockStart, $blockEnd]) {
            if ($blockEnd <= $cursor) {
                continue;
            }
            if ($blockStart >= $to) {
                break;
            }
            if ($blockStart > $cursor) {
                $gaps[] = [$cursor, $blockStart];
            }
            $cursor = max($cursor, $blockEnd);
        }
        if ($cursor < $to) {
            $gaps[] = [$cursor, $to];
        }

        return $gaps;
    }
}
