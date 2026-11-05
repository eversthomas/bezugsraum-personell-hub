<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Einfacher Router: exakte Pfade und {platzhalter}. Unbekannte Methode oder Pfad ergibt immer 404.
 */
final class Router
{
    public const AUTH_NONE = 'none';
    /** Ohne Login: 404. */
    public const AUTH_REQUIRED = 'required';
    /** Ohne Login: Weiterleitung zur Anmeldung (nur für die Startseite). */
    public const AUTH_REDIRECT = 'redirect';

    /** @var list<array{method: string, regex: string, handler: callable, auth: string}> */
    private array $routes = [];

    /** @param callable(Request, array<string, string>): Response $handler */
    public function add(string $method, string $pattern, callable $handler, string $auth = self::AUTH_REQUIRED): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => (string) $regex, 'handler' => $handler, 'auth' => $auth];
    }

    /** @param callable(Request, array<string, string>): Response $handler */
    public function get(string $pattern, callable $handler, string $auth = self::AUTH_REQUIRED): void
    {
        $this->add('GET', $pattern, $handler, $auth);
    }

    /** @param callable(Request, array<string, string>): Response $handler */
    public function post(string $pattern, callable $handler, string $auth = self::AUTH_REQUIRED): void
    {
        $this->add('POST', $pattern, $handler, $auth);
    }

    /** @return array{handler: callable, params: array<string, string>, auth: string}|null */
    public function match(Request $request): ?array
    {
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method || preg_match($route['regex'], $request->path, $m) !== 1) {
                continue;
            }
            $params = [];
            foreach ($m as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            return ['handler' => $route['handler'], 'params' => $params, 'auth' => $route['auth']];
        }

        return null;
    }
}
