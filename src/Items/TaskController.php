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

final class TaskController
{
    public function __construct(
        private readonly TaskService $service,
        private readonly MarkdownRenderer $markdown,
        private readonly ContextRepository $contexts,
        private readonly View $view,
        private readonly Csrf $csrf,
        private readonly Session $session,
        private readonly MediaService $media,
    ) {
    }

    // ---------- Aufgaben ----------

    /** @param array<string, string> $params */
    public function tasks(Request $request, array $params = []): Response
    {
        $context = $this->contextFilter($request);

        return $this->page('tasks/index', 'Aufgaben', 'aufgaben', [
            'groups' => $this->service->grouped($context),
            'done' => $this->service->recentlyDone(),
            'contexts' => $this->contexts->all(),
            'filter' => $context,
            'recurrences' => self::RECURRENCES,
        ]);
    }

    /** @param array<string, string> $params */
    public function createTask(Request $request, array $params = []): Response
    {
        try {
            $this->service->createTask(
                $request->input('title'),
                $request->input('context') ?: null,
                $request->input('due') ?: null,
                $request->input('recurrence') ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->back('/aufgaben', 'error', $e->getMessage());
        }

        return $this->back('/aufgaben', 'ok', 'Aufgabe angelegt.');
    }

    /** @param array<string, string> $params */
    public function editTask(Request $request, array $params): Response
    {
        $task = $this->service->find((int) $params['id']);
        if ($task === null || $task->type !== 'task') {
            return Response::notFound();
        }

        return $this->page('tasks/edit', 'Aufgabe bearbeiten', 'aufgaben', [
            'task' => $task,
            'contexts' => $this->contexts->all(),
            'recurrences' => self::RECURRENCES,
        ]);
    }

    /** @param array<string, string> $params */
    public function saveTask(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        try {
            $this->service->updateTask(
                $id,
                $request->input('title'),
                $request->input('body'),
                $request->input('context') ?: null,
                $request->input('due') ?: null,
                $request->input('recurrence') ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->back('/aufgaben/' . $id, 'error', $e->getMessage());
        }

        return $this->back('/aufgaben', 'ok', 'Änderungen gespeichert.');
    }

    /** @param array<string, string> $params */
    public function complete(Request $request, array $params): Response
    {
        try {
            $next = $this->service->complete((int) $params['id']);
        } catch (\InvalidArgumentException $e) {
            return $this->wants($request) ? Response::json(['error' => $e->getMessage()], 404) : $this->back('/aufgaben', 'error', $e->getMessage());
        }
        if ($this->wants($request)) {
            return Response::json(['ok' => true, 'next' => $next]);
        }

        return $this->back('/aufgaben', 'ok', $next === null ? 'Erledigt.' : 'Erledigt. Nächster Termin: ' . Time::berlin($next . ' 00:00:00', 'd.m.Y') . '.');
    }

    /** @param array<string, string> $params */
    public function reopen(Request $request, array $params): Response
    {
        return $this->guarded('/aufgaben', fn () => $this->service->reopen((int) $params['id']), 'Wieder geöffnet.');
    }

    /** @param array<string, string> $params */
    public function deleteTask(Request $request, array $params): Response
    {
        return $this->guarded('/aufgaben', function () use ($params): void {
            $this->media->purgeFilesForItem((int) $params['id']);
            $this->service->delete((int) $params['id']);
        }, 'Aufgabe gelöscht.');
    }

    /**
     * JSON für die Aufgaben-Karte im Leitstand.
     *
     * @param array<string, string> $params
     */
    public function dueSummary(Request $request, array $params = []): Response
    {
        $summary = $this->service->dueSummary();

        return Response::json([
            'overdue' => $summary['overdue'],
            'today' => $summary['today'],
            'items' => array_map(static fn (Item $i): array => [
                'id' => $i->id,
                'title' => $i->title,
                'due' => $i->dueDate,
                'context' => $i->context,
                'recurring' => $i->recurrence !== null,
            ], $summary['items']),
        ]);
    }

    // ---------- Notizen ----------

    /** @param array<string, string> $params */
    public function notes(Request $request, array $params = []): Response
    {
        $query = trim($request->query['q'] ?? '');
        $context = $this->contextFilter($request);
        $rows = [];
        foreach ($this->service->notes($query, $context) as $note) {
            $rows[] = [
                'id' => $note->id,
                'title' => $note->title,
                'excerpt' => mb_strimwidth(preg_replace('/\s+/', ' ', $note->body) ?? '', 0, 140, '…'),
                'context' => $note->context,
                'updated' => Time::berlin($note->updatedAt),
            ];
        }

        return $this->page('notes/index', 'Notizen', 'notizen', [
            'rows' => $rows,
            'query' => $query,
            'filter' => $context,
            'contexts' => $this->contexts->all(),
        ]);
    }

    /** @param array<string, string> $params */
    public function newNote(Request $request, array $params = []): Response
    {
        return $this->page('notes/edit', 'Neue Notiz', 'notizen', [
            'photos' => [],
            'note' => null,
            'html' => '',
            'contexts' => $this->contexts->all(),
        ]);
    }

    /** @param array<string, string> $params */
    public function createNote(Request $request, array $params = []): Response
    {
        try {
            $id = $this->service->createNote($request->input('title'), $request->input('body'), $request->input('context') ?: null);
        } catch (\InvalidArgumentException $e) {
            return $this->back('/notizen/neu', 'error', $e->getMessage());
        }

        return $this->back('/notizen/' . $id, 'ok', 'Notiz gespeichert.');
    }

    /** @param array<string, string> $params */
    public function editNote(Request $request, array $params): Response
    {
        $note = $this->service->find((int) $params['id']);
        if ($note === null || $note->type !== 'note') {
            return Response::notFound();
        }

        return $this->page('notes/edit', $note->title, 'notizen', [
            'photos' => array_column($this->media->forItem($note->id), 'id'),
            'note' => $note,
            'html' => $this->markdown->render($note->body),
            'contexts' => $this->contexts->all(),
        ]);
    }

    /** @param array<string, string> $params */
    public function saveNote(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        try {
            $this->service->updateNote($id, $request->input('title'), $request->input('body'), $request->input('context') ?: null);
        } catch (\InvalidArgumentException $e) {
            return $this->back('/notizen/' . $id, 'error', $e->getMessage());
        }

        return $this->back('/notizen/' . $id, 'ok', 'Notiz gespeichert.');
    }

    /** @param array<string, string> $params */
    public function deleteNote(Request $request, array $params): Response
    {
        return $this->guarded('/notizen', function () use ($params): void {
            $this->media->purgeFilesForItem((int) $params['id']);
            $this->service->delete((int) $params['id']);
        }, 'Notiz gelöscht.');
    }

    // ---------- Export ----------

    /** @param array<string, string> $params */
    public function export(Request $request, array $params = []): Response
    {
        $json = json_encode([
            'exported_at' => gmdate('c'),
            'contexts' => $this->contexts->all(),
            'items' => $this->service->export(),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (new Response(200, $json, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="personal-hub-export-' . gmdate('Y-m-d') . '.json"',
        ]));
    }

    // ---------- Hilfen ----------

    public const RECURRENCES = [
        'daily' => 'täglich',
        'weekly:mon' => 'wöchentlich, Montag',
        'weekly:tue' => 'wöchentlich, Dienstag',
        'weekly:wed' => 'wöchentlich, Mittwoch',
        'weekly:thu' => 'wöchentlich, Donnerstag',
        'weekly:fri' => 'wöchentlich, Freitag',
        'weekly:sat' => 'wöchentlich, Samstag',
        'weekly:sun' => 'wöchentlich, Sonntag',
        'monthly:1' => 'monatlich, am 1.',
        'monthly:15' => 'monatlich, am 15.',
        'monthly:31' => 'monatlich, am Monatsende',
    ];

    private function contextFilter(Request $request): ?string
    {
        $key = $request->query['kontext'] ?? '';

        return $key !== '' && $this->contexts->exists($key) ? $key : null;
    }

    private function wants(Request $request): bool
    {
        return $request->wantsJson();
    }

    private function guarded(string $path, callable $action, string $success): Response
    {
        try {
            $action();
        } catch (\InvalidArgumentException $e) {
            return $this->back($path, 'error', $e->getMessage());
        }

        return $this->back($path, 'ok', $success);
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

    private function back(string $path, string $type, string $message): Response
    {
        $this->session->set('flash', ['type' => $type, 'message' => $message]);

        return Response::redirect($path);
    }
}
