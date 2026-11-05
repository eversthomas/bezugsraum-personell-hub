<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Core\Request;

interface AuthenticatorInterface
{
    public function isAuthenticated(Request $request): bool;
}
