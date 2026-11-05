<?php

declare(strict_types=1);

namespace App\Notify;

use App\Core\Contracts\NotificationChannelInterface;
use App\Core\DeliveryReport;
use App\Core\Notification;

/**
 * Sendet an alle eingetragenen Geräte. Abgelaufene Abos werden entfernt.
 */
final class WebPushChannel implements NotificationChannelInterface
{
    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly PushSender $sender,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function send(Notification $notification): DeliveryReport
    {
        $payload = $notification->toJson();
        $delivered = $failed = $removed = 0;
        foreach ($this->subscriptions->all() as $subscription) {
            $result = $this->sender->send(
                ['endpoint' => $subscription['endpoint'], 'p256dh' => $subscription['p256dh'], 'auth' => $subscription['auth']],
                $payload,
            );
            switch ($result) {
                case PushResult::Delivered:
                    ++$delivered;
                    $this->subscriptions->markOk($subscription['id'], gmdate('Y-m-d H:i:s', ($this->clock)()));
                    break;
                case PushResult::Gone:
                    ++$removed;
                    $this->subscriptions->delete($subscription['id']);
                    break;
                case PushResult::Failed:
                    ++$failed;
                    $this->subscriptions->markFailure($subscription['id']);
                    break;
            }
        }

        return new DeliveryReport($delivered, $failed, $removed);
    }
}
