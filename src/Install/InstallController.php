<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/**
 * Einrichtungscode und Systemprüfung. Weitere Schritte folgen erst nach bestandener Prüfung.
 */
final class InstallController
{
    public function __construct(
        private readonly InstallCode $code,
        private readonly SystemCheck $check,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly View $view,
    ) {
    }

    public function handle(Request $request): Response
    {
        $path = rtrim($request->path, '/') ?: '/';
        if ($request->method === 'POST' && $path === '/einrichten/code') {
            return $this->submitCode($request);
        }
        if ($request->method === 'GET' && ($path === '/' || $path === '/einrichten')) {
            return $this->page();
        }

        return Response::notFound();
    }

    private function page(): Response
    {
        if (!$this->code->ensure()) {
            return $this->render('install/check', [
                'title' => 'Einrichten',
                'problems' => ['Der Einrichtungscode konnte nicht geschrieben werden.', ...$this->check->problems()],
                'ready' => false,
            ]);
        }
        if ($this->session->get('install_unlocked') === true) {
            $problems = $this->check->problems();

            return $this->render('install/check', [
                'title' => 'Einrichten',
                'problems' => $problems,
                'ready' => $problems === [],
            ]);
        }

        return $this->render('install/code', [
            'title' => 'Einrichten',
            'error' => null,
        ]);
    }

    private function submitCode(Request $request): Response
    {
        if (!$this->csrf->isValid($request)) {
            return new Response(403, "Ungültige Anfrage\n", ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $result = $this->code->check($request->input('code'));
        if ($result === 'ok') {
            $this->session->set('install_unlocked', true);

            return Response::redirect('/einrichten');
        }

        return $this->render('install/code', [
            'title' => 'Einrichten',
            'error' => $result === 'renewed'
                ? 'Nach mehreren Fehlversuchen liegt ein neuer Code in der Datei. Bitte ihn erneut ablesen.'
                : 'Der Code stimmt nicht.',
        ], 401);
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data, int $status = 200): Response
    {
        $data += [
            'csrf' => $this->csrf->token(),
            'bodyClass' => 'auth',
        ];

        return Response::html($this->view->render($template, $data), $status);
    }
}
