<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Core\Contracts\EventLinkBuilderInterface;
use App\Core\PlannedEvent;

/**
 * Zeitfenster reservieren: schlägt freie Fenster über alle Kalender vor und bereitet den Termin im Zielkalender vor.
 * Personal Hub bleibt lesend: angelegt wird der Termin im Ursprungskonto, hier wird er nur vorgemerkt.
 */
final class PlanningService
{
    public const DURATIONS = [30, 45, 60, 90, 120, 180];
    public const MAX_DAYS_AHEAD = 90;
    public const TARGETS = ['outlook', 'google', 'ics'];

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param array<string, EventLinkBuilderInterface> $builders nach Schlüssel */
    public function __construct(
        private readonly PlanningSettings $settings,
        private readonly EventRepository $events,
        private readonly PlannedBlockRepository $blocks,
        private readonly array $builders,
        private readonly IcsEventBuilder $ics = new IcsEventBuilder(),
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return list<Slot> */
    public function suggestions(int $durationMinutes, int $days = 7): array
    {
        $now = new \DateTimeImmutable('@' . ($this->clock)());
        $until = $now->modify('+' . ($days + 1) . ' days');

        $busy = [];
        $utc = new \DateTimeZone('UTC');
        foreach ($this->events->between($now->modify('-1 day'), $until) as $event) {
            if ($event['all_day']) {
                continue; // ganztägige Einträge (Geburtstage, Hinweise) sperren nicht den ganzen Tag
            }
            $busy[] = [
                'start' => new \DateTimeImmutable($event['starts_at'], $utc),
                'end' => new \DateTimeImmutable($event['ends_at'], $utc),
            ];
        }
        foreach ($this->blocks->pendingBetween($now->modify('-1 day'), $until) as $block) {
            $busy[] = ['start' => $block->startsAt, 'end' => $block->endsAt];
        }

        return (new SlotFinder($this->settings->workingHours()))->find($busy, $now, $durationMinutes, $days);
    }

    /**
     * Merkt einen Zeitblock vor.
     *
     * @throws \InvalidArgumentException
     */
    public function reserve(string $title, \DateTimeImmutable $start, \DateTimeImmutable $end, string $target, ?int $itemId = null): int
    {
        $title = trim(preg_replace('/[\x00-\x1f\x7f]/', '', $title) ?? '');
        if ($title === '' || mb_strlen($title) > 200) {
            throw new \InvalidArgumentException('Bitte einen Titel (höchstens 200 Zeichen) angeben.');
        }
        if (!in_array($target, self::TARGETS, true)) {
            throw new \InvalidArgumentException('Unbekannter Zielkalender.');
        }
        $now = ($this->clock)();
        $minutes = ($end->getTimestamp() - $start->getTimestamp()) / 60;
        if ($minutes < 5 || $minutes > 8 * 60) {
            throw new \InvalidArgumentException('Die Dauer muss zwischen 5 Minuten und 8 Stunden liegen.');
        }
        if ($start->getTimestamp() < $now - 300) {
            throw new \InvalidArgumentException('Der Zeitpunkt liegt in der Vergangenheit.');
        }
        if ($start->getTimestamp() > $now + self::MAX_DAYS_AHEAD * 86400) {
            throw new \InvalidArgumentException('Der Zeitpunkt liegt zu weit in der Zukunft.');
        }

        return $this->blocks->create($itemId, $title, $start, $end, $target, gmdate('Y-m-d H:i:s', $now));
    }

    public function find(int $id): ?PlannedBlock
    {
        return $this->blocks->find($id);
    }

    public function cancel(int $id): void
    {
        $this->blocks->delete($id);
    }

    /** Link in den Zielkalender oder null, wenn nur der .ics-Weg möglich ist. */
    public function linkFor(PlannedBlock $block): ?string
    {
        $builder = $this->builders[$block->target] ?? null;

        return $builder?->url($this->toEvent($block));
    }

    public function icsFor(PlannedBlock $block): string
    {
        return $this->ics->build($this->toEvent($block), 'block-' . $block->id . '-' . substr(sha1((string) $block->startsAt->getTimestamp()), 0, 8));
    }

    /** @return list<PlannedBlock> noch nicht bestätigte Blöcke im Zeitraum (für den Leitstand) */
    public function pendingBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->blocks->pendingBetween($from, $to);
    }

    /**
     * Gleicht vorgemerkte Blöcke mit den abgerufenen Terminen ab: ein Termin zur selben Zeit bestätigt den Block;
     * Blöcke, die länger als einen Tag zurückliegen und nie auftauchten, verfallen.
     *
     * @return int Anzahl neu bestätigter Blöcke
     */
    public function reconcile(): int
    {
        $now = ($this->clock)();
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $confirmed = 0;
        foreach ($this->blocks->allPending() as $block) {
            if ($this->events->existsExactly($block->startsAt, $block->endsAt)) {
                $this->blocks->setStatus($block->id, 'confirmed', $stamp);
                ++$confirmed;
            } elseif ($block->endsAt->getTimestamp() < $now - 86400) {
                $this->blocks->setStatus($block->id, 'expired', $stamp);
            }
        }

        return $confirmed;
    }

    private function toEvent(PlannedBlock $block): PlannedEvent
    {
        $zone = new \DateTimeZone('Europe/Berlin');

        return new PlannedEvent($block->title, $block->startsAt->setTimezone($zone), $block->endsAt->setTimezone($zone));
    }
}
