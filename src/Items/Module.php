<?php

declare(strict_types=1);

namespace App\Items;

use App\Core\ContextRepository;
use App\Core\Container;
use App\Core\Contracts\ModuleInterface;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Media\MediaService;

final class Module implements ModuleInterface
{
    public function services(Container $container): void
    {
        $container->set(ContextRepository::class, fn (Container $c): ContextRepository => new ContextRepository($c->get(Db::class)));
        $container->set(ItemRepository::class, fn (Container $c): ItemRepository => new ItemRepository($c->get(Db::class)));
        $container->set(ItemService::class, fn (Container $c): ItemService => new ItemService(
            $c->get(ItemRepository::class),
            $c->get(ContextRepository::class)->exists(...),
        ));
        $container->set(TaskService::class, fn (Container $c): TaskService => new TaskService(
            $c->get(ItemRepository::class),
            $c->get(ContextRepository::class)->exists(...),
        ));
        $container->set(MarkdownRenderer::class, fn (): MarkdownRenderer => new MarkdownRenderer());
        $container->set(TaskController::class, fn (Container $c): TaskController => new TaskController(
            $c->get(TaskService::class),
            $c->get(MarkdownRenderer::class),
            $c->get(ContextRepository::class),
            $c->get(View::class),
            $c->get(Csrf::class),
            $c->get(Session::class),
            $c->get(MediaService::class),
        ));
        $container->set(ItemController::class, fn (Container $c): ItemController => new ItemController(
            $c->get(ItemService::class),
            $c->get(ContextRepository::class),
            $c->get(View::class),
            $c->get(Csrf::class),
            $c->get(Session::class),
            $c->get(MediaService::class),
        ));
    }

    public function routes(Router $router, Container $container): void
    {
        $c = static fn (): ItemController => $container->get(ItemController::class);

        $router->get('/erfassen', fn ($r, $p) => $c()->capturePage($r, $p));
        $router->post('/erfassen', fn ($r, $p) => $c()->captureForm($r, $p));
        $router->get('/inbox', fn ($r, $p) => $c()->inbox($r, $p));
        $router->post('/inbox/{id}/einsortieren', fn ($r, $p) => $c()->sort($r, $p));
        $router->post('/inbox/{id}/loeschen', fn ($r, $p) => $c()->delete($r, $p));
        $router->post('/api/v1/items', fn ($r, $p) => $c()->captureApi($r, $p));

        $t = static fn (): TaskController => $container->get(TaskController::class);
        $router->get('/aufgaben', fn ($r, $p) => $t()->tasks($r, $p));
        $router->post('/aufgaben', fn ($r, $p) => $t()->createTask($r, $p));
        $router->get('/aufgaben/{id}', fn ($r, $p) => $t()->editTask($r, $p));
        $router->post('/aufgaben/{id}', fn ($r, $p) => $t()->saveTask($r, $p));
        $router->post('/aufgaben/{id}/erledigt', fn ($r, $p) => $t()->complete($r, $p));
        $router->post('/aufgaben/{id}/wieder-oeffnen', fn ($r, $p) => $t()->reopen($r, $p));
        $router->post('/aufgaben/{id}/loeschen', fn ($r, $p) => $t()->deleteTask($r, $p));
        $router->get('/api/v1/tasks/due', fn ($r, $p) => $t()->dueSummary($r, $p));
        $router->post('/api/v1/tasks/{id}/complete', fn ($r, $p) => $t()->complete($r, $p));

        $router->get('/notizen', fn ($r, $p) => $t()->notes($r, $p));
        $router->get('/notizen/neu', fn ($r, $p) => $t()->newNote($r, $p));
        $router->post('/notizen', fn ($r, $p) => $t()->createNote($r, $p));
        $router->get('/notizen/{id}', fn ($r, $p) => $t()->editNote($r, $p));
        $router->post('/notizen/{id}', fn ($r, $p) => $t()->saveNote($r, $p));
        $router->post('/notizen/{id}/loeschen', fn ($r, $p) => $t()->deleteNote($r, $p));

        $router->get('/export.json', fn ($r, $p) => $t()->export($r, $p));
    }

    public function cronJobs(): array
    {
        return [];
    }

    public function navItems(): array
    {
        return [];
    }
}
