<?php

declare(strict_types=1);

namespace App\Notify;

use App\Core\Contracts\NotificationChannelInterface;
use App\Core\Csrf;
use App\Core\Notification;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Time;
use App\Core\View;

final class PushController
{
    public function __construct(
        private readonly SubscriptionService $service,
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly NotificationChannelInterface $channel,
        private readonly View $view,
        private readonly Csrf $csrf,
        private readonly Session $session,
        private readonly string $publicKey,
    ) {
    }

    /** @param array<string, string> $params */
    public function page(Request $request, array $params = []): Response
    {
        $flash = $this->session->get('flash');
        $this->session->remove('flash');
        $devices = [];
        foreach ($this->subscriptions->all() as $sub) {
            $devices[] = [
                'id' => $sub['id'],
                'label' => $sub['label'],
                'since' => Time::berlin($sub['created_at'], 'd.m.Y'),
                'lastOk' => $sub['last_ok_at'] === null ? 'noch nie' : Time::berlin($sub['last_ok_at']),
            ];
        }

        return Response::html($this->view->render('notify/index', [
            'title' => 'Benachrichtigungen',
            'bodyClass' => 'app',
            'shell' => true,
            'active' => 'push',
            'csrf' => $this->csrf->token(),
            'devices' => $devices,
            'configured' => $this->publicKey !== '',
            'flash' => is_array($flash) ? $flash : null,
        ]));
    }

    /** @param array<string, string> $params */
    public function key(Request $request, array $params = []): Response
    {
        if ($this->publicKey === '') {
            return Response::json(['error' => 'Push ist nicht eingerichtet.'], 503);
        }

        return Response::json(['publicKey' => $this->publicKey]);
    }

    /** @param array<string, string> $params */
    public function subscribe(Request $request, array $params = []): Response
    {
        $json = $request->json();
        $keys = is_array($json['keys'] ?? null) ? $json['keys'] : [];
        try {
            $this->service->subscribe(
                is_string($json['endpoint'] ?? null) ? $json['endpoint'] : '',
                is_string($keys['p256dh'] ?? null) ? $keys['p256dh'] : '',
                is_string($keys['auth'] ?? null) ? $keys['auth'] : '',
                is_string($json['label'] ?? null) ? $json['label'] : '',
            );
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json(['ok' => true], 201);
    }

    /** @param array<string, string> $params */
    public function unsubscribe(Request $request, array $params = []): Response
    {
        $endpoint = $request->json()['endpoint'] ?? '';
        if (is_string($endpoint) && $endpoint !== '') {
            $this->service->unsubscribe($endpoint);
        }

        return Response::json(['ok' => true]);
    }

    /** @param array<string, string> $params */
    public function test(Request $request, array $params = []): Response
    {
        $report = $this->channel->send(new Notification('Testnachricht', 'Die Erinnerungen funktionieren auf diesem Gerät.', '/einstellungen/benachrichtigungen', 'test'));

        return Response::json([
            'delivered' => $report->delivered,
            'failed' => $report->failed,
            'removed' => $report->removed,
        ]);
    }

    /** @param array<string, string> $params */
    public function remove(Request $request, array $params): Response
    {
        $ok = $this->subscriptions->delete((int) $params['id']);
        $this->session->set('flash', ['type' => $ok ? 'ok' : 'error', 'message' => $ok ? 'Gerät entfernt.' : 'Gerät nicht gefunden.']);

        return Response::redirect('/einstellungen/benachrichtigungen');
    }
}
