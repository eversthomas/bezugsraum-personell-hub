<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Core\ContextRepository;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Time;
use App\Core\View;
use App\Items\TaskService;

final class PlanningController
{
    public function __construct(
        private readonly PlanningService $planning,
        private readonly PlanningSettings $settings,
        private readonly TaskService $tasks,
        private readonly ContextRepository $contexts,
        private readonly View $view,
        private readonly Csrf $csrf,
        private readonly Session $session,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $item = null;
        if (($request->query['item'] ?? '') !== '') {
            $item = $this->tasks->find((int) $request->query['item']);
            if ($item === null) {
                return Response::notFound();
            }
        }
        $duration = (int) ($request->query['dauer'] ?? 60);
        if (!in_array($duration, PlanningService::DURATIONS, true)) {
            $duration = 60;
        }

        $slots = [];
        foreach ($this->planning->suggestions($duration, 7) as $slot) {
            $slots[] = [
                'start' => $slot->start->getTimestamp(),
                'end' => $slot->end->getTimestamp(),
                'day' => $slot->start->format('Y-m-d'),
                'label' => $slot->start->format('H:i') . '–' . $slot->end->format('H:i') . ' Uhr',
            ];
        }
        $byDay = [];
        foreach ($slots as $slot) {
            $byDay[$slot['day']][] = $slot;
        }

        return $this->page('planning/index', 'Zeit reservieren', 'aufgaben', [
            'item' => $item,
            'duration' => $duration,
            'durations' => PlanningService::DURATIONS,
            'byDay' => $byDay,
            'defaultTitle' => $this->settings->defaultTitle(),
            'target' => $this->settings->targetFor($item?->context),
            'targets' => ['outlook' => 'Outlook im Web', 'google' => 'Google Kalender', 'ics' => 'Nur .ics-Datei'],
            'itemId' => $item?->id,
        ]);
    }

    /** @param array<string, string> $params */
    public function reserve(Request $request, array $params = []): Response
    {
        $zone = new \DateTimeZone(Time::DISPLAY_ZONE);
        $start = (new \DateTimeImmutable('@' . (int) $request->input('start')))->setTimezone($zone);
        $end = (new \DateTimeImmutable('@' . (int) $request->input('end')))->setTimezone($zone);
        $itemId = (int) $request->input('item') ?: null;
        if ($itemId !== null && $this->tasks->find($itemId) === null) {
            $itemId = null;
        }
        try {
            $id = $this->planning->reserve($request->input('title'), $start, $end, $request->input('target'), $itemId);
        } catch (\InvalidArgumentException $e) {
            $this->session->set('flash', ['type' => 'error', 'message' => $e->getMessage()]);

            return Response::redirect('/reservieren' . ($itemId !== null ? '?item=' . $itemId : ''));
        }

        return Response::redirect('/reservieren/' . $id);
    }

    /** @param array<string, string> $params */
    public function done(Request $request, array $params): Response
    {
        $block = $this->planning->find((int) $params['id']);
        if ($block === null) {
            return Response::notFound();
        }
        $labels = ['outlook' => 'Outlook im Web', 'google' => 'Google Kalender', 'ics' => 'Datei'];

        return $this->page('planning/done', 'Termin vorbereitet', 'aufgaben', [
            'block' => $block,
            'when' => Time::berlin($block->startsAt->format('Y-m-d H:i:s'), 'd.m.Y, H:i') . '–' . Time::berlin($block->endsAt->format('Y-m-d H:i:s'), 'H:i') . ' Uhr',
            'link' => $this->planning->linkFor($block),
            'targetLabel' => $labels[$block->target] ?? $block->target,
        ]);
    }

    /** @param array<string, string> $params */
    public function ics(Request $request, array $params): Response
    {
        $block = $this->planning->find((int) $params['id']);
        if ($block === null) {
            return Response::notFound();
        }

        return new Response(200, $this->planning->icsFor($block), [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="termin.ics"',
        ]);
    }

    /** @param array<string, string> $params */
    public function discard(Request $request, array $params): Response
    {
        $this->planning->cancel((int) $params['id']);
        $this->session->set('flash', ['type' => 'ok', 'message' => 'Vormerkung verworfen.']);

        return Response::redirect('/aufgaben');
    }

    /** @param array<string, string> $params */
    public function settings(Request $request, array $params = []): Response
    {
        $hours = $this->settings->workingHours();

        return $this->page('planning/settings', 'Planung', 'planung', [
            'days' => $hours->days,
            'start' => sprintf('%02d:%02d', intdiv($hours->startMinute, 60), $hours->startMinute % 60),
            'end' => sprintf('%02d:%02d', intdiv($hours->endMinute, 60), $hours->endMinute % 60),
            'buffer' => $hours->bufferMinutes,
            'planTitle' => $this->settings->defaultTitle(),
            'targets' => $this->settings->targets(),
            'contexts' => $this->contexts->all(),
            'options' => ['outlook' => 'Outlook im Web', 'google' => 'Google Kalender', 'ics' => 'Nur .ics-Datei'],
        ]);
    }

    /** @param array<string, string> $params */
    public function saveSettings(Request $request, array $params = []): Response
    {
        $days = [];
        foreach ($request->post['days'] ?? [] as $day) {
            $days[] = (int) $day;
        }
        $targets = [];
        foreach ($this->contexts->all() as $context) {
            $value = $request->post['target'][$context['key']] ?? null;
            if (is_string($value)) {
                $targets[$context['key']] = $value;
            }
        }
        try {
            $this->settings->save(
                $days,
                $this->minutes($request->input('start')),
                $this->minutes($request->input('end')),
                (int) $request->input('buffer'),
                $request->input('title'),
                $targets,
            );
        } catch (\InvalidArgumentException $e) {
            $this->session->set('flash', ['type' => 'error', 'message' => $e->getMessage()]);

            return Response::redirect('/einstellungen/planung');
        }
        $this->session->set('flash', ['type' => 'ok', 'message' => 'Einstellungen gespeichert.']);

        return Response::redirect('/einstellungen/planung');
    }

    private function minutes(string $time): int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m) !== 1) {
            throw new \InvalidArgumentException('Bitte Uhrzeiten wie 08:00 angeben.');
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    /** @param array<string, mixed> $data */
    private function page(string $template, string $title, string $active, array $data): Response
    {
        $flash = $this->session->get('flash');
        $this->session->remove('flash');

        return Response::html($this->view->render($template, $data + [
            'title' => $title,
            'bodyClass' => 'app',
            'shell' => true,
            'active' => $active,
            'csrf' => $this->csrf->token(),
            'flash' => is_array($flash) ? $flash : null,
        ]));
    }
}
