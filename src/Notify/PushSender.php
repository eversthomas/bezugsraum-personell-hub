<?php

declare(strict_types=1);

namespace App\Notify;

interface PushSender
{
    /** @param array{endpoint: string, p256dh: string, auth: string} $subscription */
    public function send(array $subscription, string $payload): PushResult;
}
