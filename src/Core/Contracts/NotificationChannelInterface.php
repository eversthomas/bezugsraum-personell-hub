<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Core\DeliveryReport;
use App\Core\Notification;

interface NotificationChannelInterface
{
    public function send(Notification $notification): DeliveryReport;
}
