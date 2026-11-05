<?php

declare(strict_types=1);

namespace App\Links;

use App\Calendar\Palette;
use App\Core\ContextRepository;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

final class LinkController
{
    private const BASE = '/einstellungen/links';

    public function __construct(
        private readonly QuickLinkService $service,
        private readonly ContextRepository $contexts,
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

        return Response::html($this->view->render('links/index', [
            'title' => 'Schnellzugriff',
            'bodyClass' => 'app',
            'shell' => true,
            'active' => 'links',
            'csrf' => $this->csrf->token(),
            'links' => $this->service->all(),
            'contexts' => $this->contexts->all(),
            'flash' => is_array($flash) ? $flash : null,
        ]));
    }

    /** @param array<string, string> $params */
    public function add(Request $request, array $params = []): Response
    {
        return $this->guarded(fn () => $this->service->add($request->input('label'), $request->input('url'), $request->input('context') ?: null), 'Link hinzugefügt.');
    }

    /** @param array<string, string> $params */
    public function remove(Request $request, array $params): Response
    {
        return $this->guarded(fn () => $this->service->remove((int) $params['id']), 'Link entfernt.');
    }

    /** @param array<string, string> $params */
    public function move(Request $request, array $params): Response
    {
        $direction = $request->input('direction') === 'up' ? 'up' : 'down';

        return $this->guarded(fn () => $this->service->move((int) $params['id'], $direction), 'Reihenfolge geändert.');
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
