<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Response;
use App\Core\SecurityHeaders;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function testMandatoryHeadersOnEveryResponse(): void
    {
        $response = SecurityHeaders::apply(Response::notFound(), false);

        self::assertSame('noindex, nofollow, noarchive', $response->headers['X-Robots-Tag']);
        self::assertSame('no-referrer', $response->headers['Referrer-Policy']);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        self::assertStringContainsString("default-src 'none'", $response->headers['Content-Security-Policy']);
        self::assertStringNotContainsString('http', $response->headers['Content-Security-Policy']);
        self::assertArrayNotHasKey('Strict-Transport-Security', $response->headers);
    }

    public function testHstsOnlyOverHttps(): void
    {
        $response = SecurityHeaders::apply(Response::notFound(), true);

        self::assertArrayHasKey('Strict-Transport-Security', $response->headers);
    }
}
