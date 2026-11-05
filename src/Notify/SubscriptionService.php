<?php

declare(strict_types=1);

namespace App\Notify;

/**
 * Prüft und speichert Push-Abos. Der Endpunkt wird vom Server aufgerufen; damit niemand den Server zu
 * Anfragen an beliebige Adressen bringen kann, sind nur die Endpunkte der bekannten Push-Dienste erlaubt.
 */
final class SubscriptionService
{
    /** @var list<string> */
    public const ALLOWED_HOST_SUFFIXES = [
        'fcm.googleapis.com',
        'push.services.mozilla.com',
        'push.apple.com',
        'notify.windows.com',
    ];

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param list<string> $extraHosts zusätzlich erlaubte Host-Endungen (config push.allowed_hosts) */
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly array $extraHosts = [],
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @throws \InvalidArgumentException */
    public function subscribe(string $endpoint, string $p256dh, string $auth, string $label): void
    {
        $this->assertEndpoint($endpoint);
        if (preg_match('/^[A-Za-z0-9_-]{20,255}$/', $p256dh) !== 1 || preg_match('/^[A-Za-z0-9_-]{8,255}$/', $auth) !== 1) {
            throw new \InvalidArgumentException('Ungültige Schlüssel.');
        }
        $label = trim(preg_replace('/[\x00-\x1f\x7f]/', '', $label) ?? '');
        $this->subscriptions->upsert(
            $endpoint,
            $p256dh,
            $auth,
            mb_substr($label === '' ? 'Gerät' : $label, 0, 120),
            gmdate('Y-m-d H:i:s', ($this->clock)()),
        );
    }

    public function unsubscribe(string $endpoint): void
    {
        $this->subscriptions->deleteByEndpoint($endpoint);
    }

    /** @throws \InvalidArgumentException */
    public function assertEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);
        if (
            $parts === false
            || strlen($endpoint) > 1000
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || !isset($parts['host'])
            || preg_match('/[\x00-\x20\x7f]/', $endpoint) === 1
        ) {
            throw new \InvalidArgumentException('Ungültiger Push-Endpunkt.');
        }
        $host = strtolower($parts['host']);
        foreach ([...self::ALLOWED_HOST_SUFFIXES, ...$this->extraHosts] as $suffix) {
            $suffix = strtolower($suffix);
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return;
            }
        }

        throw new \InvalidArgumentException('Dieser Push-Dienst ist nicht freigegeben.');
    }
}
