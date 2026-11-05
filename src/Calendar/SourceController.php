<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Time;
use App\Core\View;

final class SourceController
{
    private const BASE = '/einstellungen/quellen';

    public function __construct(
        private readonly CalendarService $service,
        private readonly SourceRepository $sources,
        private readonly View $view,
        private readonly Csrf $csrf,
        private readonly Session $session,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $flash = $this->session->get('flash');
        $this->session->remove('flash');

        $rows = [];
        foreach ($this->service->sources() as $source) {
            $rows[] = [
                'id' => $source->id,
                'name' => $source->name,
                'context' => $source->context,
                'class' => Palette::classFor($source->color),
                'color' => $source->color,
                'hint' => $source->urlHint,
                'interval' => $source->intervalMin,
                'active' => $source->active,
                'error' => $source->lastError,
                'lastOk' => $source->lastOkAt === null ? 'noch nie' : Time::berlin($source->lastOkAt),
                'status' => $this->status($source),
            ];
        }

        $html = $this->view->render('sources/index', [
            'title' => 'Kalenderquellen',
            'bodyClass' => 'app',
            'csrf' => $this->csrf->token(),
            'rows' => $rows,
            'contexts' => $this->sources->contexts(),
            'palette' => Palette::COLORS,
            'flash' => is_array($flash) ? $flash : null,
        ]);

        return Response::html($html);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params = []): Response
    {
        try {
            $id = $this->service->addSource(
                $request->input('name'),
                $request->input('context'),
                $this->colorFrom($request),
                $request->input('url'),
                (int) $request->input('interval', '15'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->back('error', $e->getMessage());
        }
        $ok = $this->service->refresh($id);

        return $this->back($ok ? 'ok' : 'error', $ok ? 'Quelle gespeichert und abgerufen.' : 'Quelle gespeichert, aber der erste Abruf ist fehlgeschlagen. Details stehen an der Quelle.');
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        return $this->guarded(fn () => $this->service->updateSource(
            (int) $params['id'],
            $request->input('name'),
            $request->input('context'),
            $this->colorFrom($request),
            (int) $request->input('interval', '15'),
        ), 'Änderungen gespeichert.');
    }

    /** @param array<string, string> $params */
    public function pause(Request $request, array $params): Response
    {
        return $this->guarded(fn () => $this->service->pause((int) $params['id']), 'Quelle pausiert. Ihre Termine werden ausgeblendet.');
    }

    /** @param array<string, string> $params */
    public function resume(Request $request, array $params): Response
    {
        return $this->guarded(function () use ($params): void {
            $this->service->resume((int) $params['id']);
            $this->service->refresh((int) $params['id']);
        }, 'Quelle fortgesetzt.');
    }

    /** @param array<string, string> $params */
    public function remove(Request $request, array $params): Response
    {
        return $this->guarded(fn () => $this->service->remove((int) $params['id']), 'Quelle entfernt.');
    }

    /** @param array<string, string> $params */
    public function replaceUrl(Request $request, array $params): Response
    {
        return $this->guarded(function () use ($request, $params): void {
            $this->service->replaceUrl((int) $params['id'], $request->input('url'));
            $this->service->refresh((int) $params['id']);
        }, 'Link ersetzt und neu abgerufen.');
    }

    /** @param array<string, string> $params */
    public function refresh(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        try {
            $ok = $this->service->refresh($id);
        } catch (\InvalidArgumentException $e) {
            return $this->back('error', $e->getMessage());
        }

        return $this->back($ok ? 'ok' : 'error', $ok ? 'Abruf erfolgreich.' : 'Abruf fehlgeschlagen. Details stehen an der Quelle.');
    }

    /** @param array<string, string> $params */
    public function test(Request $request, array $params = []): Response
    {
        $result = $this->service->test($request->input('url'));

        return Response::json(['ok' => $result->ok, 'eventCount' => $result->eventCount, 'error' => $result->error]);
    }

    private function colorFrom(Request $request): string
    {
        $key = $request->input('color', 'blue');

        return Palette::COLORS[$key]['hex'] ?? Palette::COLORS['blue']['hex'];
    }

    private function status(Source $source): string
    {
        if (!$source->active) {
            return 'paused';
        }
        if ($source->lastError !== null) {
            return 'error';
        }
        if ($source->lastOkAt === null) {
            return 'new';
        }

        return 'ok';
    }

    private function guarded(callable $action, string $success): Response
    {
        try {
            $action();
        } catch (\InvalidArgumentException $e) {
            return $this->back('error', $e->getMessage());
        }

        return $this->back('ok', $success);
    }

    private function back(string $type, string $message): Response
    {
        $this->session->set('flash', ['type' => $type, 'message' => $message]);

        return Response::redirect(self::BASE);
    }
}
