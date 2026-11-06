<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Contracts\ModuleInterface;

/**
 * Grundgerüst der angemeldeten Oberfläche: Startseite und Kalenderseite.
 */
final class CoreModule implements ModuleInterface
{
    public function services(Container $container): void
    {
    }

    public function routes(Router $router, Container $container): void
    {
        $router->get('/', function (Request $request) use ($container): Response {
            $html = $container->get(View::class)->render('leitstand', [
                'title' => 'Leitstand',
                'csrf' => $container->get(Csrf::class)->token(),
                'bodyClass' => 'app',
                'shell' => true,
                'active' => 'leitstand',
            ]);

            return Response::html($html);
        }, Router::AUTH_REDIRECT);
        $router->get('/kalender', function (Request $request) use ($container): Response {
            $html = $container->get(View::class)->render('calendar', [
                'title' => 'Kalender',
                'csrf' => $container->get(Csrf::class)->token(),
                'bodyClass' => 'app',
                'shell' => true,
                'active' => 'kalender',
            ]);

            return Response::html($html);
        });
        $router->get('/cron/{token}', function (Request $request, array $params) use ($container): Response {
            return $container->get(CronController::class)->run($request, $params);
        }, Router::AUTH_NONE);
        $router->get('/aktualisieren', function (Request $request, array $params) use ($container): Response {
            return $container->get(UpdateController::class)->show($request, $params);
        });
        $router->post('/aktualisieren', function (Request $request, array $params) use ($container): Response {
            return $container->get(UpdateController::class)->apply($request, $params);
        });
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
