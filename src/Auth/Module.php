<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Container;
use App\Core\Contracts\AuthenticatorInterface;
use App\Core\Contracts\ModuleInterface;
use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;

final class Module implements ModuleInterface
{
    public function services(Container $container): void
    {
        $container->set(UserRepository::class, fn (Container $c): UserRepository => new UserRepository($c->get(Db::class)));
        $container->set(LoginAttemptRepository::class, fn (Container $c): LoginAttemptRepository => new LoginAttemptRepository($c->get(Db::class)));
        $container->set(TrustedDeviceRepository::class, fn (Container $c): TrustedDeviceRepository => new TrustedDeviceRepository($c->get(Db::class)));
        $container->set(TotpVerifier::class, fn (): TotpVerifier => new TotpVerifier());
        $container->set(AuthService::class, fn (Container $c): AuthService => new AuthService(
            $c->get(UserRepository::class),
            $c->get(LoginAttemptRepository::class),
            $c->get(TrustedDeviceRepository::class),
            $c->get(TotpVerifier::class),
            $c->get(Crypto::class),
            $c->get(Session::class),
            $c->get(Csrf::class),
        ));
        $container->set(AuthenticatorInterface::class, fn (Container $c): AuthenticatorInterface => $c->get(AuthService::class));
        $container->set(AuthController::class, fn (Container $c): AuthController => new AuthController(
            $c->get(AuthService::class),
            $c->get(View::class),
            $c->get(Csrf::class),
        ));
    }

    public function routes(Router $router, Container $container): void
    {
        $controller = static fn (): AuthController => $container->get(AuthController::class);

        $router->get('/login', fn ($r, $p) => $controller()->showLogin($r, $p), Router::AUTH_NONE);
        $router->post('/login', fn ($r, $p) => $controller()->login($r, $p), Router::AUTH_NONE);
        $router->get('/login/code', fn ($r, $p) => $controller()->showCode($r, $p), Router::AUTH_NONE);
        $router->post('/login/code', fn ($r, $p) => $controller()->code($r, $p), Router::AUTH_NONE);
        $router->post('/logout', fn ($r, $p) => $controller()->logout($r, $p), Router::AUTH_REQUIRED);
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
