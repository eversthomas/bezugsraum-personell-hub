<?php

declare(strict_types=1);

namespace Tests\Links;

use App\Links\QuickLinkService;
use Tests\DbTestCase;

final class QuickLinkServiceTest extends DbTestCase
{
    private QuickLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QuickLinkService(
            $this->db,
            fn (string $key): bool => $this->db->fetchOne('SELECT `key` FROM contexts WHERE `key` = ?', [$key]) !== null,
        );
    }

    /** @return list<string> */
    private function labels(): array
    {
        return array_map(static fn ($l) => $l->label, $this->service->all());
    }

    public function testAddListAndSymbol(): void
    {
        $this->service->add('Outlook AWO', 'https://outlook.office.com/mail/', 'dienstlich');
        $this->service->add('ärztliche Hilfe', 'https://example.test/', null);

        $links = $this->service->all();
        self::assertSame(['Outlook AWO', 'ärztliche Hilfe'], $this->labels());
        self::assertSame('O', $links[0]->symbol);
        self::assertSame('Ä', $links[1]->symbol, 'Anfangsbuchstabe, auch mit Umlaut');
        self::assertSame('dienstlich', $links[0]->context);
    }

    public function testOnlyHttpsAndNoCredentials(): void
    {
        $bad = ['http://example.test/', 'javascript:alert(1)', 'ftp://example.test', 'https://user:pw@example.test/', 'https:///x', 'kein url', "https://example.test/a b", 'data:text/html,x', ''];
        foreach ($bad as $url) {
            try {
                $this->service->add('X', $url, null);
                self::fail('Erwartet InvalidArgumentException für ' . $url);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->service->all());
    }

    public function testValidationOfLabelAndContext(): void
    {
        foreach ([['', 'https://example.test/', null], [str_repeat('a', 81), 'https://example.test/', null], ['X', 'https://example.test/', 'gibtsnicht']] as $args) {
            try {
                $this->service->add(...$args);
                self::fail('Erwartet InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMoveChangesOrder(): void
    {
        $a = $this->service->add('A', 'https://a.test/', null);
        $this->service->add('B', 'https://b.test/', null);
        $c = $this->service->add('C', 'https://c.test/', null);

        $this->service->move($c, 'up');
        self::assertSame(['A', 'C', 'B'], $this->labels());
        $this->service->move($a, 'up');
        self::assertSame(['A', 'C', 'B'], $this->labels(), 'Erster Eintrag bleibt oben');
        $this->service->move($a, 'down');
        self::assertSame(['C', 'A', 'B'], $this->labels());
    }

    public function testRemoveAndLimit(): void
    {
        $id = $this->service->add('A', 'https://a.test/', null);
        $this->service->remove($id);
        self::assertSame([], $this->service->all());

        for ($i = 0; $i < QuickLinkService::MAX_LINKS; ++$i) {
            $this->service->add('L' . $i, 'https://example.test/' . $i, null);
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->service->add('zu viel', 'https://example.test/x', null);
    }

    public function testRemoveUnknownFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->remove(999);
    }
}
