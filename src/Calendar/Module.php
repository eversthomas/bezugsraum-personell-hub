<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Core\ContextRepository;
use App\Core\Container;
use App\Core\Contracts\ModuleInterface;
use App\Core\Crypto;
use App\Core\CronJob;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Logger;
use App\Core\Router;
use App\Core\SettingsRepository;
use App\Core\Session;
use App\Core\View;
use App\Items\TaskService;

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
        $container->set(PlanningSettings::class, fn (Container $c): PlanningSettings => new PlanningSettings(new SettingsRepository($c->get(Db::class))));
        $container->set(PlannedBlockRepository::class, fn (Container $c): PlannedBlockRepository => new PlannedBlockRepository($c->get(Db::class)));
        $container->set(PlanningService::class, fn (Container $c): PlanningService => new PlanningService(
            $c->get(PlanningSettings::class),
            $c->get(EventRepository::class),
            $c->get(PlannedBlockRepository::class),
            ['outlook' => new OutlookWebLinkBuilder(), 'google' => new GoogleCalendarLinkBuilder()],
        ));
        $container->set(PlanningController::class, fn (Container $c): PlanningController => new PlanningController(
            $c->get(PlanningService::class),
            $c->get(PlanningSettings::class),
            $c->get(TaskService::class),
            $c->get(ContextRepository::class),
            $c->get(View::class),
            $c->get(Csrf::class),
            $c->get(Session::class),
        ));
        $container->set(AgendaController::class, fn (Container $c): AgendaController => new AgendaController(
            $c->get(SourceRepository::class),
            $c->get(EventRepository::class),
            static fn (): int => time(),
            $c->get(PlanningService::class),
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
        $router->post($base . '/{id}/erinnerung', fn ($r, $p) => $c()->reminder($r, $p));
        $router->post($base . '/{id}/abrufen', fn ($r, $p) => $c()->refresh($r, $p));
        $p = static fn (): PlanningController => $container->get(PlanningController::class);
        $router->get('/reservieren', fn ($r, $q) => $p()->index($r, $q));
        $router->post('/reservieren', fn ($r, $q) => $p()->reserve($r, $q));
        $router->get('/reservieren/{id}', fn ($r, $q) => $p()->done($r, $q));
        $router->get('/reservieren/{id}/termin.ics', fn ($r, $q) => $p()->ics($r, $q));
        $router->post('/reservieren/{id}/verwerfen', fn ($r, $q) => $p()->discard($r, $q));
        $router->get('/einstellungen/planung', fn ($r, $q) => $p()->settings($r, $q));
        $router->post('/einstellungen/planung', fn ($r, $q) => $p()->saveSettings($r, $q));

        $router->get('/api/v1/calendar/events', fn ($r, $p) => $container->get(AgendaController::class)->events($r, $p));
        $router->post('/api/v1/calendar/test', fn ($r, $p) => $c()->test($r, $p));
    }

    public function cronJobs(): array
    {
        return [
            new CronJob('calendar.refresh', 15, static function (Container $c): void {
                $c->get(CalendarService::class)->refreshDue();
                $c->get(PlanningService::class)->reconcile();
            }),
        ];
    }

    public function navItems(): array
    {
        return [new \App\Core\NavItem('Kalenderquellen', '/einstellungen/quellen', 900)];
    }
}
