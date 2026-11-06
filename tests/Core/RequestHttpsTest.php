<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Config;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use PHPUnit\Framework\TestCase;

final class RequestHttpsTest extends TestCase
{
    public function testDirectHttpsStandsWithoutTheProxyFlag(): void
    {
        $request = new Request('GET', '/', server: ['HTTPS' => 'on', 'HTTP_X_FORWARDED_PROTO' => 'http']);

        self::assertTrue($request->isHttps(false));
        self::assertTrue($request->isHttps(true));
    }

    public function testForwardedProtoIsIgnoredUnlessTheProxyIsTrusted(): void
    {
        $request = new Request('GET', '/', server: ['HTTP_X_FORWARDED_PROTO' => 'https']);

        self::assertFalse($request->isHttps());
        self::assertFalse($request->isHttps(false));
        self::assertTrue($request->isHttps(true));
    }

    public function testKernelOmitsHstsWhenTheProxyIsNotTrusted(): void
    {
        $response = $this->handle(false);

        self::assertArrayNotHasKey('Strict-Transport-Security', $response->headers);
    }

    public function testKernelSendsHstsWhenTheProxyIsTrusted(): void
    {
        $response = $this->handle(true);

        self::assertSame('max-age=31536000; includeSubDomains', $response->headers['Strict-Transport-Security']);
    }

    private function handle(bool $trustProxy): Response
    {
        $kernel = new Kernel(dirname(__DIR__, 2), new Config([
            'app' => ['url' => 'http://hub.example.invalid', 'trust_proxy' => $trustProxy],
        ]));
        $kernel->boot(require dirname(__DIR__, 2) . '/config/modules.php');

        return $kernel->handle(new Request('GET', '/cron/keins', server: ['HTTP_X_FORWARDED_PROTO' => 'https']));
    }
}
