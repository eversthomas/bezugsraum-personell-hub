<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Contracts\ModuleInterface;

/**
 * Grundgerüst der angemeldeten Oberfläche (Startseite, bis der Leitstand in AP 3 kommt).
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
            ]);

            return Response::html($html);
        }, Router::AUTH_REDIRECT);
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
