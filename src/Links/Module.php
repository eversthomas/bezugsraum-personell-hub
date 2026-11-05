<?php

declare(strict_types=1);

namespace App\Links;

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
        $container->set(QuickLinkService::class, fn (Container $c): QuickLinkService => new QuickLinkService(
            $c->get(Db::class),
            $c->get(ContextRepository::class)->exists(...),
        ));
        $container->set(LinkController::class, fn (Container $c): LinkController => new LinkController(
            $c->get(QuickLinkService::class),
            $c->get(ContextRepository::class),
            $c->get(View::class),
            $c->get(Csrf::class),
            $c->get(Session::class),
        ));
        // Seitenleiste: Links werden nur geladen, wenn eine Seite mit Gerüst sie anfordert.
        $container->get(View::class)->share('quickLinks', static fn (): array => $container->get(QuickLinkService::class)->all());
        $container->get(View::class)->share('contextColors', static fn (): array => $container->get(QuickLinkService::class)->contextColors());
    }

    public function routes(Router $router, Container $container): void
    {
        $c = static fn (): LinkController => $container->get(LinkController::class);
        $router->get('/einstellungen/links', fn ($r, $p) => $c()->index($r, $p));
        $router->post('/einstellungen/links', fn ($r, $p) => $c()->add($r, $p));
        $router->post('/einstellungen/links/{id}/entfernen', fn ($r, $p) => $c()->remove($r, $p));
        $router->post('/einstellungen/links/{id}/verschieben', fn ($r, $p) => $c()->move($r, $p));
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
