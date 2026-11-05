<?php

declare(strict_types=1);

namespace Tests\Items;

use App\Items\ItemRepository;
use App\Items\ItemService;
use Tests\DbTestCase;

final class ItemServiceTest extends DbTestCase
{
    private const UUID = '3f2b8c1e-7a4d-4e0b-9c6a-1d2e3f4a5b6c';

    private ItemService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ItemService(
            new ItemRepository($this->db),
            fn (string $key): bool => $this->db->fetchOne('SELECT `key` FROM contexts WHERE `key` = ?', [$key]) !== null,
            fn (): int => (int) strtotime('2026-11-05 08:00:00 UTC'),
        );
    }

    public function testCaptureCreatesInboxItemWithTitleAndBody(): void
    {
        $result = $this->service->capture("Steuerunterlagen holen\nBeim Amt nachfragen\nund Frist prüfen");

        self::assertFalse($result['duplicate']);
        self::assertSame('inbox', $result['item']->type);
        self::assertSame('Steuerunterlagen holen', $result['item']->title);
        self::assertSame("Beim Amt nachfragen\nund Frist prüfen", $result['item']->body);
        self::assertSame('text', $result['item']->origin);
        self::assertSame('2026-11-05 08:00:00', $result['item']->createdAt);
    }

    public function testSameClientUuidIsStoredOnlyOnce(): void
    {
        $first = $this->service->capture('Funkloch-Notiz', 'text', self::UUID);
        $second = $this->service->capture('Funkloch-Notiz', 'text', strtoupper(self::UUID));

        self::assertFalse($first['duplicate']);
        self::assertTrue($second['duplicate']);
        self::assertSame($first['item']->id, $second['item']->id);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM items'));
    }

    public function testRejectsEmptyTooLongAndBadUuid(): void
    {
        foreach ([['   '], ["\n\n"], [str_repeat('a', ItemService::MAX_TEXT + 1)], ['ok', 'text', 'kein-uuid']] as $args) {
            try {
                $this->service->capture(...$args);
                self::fail('Erwartet InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM items'));
    }

    public function testUnknownOriginFallsBackToText(): void
    {
        self::assertSame('text', $this->service->capture('x', 'hacker')['item']->origin);
        self::assertSame('voice', $this->service->capture('y', 'voice')['item']->origin);
    }

    public function testLongFirstLineIsShortenedWithoutLosingText(): void
    {
        $long = str_repeat('wort ', 80);
        $item = $this->service->capture($long)['item'];

        self::assertLessThanOrEqual(ItemService::MAX_TITLE, mb_strlen($item->title));
        self::assertStringEndsWith('…', $item->title);
        self::assertNotSame('', $item->body);
    }

    public function testControlCharactersAreRemoved(): void
    {
        $item = $this->service->capture("Ti\x00tel\x07\nText")['item'];

        self::assertSame('Titel', $item->title);
    }

    public function testSortTurnsInboxItemIntoTaskOrNote(): void
    {
        $id = $this->service->capture('Aufgabe A')['item']->id;
        $this->service->sort($id, 'task', 'dienstlich', '2026-11-10');
        $row = $this->db->fetchOne('SELECT type, context, due_date FROM items WHERE id = ?', [$id]);
        self::assertSame(['task', 'dienstlich', '2026-11-10'], [$row['type'], $row['context'], $row['due_date']]);
        self::assertSame(0, $this->service->inboxCount());

        $note = $this->service->capture('Notiz B')['item']->id;
        $this->service->sort($note, 'note', 'privat', '2026-11-10');
        self::assertNull($this->db->fetchValue('SELECT due_date FROM items WHERE id = ?', [$note]), 'Notizen haben keine Fälligkeit');
    }

    public function testSortValidation(): void
    {
        $id = $this->service->capture('X')['item']->id;
        foreach ([['inbox'], ['task', 'gibtsnicht'], ['task', null, '31.12.2026'], ['task', null, '2026-02-31']] as $args) {
            try {
                $this->service->sort($id, ...$args);
                self::fail('Erwartet InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame('inbox', $this->db->fetchValue('SELECT type FROM items WHERE id = ?', [$id]));
    }

    public function testDeleteAndUnknownId(): void
    {
        $id = $this->service->capture('Weg damit')['item']->id;
        $this->service->delete($id);
        self::assertSame([], $this->service->inbox());

        $this->expectException(\InvalidArgumentException::class);
        $this->service->delete($id);
    }

    public function testInboxIsNewestFirst(): void
    {
        $this->service->capture('eins');
        $this->service->capture('zwei');

        self::assertSame(['zwei', 'eins'], array_map(static fn ($i) => $i->title, $this->service->inbox()));
    }
}
