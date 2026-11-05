<?php

declare(strict_types=1);

namespace App\Items;

use App\Core\ContextRepository;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Time;
use App\Core\View;
use App\Media\MediaService;

final class ItemController
{
    public function __construct(
        private readonly ItemService $service,
        private readonly ContextRepository $contexts,
        private readonly View $view,
        private readonly Csrf $csrf,
        private readonly Session $session,
        private readonly MediaService $media,
    ) {
    }

    /** @param array<string, string> $params */
    public function capturePage(Request $request, array $params = []): Response
    {
        // Share-Target: geteilter Titel, Text und Link landen vorbefüllt im Feld.
        $shared = array_filter([
            $request->query['title'] ?? '',
            $request->query['text'] ?? '',
            $request->query['url'] ?? '',
        ], static fn (string $part): bool => trim($part) !== '');
        $prefill = implode("\n", array_unique($shared));

        return $this->page('items/capture', 'Erfassen', 'erfassen', [
            'prefill' => $prefill,
            'origin' => $prefill !== '' ? 'share' : 'text',
            'flash' => $this->flash(),
        ]);
    }

    /** @param array<string, string> $params */
    public function captureForm(Request $request, array $params = []): Response
    {
        try {
            $this->service->capture($request->input('text'), $request->input('origin', 'text'), $request->input('client_uuid') ?: null);
        } catch (\InvalidArgumentException $e) {
            return $this->back('/erfassen', 'error', $e->getMessage());
        }

        return $this->back('/erfassen', 'ok', 'Gespeichert. Der Eintrag liegt in der Inbox.');
    }

    /** @param array<string, string> $params */
    public function captureApi(Request $request, array $params = []): Response
    {
        try {
            $result = $this->service->capture(
                $request->input('text'),
                $request->input('origin', 'text'),
                $request->input('client_uuid') ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json(
            ['id' => $result['item']->id, 'duplicate' => $result['duplicate']],
            $result['duplicate'] ? 200 : 201,
        );
    }

    /** @param array<string, string> $params */
    public function inbox(Request $request, array $params = []): Response
    {
        $rows = [];
        $inbox = $this->service->inbox();
        $photos = $this->media->idsForItems(array_map(static fn (Item $i): int => $i->id, $inbox));
        foreach ($inbox as $item) {
            $rows[] = [
                'photos' => $photos[$item->id] ?? [],
                'id' => $item->id,
                'title' => $item->title,
                'body' => $item->body,
                'origin' => $item->origin,
                'created' => Time::berlin($item->createdAt),
            ];
        }

        return $this->page('items/inbox', 'Inbox', 'inbox', [
            'rows' => $rows,
            'contexts' => $this->contexts->all(),
            'flash' => $this->flash(),
        ]);
    }

    /** @param array<string, string> $params */
    public function sort(Request $request, array $params): Response
    {
        try {
            $this->service->sort(
                (int) $params['id'],
                $request->input('type'),
                $request->input('context') ?: null,
                $request->input('due') ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->back('/inbox', 'error', $e->getMessage());
        }

        return $this->back('/inbox', 'ok', $request->input('type') === 'task' ? 'Als Aufgabe einsortiert.' : 'Als Notiz einsortiert.');
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): Response
    {
        try {
            $this->media->purgeFilesForItem((int) $params['id']);
            $this->service->delete((int) $params['id']);
        } catch (\InvalidArgumentException $e) {
            return $this->back('/inbox', 'error', $e->getMessage());
        }

        return $this->back('/inbox', 'ok', 'Eintrag gelöscht.');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function page(string $template, string $title, string $active, array $data): Response
    {
        return Response::html($this->view->render($template, $data + [
            'title' => $title,
            'bodyClass' => 'app',
            'shell' => true,
            'active' => $active,
            'csrf' => $this->csrf->token(),
        ]));
    }

    /** @return array{type: string, message: string}|null */
    private function flash(): ?array
    {
        $flash = $this->session->get('flash');
        $this->session->remove('flash');

        return is_array($flash) ? $flash : null;
    }

    private function back(string $path, string $type, string $message): Response
    {
        $this->session->set('flash', ['type' => $type, 'message' => $message]);

        return Response::redirect($path);
    }
}
