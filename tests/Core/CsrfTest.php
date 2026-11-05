<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testTokenFromFieldOrHeaderIsAccepted(): void
    {
        $csrf = new Csrf(new Session(null, false));
        $token = $csrf->token();

        self::assertTrue($csrf->isValid(new Request('POST', '/x', post: ['_csrf' => $token])));
        self::assertTrue($csrf->isValid(new Request('POST', '/x', headers: ['x-csrf-token' => $token])));
    }

    public function testMissingOrWrongTokenIsRejected(): void
    {
        $csrf = new Csrf(new Session(null, false));
        $csrf->token();

        self::assertFalse($csrf->isValid(new Request('POST', '/x')));
        self::assertFalse($csrf->isValid(new Request('POST', '/x', post: ['_csrf' => 'falsch'])));
    }

    public function testNoTokenInSessionRejectsEverything(): void
    {
        $csrf = new Csrf(new Session(null, false));

        self::assertFalse($csrf->isValid(new Request('POST', '/x', post: ['_csrf' => ''])));
    }

    public function testJsonBodyToken(): void
    {
        $csrf = new Csrf(new Session(null, false));
        $token = $csrf->token();
        $request = new Request(
            'POST',
            '/api/v1/x',
            headers: ['content-type' => 'application/json'],
            body: json_encode(['_csrf' => $token], JSON_THROW_ON_ERROR),
        );

        self::assertTrue($csrf->isValid($request));
    }
}
