<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public const FIELD = '_csrf';
    public const HEADER = 'X-CSRF-Token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set('csrf', $token);
        }

        return $token;
    }

    public function isValid(Request $request): bool
    {
        $expected = $this->session->get('csrf');
        if (!is_string($expected) || $expected === '') {
            return false;
        }
        $given = $request->header(self::HEADER) ?? $request->input(self::FIELD);

        return $given !== '' && hash_equals($expected, $given);
    }

    /** Nach Login erneuern, damit ein vorab bekanntes Token wertlos ist. */
    public function rotate(): void
    {
        $this->session->remove('csrf');
    }
}
