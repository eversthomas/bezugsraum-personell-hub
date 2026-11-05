<?php

declare(strict_types=1);

namespace App\Items;

use App\Core\Time;

/**
 * Aufgaben und Notizen. Zeiten in UTC gespeichert, "heute" gilt in Europe/Berlin.
 */
final class TaskService
{
    public const MAX_TITLE = 500;
    public const MAX_BODY = 20000;

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        private readonly ItemRepository $items,
        private readonly \Closure $contextExists,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    // ---------- Aufgaben ----------

    /** @throws \InvalidArgumentException */
    public function createTask(string $title, ?string $context, ?string $due, ?string $recurrence): int
    {
        [$title, $context, $due, $recurrence] = $this->validate($title, $context, $due, $recurrence, true);

        return $this->items->create('task', $title, '', $context, $due, $recurrence, $this->utcNow());
    }

    /** @throws \InvalidArgumentException */
    public function updateTask(int $id, string $title, string $body, ?string $context, ?string $due, ?string $recurrence): void
    {
        $this->requireOfType($id, 'task');
        [$title, $context, $due, $recurrence] = $this->validate($title, $context, $due, $recurrence, true);
        $this->items->update($id, $title, $this->body($body), $context, $due, $recurrence, $this->utcNow());
    }

    /**
     * Erledigt eine Aufgabe. Wiederkehrende Aufgaben rücken stattdessen auf den nächsten Termin.
     *
     * @return string|null das neue Fälligkeitsdatum bei Wiederholung, sonst null
     */
    public function complete(int $id): ?string
    {
        $task = $this->requireOfType($id, 'task');
        $recurrence = Recurrence::parse($task->recurrence);
        if ($recurrence === null) {
            $this->items->markDone($id, $this->utcNow());

            return null;
        }
        $today = new \DateTimeImmutable($this->today());
        $base = $task->dueDate !== null && $task->dueDate > $today->format('Y-m-d') ? new \DateTimeImmutable($task->dueDate) : $today;
        $next = $recurrence->next($base)->format('Y-m-d');
        $this->items->setDue($id, $next, $this->utcNow());

        return $next;
    }

    public function reopen(int $id): void
    {
        $this->requireOfType($id, 'task');
        $this->items->reopen($id, $this->utcNow());
    }

    /**
     * Offene Aufgaben nach Fälligkeit gruppiert.
     *
     * @return array{overdue: list<Item>, today: list<Item>, soon: list<Item>, later: list<Item>, undated: list<Item>}
     */
    public function grouped(?string $context = null): array
    {
        $today = $this->today();
        $soonLimit = (new \DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d');
        $groups = ['overdue' => [], 'today' => [], 'soon' => [], 'later' => [], 'undated' => []];
        foreach ($this->items->openTasks($context) as $task) {
            $group = match (true) {
                $task->dueDate === null => 'undated',
                $task->dueDate < $today => 'overdue',
                $task->dueDate === $today => 'today',
                $task->dueDate <= $soonLimit => 'soon',
                default => 'later',
            };
            $groups[$group][] = $task;
        }

        return $groups;
    }

    /** @return list<Item> */
    public function recentlyDone(): array
    {
        return $this->items->doneTasks();
    }

    /** Überfällige und heutige Aufgaben für die Karte im Leitstand. */
    /** @return array{overdue: int, today: int, items: list<Item>} */
    public function dueSummary(int $limit = 6): array
    {
        $groups = $this->grouped();
        $items = [...$groups['overdue'], ...$groups['today']];

        return ['overdue' => count($groups['overdue']), 'today' => count($groups['today']), 'items' => array_slice($items, 0, $limit)];
    }

    /**
     * Erinnerungen für fällige Aufgaben: um 08:00 Uhr (Berlin) am Fälligkeitstag, jede genau einmal.
     * Ältere Erinnerungen (mehr als 2 Tage zurück, etwa nach einem langen Ausfall) werden stillschweigend übersprungen.
     *
     * @return list<array{item: Item, remindAt: string}>
     */
    public function claimDueReminders(): array
    {
        $zone = new \DateTimeZone(Time::DISPLAY_ZONE);
        $utc = new \DateTimeZone('UTC');
        $nowTs = ($this->clock)();
        $today = $this->today();
        $claims = [];
        foreach ($this->items->tasksDueUntil($today) as $task) {
            $remindAt = (new \DateTimeImmutable((string) $task->dueDate . ' 08:00:00', $zone))->setTimezone($utc);
            if ($remindAt->getTimestamp() > $nowTs) {
                continue; // heute noch nicht 08:00 Uhr
            }
            $stale = $remindAt->getTimestamp() < $nowTs - 2 * 86400;
            $key = $remindAt->format('Y-m-d H:i:s');
            if ($this->items->claimReminder($task->id, $key, $this->utcNow(), $stale)) {
                $claims[] = ['item' => $task, 'remindAt' => $key];
            }
        }
        $this->items->purgeReminders(gmdate('Y-m-d H:i:s', $nowTs - 30 * 86400));

        return $claims;
    }

    public function releaseReminder(int $itemId, string $remindAt): void
    {
        $this->items->releaseReminder($itemId, $remindAt);
    }

    // ---------- Notizen ----------

    /** @throws \InvalidArgumentException */
    public function createNote(string $title, string $body, ?string $context): int
    {
        [$title, $context] = $this->validate($title, $context, null, null, false);

        return $this->items->create('note', $title, $this->body($body), $context, null, null, $this->utcNow());
    }

    /** @throws \InvalidArgumentException */
    public function updateNote(int $id, string $title, string $body, ?string $context): void
    {
        $this->requireOfType($id, 'note');
        [$title, $context] = $this->validate($title, $context, null, null, false);
        $this->items->update($id, $title, $this->body($body), $context, null, null, $this->utcNow());
    }

    /** @return list<Item> */
    public function notes(string $query = '', ?string $context = null): array
    {
        return $this->items->searchNotes(trim($query), $context);
    }

    // ---------- gemeinsam ----------

    public function find(int $id): ?Item
    {
        $item = $this->items->find($id);

        return $item !== null && in_array($item->type, ['task', 'note'], true) ? $item : null;
    }

    public function delete(int $id): void
    {
        $item = $this->find($id) ?? throw new \InvalidArgumentException('Eintrag nicht gefunden.');
        $this->items->delete($item->id);
    }

    /** @return list<array<string, mixed>> */
    public function export(): array
    {
        return $this->items->exportAll();
    }

    /**
     * @return array{string, ?string, ?string, ?string}
     * @throws \InvalidArgumentException
     */
    private function validate(string $title, ?string $context, ?string $due, ?string $recurrence, bool $task): array
    {
        $title = trim(preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $title) ?? '');
        if ($title === '') {
            throw new \InvalidArgumentException('Bitte einen Titel eingeben.');
        }
        if (mb_strlen($title) > self::MAX_TITLE) {
            throw new \InvalidArgumentException('Der Titel ist zu lang.');
        }
        $context = $context === '' ? null : $context;
        if ($context !== null && !($this->contextExists)($context)) {
            throw new \InvalidArgumentException('Unbekannter Kontext.');
        }
        $due = $due === '' ? null : $due;
        if ($due !== null) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $due);
            if ($date === false || $date->format('Y-m-d') !== $due) {
                throw new \InvalidArgumentException('Ungültiges Datum.');
            }
        }
        $recurrence = $recurrence === '' ? null : $recurrence;
        if ($recurrence !== null && Recurrence::parse($recurrence) === null) {
            throw new \InvalidArgumentException('Ungültige Wiederholung.');
        }
        if ($task && $recurrence !== null && $due === null) {
            throw new \InvalidArgumentException('Wiederkehrende Aufgaben brauchen ein Fälligkeitsdatum.');
        }

        return [$title, $context, $due, $recurrence];
    }

    private function body(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $body) ?? '';
        if (mb_strlen($body) > self::MAX_BODY) {
            throw new \InvalidArgumentException('Der Text ist zu lang.');
        }

        return trim($body);
    }

    private function requireOfType(int $id, string $type): Item
    {
        $item = $this->items->find($id);
        if ($item === null || $item->type !== $type) {
            throw new \InvalidArgumentException('Eintrag nicht gefunden.');
        }

        return $item;
    }

    private function today(): string
    {
        return Time::berlin(gmdate('Y-m-d H:i:s', ($this->clock)()), 'Y-m-d');
    }

    private function utcNow(): string
    {
        return gmdate('Y-m-d H:i:s', ($this->clock)());
    }
}
