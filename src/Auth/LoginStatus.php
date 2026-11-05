<?php

declare(strict_types=1);

namespace App\Auth;

enum LoginStatus
{
    case Locked;
    case Invalid;
    case NeedsTotp;
    case LoggedIn;
}
