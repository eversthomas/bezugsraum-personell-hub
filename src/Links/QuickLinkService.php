<?php

declare(strict_types=1);

namespace App\Links;

use App\Core\Db;

/**
 * Pflegt die Linkliste. Nur https-Adressen; das Symbol ist der Anfangsbuchstabe (keine externen Favicons).
 */
final class QuickLinkService
{
    public const MAX_LINKS = 30;

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        private readonly Db $db,
        private readonly \Closure $contextExists,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return list<QuickLink> */
    public function all(): array
    {
        $links = [];
        foreach ($this->db->fetchAll('SELECT l.id, l.label, l.url, l.context, c.color FROM quick_links l LEFT JOIN contexts c ON c.`key` = l.context ORDER BY l.sort, l.id') as $row) {
            $label = (string) $row['label'];
            $links[] = new QuickLink(
                (int) $row['id'],
                $label,
                (string) $row['url'],
                $row['context'] === null ? null : (string) $row['context'],
                mb_strtoupper(mb_substr($label, 0, 1)),
            );
        }

        return $links;
    }

    /** @return array<string, string> Kontext-Schlüssel => Farbwert für das Symbol */
    public function contextColors(): array
    {
        $colors = [];
        foreach ($this->db->fetchAll('SELECT `key`, color FROM contexts') as $row) {
            $colors[(string) $row['key']] = (string) $row['color'];
        }

        return $colors;
    }

    /** @throws \InvalidArgumentException */
    public function add(string $label, string $url, ?string $context): int
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 80) {
            throw new \InvalidArgumentException('Bitte eine Bezeichnung (höchstens 80 Zeichen) angeben.');
        }
        $url = $this->validateUrl($url);
        $context = $context === '' ? null : $context;
        if ($context !== null && !($this->contextExists)($context)) {
            throw new \InvalidArgumentException('Unbekannter Kontext.');
        }
        if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM quick_links') >= self::MAX_LINKS) {
            throw new \InvalidArgumentException('Es sind höchstens ' . self::MAX_LINKS . ' Links möglich.');
        }
        $sort = (int) $this->db->fetchValue('SELECT COALESCE(MAX(sort), 0) + 10 FROM quick_links');
        $this->db->execute(
            'INSERT INTO quick_links (label, url, context, sort, created_at) VALUES (?, ?, ?, ?, ?)',
            [$label, $url, $context, $sort, gmdate('Y-m-d H:i:s', ($this->clock)())],
        );

        return $this->db->lastInsertId();
    }

    public function remove(int $id): void
    {
        if ($this->db->execute('DELETE FROM quick_links WHERE id = ?', [$id]) !== 1) {
            throw new \InvalidArgumentException('Link nicht gefunden.');
        }
    }

    /** Tauscht die Reihenfolge mit dem Nachbarn ("up" oder "down"). */
    public function move(int $id, string $direction): void
    {
        $links = $this->all();
        $index = null;
        foreach ($links as $i => $link) {
            if ($link->id === $id) {
                $index = $i;
            }
        }
        if ($index === null) {
            throw new \InvalidArgumentException('Link nicht gefunden.');
        }
        $other = $direction === 'up' ? $index - 1 : $index + 1;
        if (!isset($links[$other])) {
            return;
        }
        // Reihenfolge neu durchnummerieren, dann tauschen: robust auch bei gleichen Sortierwerten.
        $order = array_map(static fn (QuickLink $l): int => $l->id, $links);
        [$order[$index], $order[$other]] = [$order[$other], $order[$index]];
        $this->db->transaction(function () use ($order): void {
            foreach ($order as $position => $linkId) {
                $this->db->execute('UPDATE quick_links SET sort = ? WHERE id = ?', [($position + 1) * 10, $linkId]);
            }
        });
    }

    /** @throws \InvalidArgumentException */
    private function validateUrl(string $url): string
    {
        $url = trim($url);
        if (mb_strlen($url) > 2000 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new \InvalidArgumentException('Ungültige Adresse.');
        }
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            throw new \InvalidArgumentException('Nur https-Adressen sind erlaubt.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Adressen mit Zugangsdaten sind nicht erlaubt.');
        }

        return $url;
    }
}
