<?php

declare(strict_types=1);

namespace App\Notify;

enum PushResult
{
    case Delivered;
    /** Abo ist abgelaufen oder widerrufen (404/410): entfernen. */
    case Gone;
    case Failed;
}
