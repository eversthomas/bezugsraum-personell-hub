<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Contracts\AuthenticatorInterface;
use App\Core\Contracts\ModuleInterface;

/**
 * Verdrahtet Konfiguration, Module und Router und beantwortet Anfragen.
 */
final class Kernel
{
    private Container $container;
    private Router $router;
    /** @var list<ModuleInterface> */
    private array $modules = [];

    public function __construct(private readonly string $root, private readonly Config $config)
    {
        $this->container = new Container();
        $this->router = new Router();
        date_default_timezone_set('UTC');
    }

    public function container(): Container
    {
        return $this->container;
    }

    /** @param list<class-string<ModuleInterface>> $moduleClasses */
    public function boot(array $moduleClasses): void
    {
        $c = $this->container;
        $c->instance(Config::class, $this->config);
        $c->set(Logger::class, fn (): Logger => new Logger($this->root . '/storage/logs/app.log'));
        $c->set(Db::class, fn (): Db => Db::connect($this->config));
        $c->set(Crypto::class, fn (): Crypto => Crypto::fromFile($this->config->string('crypto.key_path')));
        if (!$c->has(Session::class)) {
            $c->set(Session::class, fn (): Session => new Session(
                $this->root . '/storage/sessions',
                str_starts_with($this->config->string('app.url'), 'https://'),
            ));
        }
        $c->set(Csrf::class, fn (Container $c): Csrf => new Csrf($c->get(Session::class)));
        $c->set(View::class, fn (): View => new View($this->root . '/templates'));

        foreach ($moduleClasses as $class) {
            $module = new $class();
            $module->services($c);
            $this->modules[] = $module;
        }
        foreach ($this->modules as $module) {
            $module->routes($this->router, $c);
        }
    }

    /** @return list<CronJob> */
    public function cronJobs(): array
    {
        $jobs = [];
        foreach ($this->modules as $module) {
            array_push($jobs, ...$module->cronJobs());
        }

        return $jobs;
    }

    /** @return list<NavItem> */
    public function navItems(): array
    {
        $items = [];
        foreach ($this->modules as $module) {
            array_push($items, ...$module->navItems());
        }
        usort($items, static fn (NavItem $a, NavItem $b): int => $a->sort <=> $b->sort);

        return $items;
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (\Throwable $e) {
            $this->container->get(Logger::class)->error('unhandled', $e);
            $response = $this->errorResponse($request);
        }

        return SecurityHeaders::apply($response, $request->isHttps());
    }

    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request);
        if ($match === null) {
            return Response::notFound();
        }

        $authenticated = $this->container->get(AuthenticatorInterface::class)->isAuthenticated($request);
        if ($match['auth'] === Router::AUTH_REQUIRED && !$authenticated) {
            return Response::notFound();
        }
        if ($match['auth'] === Router::AUTH_REDIRECT && !$authenticated) {
            return Response::redirect('/login');
        }

        if ($request->isMutating() && !$this->container->get(Csrf::class)->isValid($request)) {
            return new Response(403, "Ungültige Anfrage\n", ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $handler = $match['handler'];

        return $handler($request, $match['params']);
    }

    private function errorResponse(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => 'Interner Fehler'], 500);
        }
        try {
            $html = $this->container->get(View::class)->render('error/500', ['title' => 'Fehler', 'bodyClass' => 'auth']);

            return Response::html($html, 500);
        } catch (\Throwable) {
            return new Response(500, "Interner Fehler\n", ['Content-Type' => 'text/plain; charset=utf-8']);
        }
    }
}
