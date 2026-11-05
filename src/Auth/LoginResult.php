<?php

declare(strict_types=1);

namespace App\Auth;

final class LoginResult
{
    public function __construct(
        public readonly LoginStatus $status,
        /** Neues Geräte-Token im Klartext (nur für das Cookie), falls „Gerät merken“ gewählt wurde. */
        public readonly ?string $deviceToken = null,
    ) {
    }
}
