<?php

declare(strict_types=1);

namespace App\Items;

/**
 * Erfassen vor Sortieren: neue Einträge landen in der Inbox und werden später zur Aufgabe oder Notiz.
 */
final class ItemService
{
    public const MAX_TEXT = 10000;
    public const MAX_TITLE = 200;
    public const ORIGINS = ['text', 'voice', 'photo', 'link', 'share'];

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        private readonly ItemRepository $items,
        private readonly \Closure $contextExists,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Legt einen Eintrag in der Inbox an. Mit gleicher client_uuid wird nichts doppelt gespeichert
     * (Offline-Warteschlange kann einen Eintrag mehrfach senden).
     *
     * @return array{item: Item, duplicate: bool}
     * @throws \InvalidArgumentException bei ungültigen Eingaben
     */
    public function capture(string $text, string $origin = 'text', ?string $clientUuid = null): array
    {
        $text = $this->clean($text);
        if ($text === '') {
            throw new \InvalidArgumentException('Bitte einen Text eingeben.');
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            throw new \InvalidArgumentException('Der Text ist zu lang.');
        }
        if (!in_array($origin, self::ORIGINS, true)) {
            $origin = 'text';
        }
        if ($clientUuid !== null && $clientUuid !== '') {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $clientUuid) !== 1) {
                throw new \InvalidArgumentException('Ungültige Kennung.');
            }
            $clientUuid = strtolower($clientUuid);
            $existing = $this->items->findByClientUuid($clientUuid);
            if ($existing !== null) {
                return ['item' => $existing, 'duplicate' => true];
            }
        } else {
            $clientUuid = null;
        }

        [$title, $body] = $this->split($text);
        try {
            $id = $this->items->insert($origin, $title, $body, $clientUuid, $this->time());
        } catch (\PDOException $e) {
            // Zwei gleichzeitige Anfragen mit derselben Kennung: die zweite trifft den Unique-Schlüssel.
            $existing = $clientUuid === null ? null : $this->items->findByClientUuid($clientUuid);
            if ($existing !== null) {
                return ['item' => $existing, 'duplicate' => true];
            }
            throw $e;
        }

        return ['item' => $this->require($id), 'duplicate' => false];
    }

    /** @return list<Item> */
    public function inbox(): array
    {
        return $this->items->byType('inbox');
    }

    public function inboxCount(): int
    {
        return $this->items->countByType('inbox');
    }

    /**
     * Sortiert einen Eintrag als Aufgabe oder Notiz ein.
     *
     * @throws \InvalidArgumentException bei ungültigen Eingaben
     */
    public function sort(int $id, string $type, ?string $context = null, ?string $dueDate = null): void
    {
        if (!in_array($type, ['task', 'note'], true)) {
            throw new \InvalidArgumentException('Bitte Aufgabe oder Notiz wählen.');
        }
        $context = $context === '' ? null : $context;
        if ($context !== null && !($this->contextExists)($context)) {
            throw new \InvalidArgumentException('Unbekannter Kontext.');
        }
        $dueDate = $dueDate === '' ? null : $dueDate;
        if ($dueDate !== null) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
            if ($date === false || $date->format('Y-m-d') !== $dueDate) {
                throw new \InvalidArgumentException('Ungültiges Datum.');
            }
        }
        if ($type === 'note') {
            $dueDate = null; // Notizen haben keine Fälligkeit
        }

        $this->require($id);
        $this->items->sort($id, $type, $context, $dueDate, $this->time());
    }

    public function delete(int $id): void
    {
        $this->require($id);
        $this->items->delete($id);
    }

    private function require(int $id): Item
    {
        return $this->items->find($id) ?? throw new \InvalidArgumentException('Eintrag nicht gefunden.');
    }

    private function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text) ?? '';

        return trim($text);
    }

    /** @return array{string, string} Titel (erste Zeile) und Rest */
    private function split(string $text): array
    {
        $parts = explode("\n", $text, 2);
        $first = trim($parts[0]);
        $rest = trim($parts[1] ?? '');
        if (mb_strlen($first) > self::MAX_TITLE) {
            $cut = mb_substr($first, 0, self::MAX_TITLE - 1);

            return [rtrim($cut) . '…', trim(mb_substr($first, mb_strlen($cut)) . ($rest === '' ? '' : "\n" . $rest))];
        }

        return [$first, $rest];
    }

    private function time(): string
    {
        return gmdate('Y-m-d H:i:s', ($this->clock)());
    }
}
