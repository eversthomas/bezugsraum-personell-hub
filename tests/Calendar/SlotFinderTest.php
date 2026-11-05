<?php

declare(strict_types=1);

namespace Tests\Calendar;

use App\Calendar\Slot;
use App\Calendar\SlotFinder;
use App\Calendar\WorkingHours;
use PHPUnit\Framework\TestCase;

final class SlotFinderTest extends TestCase
{
    private \DateTimeZone $berlin;

    protected function setUp(): void
    {
        $this->berlin = new \DateTimeZone('Europe/Berlin');
    }

    private function at(string $local): \DateTimeImmutable
    {
        return new \DateTimeImmutable($local, $this->berlin);
    }

    /** @return array{start: \DateTimeImmutable, end: \DateTimeImmutable} */
    private function busy(string $from, string $to): array
    {
        return ['start' => $this->at($from), 'end' => $this->at($to)];
    }

    /** @param list<Slot> $slots @return list<string> */
    private function fmt(array $slots): array
    {
        return array_map(fn (Slot $s): string => $s->start->setTimezone($this->berlin)->format('D d.m. H:i') . '-' . $s->end->setTimezone($this->berlin)->format('H:i'), $slots);
    }

    private function finder(int $buffer = 0, int $start = 8, int $end = 17): SlotFinder
    {
        return new SlotFinder(new WorkingHours([1, 2, 3, 4, 5], $start * 60, $end * 60, $buffer));
    }

    public function testEmptyCalendarOffersEarliestSlotPerDay(): void
    {
        // Montag 9.11.2026, 06:00: Arbeitstag beginnt erst um 8:00
        $slots = $this->finder()->find([], $this->at('2026-11-09 06:00'), 60, 2, 1);

        self::assertSame(['Mon 09.11. 08:00-09:00', 'Tue 10.11. 08:00-09:00'], $this->fmt($slots));
    }

    public function testFindsGapsBetweenEventsOfAllSources(): void
    {
        $busy = [
            $this->busy('2026-11-09 08:00', '2026-11-09 10:00'), // Quelle A
            $this->busy('2026-11-09 09:30', '2026-11-09 11:00'), // Quelle B überlappt
            $this->busy('2026-11-09 13:00', '2026-11-09 14:00'),
        ];

        $slots = $this->finder()->find($busy, $this->at('2026-11-09 06:00'), 60, 1, 5);

        self::assertSame(['Mon 09.11. 11:00-12:00', 'Mon 09.11. 14:00-15:00'], $this->fmt($slots), 'je Lücke der früheste Vorschlag');
    }

    public function testBufferIsKeptBeforeAndAfterEvents(): void
    {
        $busy = [$this->busy('2026-11-09 09:00', '2026-11-09 10:00')];

        $slots = $this->finder(15)->find($busy, $this->at('2026-11-09 06:00'), 45, 1, 5);

        // Vor dem Termin: 8:00 bis 8:45 passt gerade nicht (Puffer bis 8:45 = Ende 8:45 > 8:45? passt genau)
        self::assertSame('Mon 09.11. 08:00-08:45', $this->fmt($slots)[0]);
        self::assertSame('Mon 09.11. 10:15-11:00', $this->fmt($slots)[1], 'nach dem Termin erst nach dem Puffer');
    }

    public function testGapTooShortIsSkipped(): void
    {
        $busy = [
            $this->busy('2026-11-09 08:00', '2026-11-09 10:00'),
            $this->busy('2026-11-09 10:20', '2026-11-09 17:00'),
        ];

        self::assertSame([], $this->finder()->find($busy, $this->at('2026-11-09 06:00'), 30, 1));
    }

    public function testBufferCanSwallowATightGap(): void
    {
        $busy = [
            $this->busy('2026-11-09 08:00', '2026-11-09 10:00'),
            $this->busy('2026-11-09 11:00', '2026-11-09 17:00'),
        ];

        self::assertCount(1, $this->finder(0)->find($busy, $this->at('2026-11-09 06:00'), 60, 1));
        self::assertCount(0, $this->finder(10)->find($busy, $this->at('2026-11-09 06:00'), 60, 1), '60 Min. Lücke, aber 20 Min. Puffer');
    }

    public function testWeekendsAreSkipped(): void
    {
        // Freitag 13.11. nach Feierabend → Montag 16.11.
        $slots = $this->finder()->find([], $this->at('2026-11-13 18:00'), 30, 4, 1);

        self::assertSame(['Mon 16.11. 08:00-08:30'], $this->fmt($slots));
    }

    public function testNowAndLeadTimeAreRespectedAndStartsAreRoundedUp(): void
    {
        // 9:07 + 15 Min. Vorlauf = 9:22 → aufgerundet auf 9:30
        $slots = $this->finder()->find([], $this->at('2026-11-09 09:07'), 30, 1, 1);

        self::assertSame(['Mon 09.11. 09:30-10:00'], $this->fmt($slots));
    }

    public function testNothingLeftTodayMovesToTomorrow(): void
    {
        $slots = $this->finder()->find([], $this->at('2026-11-09 16:50'), 60, 2, 1);

        self::assertSame(['Tue 10.11. 08:00-09:00'], $this->fmt($slots));
    }

    public function testEventSpanningMidnightBlocksNextMorning(): void
    {
        $busy = [$this->busy('2026-11-09 20:00', '2026-11-10 09:30')];

        $slots = $this->finder()->find($busy, $this->at('2026-11-10 06:00'), 30, 1, 1);

        self::assertSame(['Tue 10.11. 09:30-10:00'], $this->fmt($slots));
    }

    public function testDaylightSavingChangeDay(): void
    {
        // Sonntag 25.10.2026 ist die Zeitumstellung; Montag 26.10. beginnt der Arbeitstag um 8:00 MEZ
        $slots = $this->finder()->find([], $this->at('2026-10-23 18:00'), 60, 4, 1);

        self::assertSame(['Mon 26.10. 08:00-09:00'], $this->fmt($slots));
        self::assertSame('+01:00', $slots[0]->start->format('P'));
    }

    public function testPerDayLimitAndOrdering(): void
    {
        $busy = [
            $this->busy('2026-11-09 09:00', '2026-11-09 09:30'),
            $this->busy('2026-11-09 11:00', '2026-11-09 11:30'),
            $this->busy('2026-11-09 13:00', '2026-11-09 13:30'),
        ];

        $slots = $this->finder()->find($busy, $this->at('2026-11-09 06:00'), 30, 1, 2);

        self::assertCount(2, $slots);
        self::assertSame('Mon 09.11. 08:00-08:30', $this->fmt($slots)[0]);
        self::assertLessThan($slots[1]->end, $slots[1]->start);
    }

    public function testSlotLengthIsExact(): void
    {
        foreach ($this->finder()->find([], $this->at('2026-11-09 06:00'), 90, 3) as $slot) {
            self::assertSame(90, $slot->minutes());
        }
    }

    public function testInvalidInputIsRejected(): void
    {
        foreach ([[2, 7], [60, 0], [9 * 60, 7]] as [$minutes, $days]) {
            try {
                $this->finder()->find([], $this->at('2026-11-09 06:00'), $minutes, $days);
                self::fail('Erwartet InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new WorkingHours([1], 17 * 60, 8 * 60);
    }
}
