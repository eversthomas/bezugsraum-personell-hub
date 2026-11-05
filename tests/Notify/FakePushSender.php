<?php

declare(strict_types=1);

namespace Tests\Notify;

use App\Notify\PushResult;
use App\Notify\PushSender;

final class FakePushSender implements PushSender
{
    /** @var list<array{subscription: array<string, string>, payload: string}> */
    public array $sent = [];
    /** @var array<string, PushResult> Endpunkt => Ergebnis (Standard: zugestellt) */
    public array $results = [];

    public function send(array $subscription, string $payload): PushResult
    {
        $this->sent[] = ['subscription' => $subscription, 'payload' => $payload];

        return $this->results[$subscription['endpoint']] ?? PushResult::Delivered;
    }

    /** @return list<array<string, mixed>> */
    public function payloads(): array
    {
        return array_map(static fn (array $s): array => json_decode($s['payload'], true), $this->sent);
    }
}
