<?php

declare(strict_types=1);

namespace Tests\Items;

use App\Items\Recurrence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecurrenceTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function cases(): array
    {
        return [
            'täglich' => ['daily', '2026-11-05', '2026-11-06'],
            'täglich über Jahreswechsel' => ['daily', '2026-12-31', '2027-01-01'],
            'wöchentlich Montag von Donnerstag' => ['weekly:mon', '2026-11-05', '2026-11-09'],
            'wöchentlich Montag von Montag (streng danach)' => ['weekly:mon', '2026-11-09', '2026-11-16'],
            'wöchentlich Sonntag' => ['weekly:sun', '2026-11-05', '2026-11-08'],
            'monatlich am 15. vor dem 15.' => ['monthly:15', '2026-11-05', '2026-11-15'],
            'monatlich am 15. am 15.' => ['monthly:15', '2026-11-15', '2026-12-15'],
            'monatlich am 15. nach dem 15.' => ['monthly:15', '2026-11-20', '2026-12-15'],
            'monatlich am 31. in kurzem Monat' => ['monthly:31', '2026-02-01', '2026-02-28'],
            'monatlich am 31. nach Februar' => ['monthly:31', '2026-02-28', '2026-03-31'],
            'monatlich am 30. im Schaltjahr' => ['monthly:30', '2028-01-31', '2028-02-29'],
            'monatlich über Jahreswechsel' => ['monthly:10', '2026-12-20', '2027-01-10'],
        ];
    }

    #[DataProvider('cases')]
    public function testNext(string $pattern, string $after, string $expected): void
    {
        $recurrence = Recurrence::parse($pattern);

        self::assertNotNull($recurrence);
        self::assertSame($expected, $recurrence->next(new \DateTimeImmutable($after))->format('Y-m-d'));
    }

    public function testInvalidPatternsAreRejected(): void
    {
        foreach (['', 'weekly', 'weekly:xyz', 'monthly:0', 'monthly:32', 'yearly', 'daily:1', null] as $bad) {
            self::assertNull(Recurrence::parse($bad), (string) $bad);
        }
    }

    public function testLabel(): void
    {
        self::assertSame('wöchentlich, Montag', Recurrence::parse('weekly:mon')?->label());
        self::assertSame('monatlich, am 15.', Recurrence::parse('monthly:15')?->label());
        self::assertSame('täglich', Recurrence::parse('daily')?->label());
    }
}
