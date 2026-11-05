<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Core\Container;
use App\Core\Contracts\ModuleInterface;
use App\Core\Crypto;
use App\Core\CronJob;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Logger;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;

final class Module implements ModuleInterface
{
    public function services(Container $container): void
    {
        $container->set(HostResolver::class, fn (): HostResolver => new DnsHostResolver());
        $container->set(UrlGuard::class, fn (Container $c): UrlGuard => new UrlGuard($c->get(HostResolver::class)));
        $container->set(HttpTransport::class, fn (): HttpTransport => new CurlTransport());
        $container->set(IcsFetcher::class, fn (Container $c): IcsFetcher => new IcsFetcher($c->get(UrlGuard::class), $c->get(HttpTransport::class)));
        $container->set(IcsParser::class, fn (): IcsParser => new IcsParser());
        $container->set(SourceRepository::class, fn (Container $c): SourceRepository => new SourceRepository($c->get(Db::class)));
        $container->set(EventRepository::class, fn (Container $c): EventRepository => new EventRepository($c->get(Db::class)));
        $container->set(CalendarService::class, fn (Container $c): CalendarService => new CalendarService(
            $c->get(SourceRepository::class),
            $c->get(EventRepository::class),
            $c->get(IcsFetcher::class),
            $c->get(UrlGuard::class),
            $c->get(IcsParser::class),
            $c->get(Crypto::class),
            $c->get(Logger::class),
        ));
        $container->set(SourceController::class, fn (Container $c): SourceController => new SourceController(
            $c->get(CalendarService::class),
            $c->get(SourceRepository::class),
            $c->get(View::class),
            $c->get(Csrf::class),
            $c->get(Session::class),
        ));
    }

    public function routes(Router $router, Container $container): void
    {
        $c = static fn (): SourceController => $container->get(SourceController::class);
        $base = '/einstellungen/quellen';

        $router->get($base, fn ($r, $p) => $c()->index($r, $p));
        $router->post($base, fn ($r, $p) => $c()->create($r, $p));
        $router->post($base . '/{id}', fn ($r, $p) => $c()->update($r, $p));
        $router->post($base . '/{id}/pausieren', fn ($r, $p) => $c()->pause($r, $p));
        $router->post($base . '/{id}/fortsetzen', fn ($r, $p) => $c()->resume($r, $p));
        $router->post($base . '/{id}/entfernen', fn ($r, $p) => $c()->remove($r, $p));
        $router->post($base . '/{id}/link', fn ($r, $p) => $c()->replaceUrl($r, $p));
        $router->post($base . '/{id}/abrufen', fn ($r, $p) => $c()->refresh($r, $p));
        $router->post('/api/v1/calendar/test', fn ($r, $p) => $c()->test($r, $p));
    }

    public function cronJobs(): array
    {
        return [
            new CronJob('calendar.refresh', 15, static function (Container $c): void {
                $c->get(CalendarService::class)->refreshDue();
            }),
        ];
    }

    public function navItems(): array
    {
        return [new \App\Core\NavItem('Kalenderquellen', '/einstellungen/quellen', 900)];
    }
}
