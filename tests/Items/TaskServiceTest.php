<?php

declare(strict_types=1);

namespace Tests\Items;

use App\Items\ItemRepository;
use App\Items\TaskService;
use Tests\DbTestCase;

final class TaskServiceTest extends DbTestCase
{
    private TaskService $service;
    private int $clock;

    protected function setUp(): void
    {
        parent::setUp();
        // Donnerstag, 5.11.2026, 23:30 UTC = 6.11. 00:30 in Berlin: "heute" ist der 6.11.
        $this->clock = (int) strtotime('2026-11-05 23:30:00 UTC');
        $this->service = new TaskService(
            new ItemRepository($this->db),
            fn (string $key): bool => $this->db->fetchOne('SELECT `key` FROM contexts WHERE `key` = ?', [$key]) !== null,
            fn (): int => $this->clock,
        );
    }

    /** @return list<string> */
    private function titles(array $items): array
    {
        return array_map(static fn ($i) => $i->title, $items);
    }

    public function testGroupingUsesBerlinToday(): void
    {
        $this->service->createTask('überfällig', null, '2026-11-05', null);
        $this->service->createTask('heute', null, '2026-11-06', null);
        $this->service->createTask('bald', null, '2026-11-13', null);
        $this->service->createTask('später', null, '2026-11-14', null);
        $this->service->createTask('ohne', null, null, null);

        $groups = $this->service->grouped();

        self::assertSame(['überfällig'], $this->titles($groups['overdue']));
        self::assertSame(['heute'], $this->titles($groups['today']));
        self::assertSame(['bald'], $this->titles($groups['soon']));
        self::assertSame(['später'], $this->titles($groups['later']));
        self::assertSame(['ohne'], $this->titles($groups['undated']));
    }

    public function testContextFilter(): void
    {
        $this->service->createTask('dienst', 'dienstlich', '2026-11-06', null);
        $this->service->createTask('privat', 'privat', '2026-11-06', null);

        self::assertSame(['dienst'], $this->titles($this->service->grouped('dienstlich')['today']));
    }

    public function testCompleteAndReopenPlainTask(): void
    {
        $id = $this->service->createTask('einmalig', null, '2026-11-06', null);

        self::assertNull($this->service->complete($id));
        self::assertSame([], $this->service->grouped()['today']);
        self::assertSame(['einmalig'], $this->titles($this->service->recentlyDone()));

        $this->service->reopen($id);
        self::assertSame(['einmalig'], $this->titles($this->service->grouped()['today']));
    }

    public function testRecurringTaskMovesToNextDateInsteadOfBeingDone(): void
    {
        $id = $this->service->createTask('Wochenbericht', null, '2026-11-02', 'weekly:mon');

        // Überfällig seit Montag 2.11., heute Freitag 6.11. → nächster Montag 9.11.
        self::assertSame('2026-11-09', $this->service->complete($id));
        self::assertSame([], $this->service->recentlyDone());
        self::assertSame('2026-11-09', $this->db->fetchValue('SELECT due_date FROM items WHERE id = ?', [$id]));
    }

    public function testRecurringTaskCompletedEarlyKeepsItsRhythm(): void
    {
        $id = $this->service->createTask('Monatsmeldung', null, '2026-11-15', 'monthly:15');

        self::assertSame('2026-12-15', $this->service->complete($id), 'Vorzeitig erledigt: nächster Termin nach dem bisherigen');
    }

    public function testDueSummary(): void
    {
        $this->service->createTask('a', null, '2026-11-01', null);
        $this->service->createTask('b', null, '2026-11-06', null);
        $this->service->createTask('c', null, '2026-11-20', null);

        $summary = $this->service->dueSummary();

        self::assertSame(1, $summary['overdue']);
        self::assertSame(1, $summary['today']);
        self::assertSame(['a', 'b'], $this->titles($summary['items']));
    }

    public function testValidation(): void
    {
        $cases = [
            ['', null, null, null],
            ['x', 'gibtsnicht', null, null],
            ['x', null, '2026-02-31', null],
            ['x', null, '2026-11-06', 'yearly'],
            ['x', null, null, 'daily'],
            [str_repeat('a', 501), null, null, null],
        ];
        foreach ($cases as $args) {
            try {
                $this->service->createTask(...$args);
                self::fail('Erwartet InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM items'));
    }

    public function testNotesCreateUpdateAndSearch(): void
    {
        $a = $this->service->createNote('Einkaufsliste', "Milch\nBrot", 'privat');
        $this->service->createNote('Protokoll', 'Beschluss zur Dienstvereinbarung', 'betriebsrat');
        $this->service->createTask('Aufgabe Milch', null, null, null);

        self::assertSame(['Einkaufsliste'], $this->titles($this->service->notes('milch')));
        self::assertSame(['Protokoll'], $this->titles($this->service->notes('Dienstvereinbarung')));
        self::assertSame(['Protokoll'], $this->titles($this->service->notes('', 'betriebsrat')));
        self::assertCount(2, $this->service->notes());

        $this->service->updateNote($a, 'Einkauf', 'Käse', 'privat');
        self::assertSame(['Einkauf'], $this->titles($this->service->notes('käse')));
    }

    public function testSearchTreatsWildcardsLiterally(): void
    {
        $this->service->createNote('100% sicher', 'x', null);
        $this->service->createNote('Anderes', 'y', null);

        self::assertSame(['100% sicher'], $this->titles($this->service->notes('%')));
        self::assertSame([], $this->service->notes('_'));
    }

    public function testWrongTypeOperationsAreRejected(): void
    {
        $note = $this->service->createNote('N', '', null);
        $task = $this->service->createTask('T', null, null, null);

        foreach ([fn () => $this->service->complete($note), fn () => $this->service->updateNote($task, 'x', '', null), fn () => $this->service->delete(999)] as $call) {
            try {
                $call();
                self::fail('Erwartet InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDeleteDoesNotTouchInbox(): void
    {
        $this->db->execute("INSERT INTO items (type, origin, title, body, created_at, updated_at) VALUES ('inbox', 'text', 'roh', '', NOW(), NOW())");
        $id = $this->db->lastInsertId();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->delete($id);
    }
}
