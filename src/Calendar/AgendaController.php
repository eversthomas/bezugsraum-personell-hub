<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Core\Request;
use App\Core\Response;
use App\Core\Time;

/**
 * JSON für den Leitstand: Termine eines Zeitfensters und der Zustand der Quellen.
 */
final class AgendaController
{
    public const MAX_DAYS = 62;

    public function __construct(
        private readonly SourceRepository $sources,
        private readonly EventRepository $events,
        private readonly \Closure $clock,
    ) {
    }

    /** @param array<string, string> $params */
    public function events(Request $request, array $params = []): Response
    {
        $zone = new \DateTimeZone(Time::DISPLAY_ZONE);
        $from = $this->date($request->query['from'] ?? '', $zone);
        $to = $this->date($request->query['to'] ?? '', $zone);
        if ($from === null || $to === null || $to <= $from) {
            return Response::json(['error' => 'Ungültiger Zeitraum'], 422);
        }
        if ($from->diff($to)->days > self::MAX_DAYS) {
            return Response::json(['error' => 'Zeitraum zu groß'], 422);
        }

        $utc = new \DateTimeZone('UTC');
        $rows = $this->events->between($from->setTimezone($utc), $to->setTimezone($utc));

        $events = [];
        foreach ($rows as $row) {
            $allDay = $row['all_day'];
            $events[] = [
                'source' => $row['source_id'],
                'title' => $row['title'],
                'location' => $row['location'],
                'allDay' => $allDay,
                'start' => $allDay ? substr($row['starts_at'], 0, 10) : $this->iso($row['starts_at']),
                'end' => $allDay ? substr($row['ends_at'], 0, 10) : $this->iso($row['ends_at']),
            ];
        }

        $sources = [];
        foreach ($this->sources->all() as $source) {
            $sources[] = [
                'id' => $source->id,
                'name' => $source->name,
                'context' => $source->context,
                'class' => Palette::classFor($source->color),
                'active' => $source->active,
                'error' => $source->active ? $source->lastError : null,
                'lastOk' => $source->lastOkAt === null ? null : $this->iso($source->lastOkAt),
            ];
        }

        return Response::json([
            'now' => gmdate('Y-m-d\TH:i:s\Z', ($this->clock)()),
            'sources' => $sources,
            'events' => $events,
        ]);
    }

    private function date(string $value, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function iso(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
