<?php

declare(strict_types=1);

namespace App\Notify;

use App\Notify\Http\CurlClient;
use App\Notify\Http\Factory;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;

/**
 * Versand über minishlink/web-push (VAPID, aes128gcm). Der HTTP-Client ist austauschbar.
 */
final class MinishlinkPushSender implements PushSender
{
    private ?WebPush $push = null;

    public function __construct(
        private readonly string $subject,
        private readonly string $publicKey,
        private readonly string $privateKey,
        private readonly ?ClientInterface $client = null,
    ) {
    }

    public function send(array $subscription, string $payload): PushResult
    {
        try {
            $report = $this->push()->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription['endpoint'],
                    'publicKey' => $subscription['p256dh'],
                    'authToken' => $subscription['auth'],
                    'contentEncoding' => 'aes128gcm',
                ]),
                $payload,
                ['TTL' => 4 * 3600, 'urgency' => 'normal'],
            );
        } catch (\Throwable) {
            return PushResult::Failed;
        }
        if ($report->isSuccess()) {
            return PushResult::Delivered;
        }

        return $report->isSubscriptionExpired() ? PushResult::Gone : PushResult::Failed;
    }

    private function push(): WebPush
    {
        if ($this->push === null) {
            $factory = new Factory();
            $this->push = new WebPush(
                ['VAPID' => ['subject' => $this->subject, 'publicKey' => $this->publicKey, 'privateKey' => $this->privateKey]],
                [],
                $this->client ?? new CurlClient(),
                $factory,
                $factory,
            );
        }

        return $this->push;
    }
}
