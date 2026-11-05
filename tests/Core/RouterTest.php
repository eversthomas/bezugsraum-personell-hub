<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchesExactAndParameterRoutes(): void
    {
        $router = new Router();
        $router->get('/a', fn (): Response => new Response());
        $router->get('/items/{id}', fn (): Response => new Response());

        self::assertNotNull($router->match(new Request('GET', '/a')));
        $match = $router->match(new Request('GET', '/items/42'));
        self::assertNotNull($match);
        self::assertSame(['id' => '42'], $match['params']);
    }

    public function testUnknownPathOrMethodIsNull(): void
    {
        $router = new Router();
        $router->get('/a', fn (): Response => new Response());

        self::assertNull($router->match(new Request('GET', '/b')));
        self::assertNull($router->match(new Request('POST', '/a')));
        self::assertNull($router->match(new Request('GET', '/a/extra')));
    }

    public function testHeadUsesGetRoute(): void
    {
        $router = new Router();
        $router->get('/a', fn (): Response => new Response());

        self::assertNotNull($router->match(new Request('HEAD', '/a')));
    }
}
