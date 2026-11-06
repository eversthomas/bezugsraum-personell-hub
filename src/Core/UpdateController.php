<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Einzige Seite, solange Migrationen ausstehen. Abgemeldete Besucher sehen sie nicht.
 */
final class UpdateController
{
    public function __construct(
        private readonly MigratorInterface $migrator,
        private readonly View $view,
        private readonly Csrf $csrf,
    ) {
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        if ($this->migrator->pending() === []) {
            return Response::notFound();
        }

        return $this->page(null);
    }

    /** @param array<string, string> $params */
    public function apply(Request $request, array $params = []): Response
    {
        if ($this->migrator->pending() === []) {
            return Response::notFound();
        }

        return $this->page($this->migrator->migrate());
    }

    /** @param list<string>|null $applied */
    private function page(?array $applied): Response
    {
        return Response::html($this->view->render('update', [
            'title' => 'Datenbank aktualisieren',
            'bodyClass' => 'auth',
            'csrf' => $this->csrf->token(),
            'applied' => $applied,
        ]));
    }
}
