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
        $container->set(ItemController::class, fn (Container $c): ItemController => new ItemController(
            $c->get(ItemService::class),
            $c->get(ContextRepository::class),
            $c->get(View::class),
            $c->get(Csrf::class),
            $c->get(Session::class),
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
