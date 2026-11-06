<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\CronCredentials;
use App\Core\CronCredentialStore;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Time;
use App\Core\View;

final class SecurityController
{
    private const BASE = '/einstellungen/sicherheit';

    public function __construct(
        private readonly AuthService $auth,
        private readonly View $view,
        private readonly Csrf $csrf,
        private readonly Session $session,
        private readonly CronCredentialStore $cronCredentials,
        private readonly string $appUrl,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $flash = $this->session->get('flash');
        $this->session->remove('flash');
        $reveal = $this->session->get('cron_reveal');
        $this->session->remove('cron_reveal');
        $devices = [];
        foreach ($this->auth->trustedDevices() as $device) {
            $devices[] = [
                'id' => (int) $device['id'],
                'label' => (string) $device['device_label'],
                'lastSeen' => Time::berlin((string) $device['last_seen_at']),
                'expires' => Time::berlin((string) $device['expires_at'], 'd.m.Y'),
            ];
        }

        return Response::html($this->view->render('security/index', [
            'title' => 'Sicherheit',
            'bodyClass' => 'app',
            'shell' => true,
            'active' => 'sicherheit',
            'csrf' => $this->csrf->token(),
            'devices' => $devices,
            'minLength' => AuthService::MIN_PASSWORD_LENGTH,
            'flash' => is_array($flash) ? $flash : null,
            'cron' => $this->cronReveal($reveal),
        ]));
    }

    /** @param array<string, string> $params */
    public function regenerateCron(Request $request, array $params = []): Response
    {
        if (!$this->auth->confirmPassword($request, $request->input('password'))) {
            return $this->back('error', 'Das Passwort stimmt nicht.');
        }
        $credentials = CronCredentials::generate($this->appUrl);
        $this->cronCredentials->save($credentials);
        $this->session->set('cron_reveal', [
            'url' => $credentials->url,
            'user' => $credentials->user,
            'password' => $credentials->password,
        ]);

        return Response::redirect(self::BASE);
    }

    /**
     * @return array{url: string, user: string, password: string}|null
     */
    private function cronReveal(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        foreach (['url', 'user', 'password'] as $key) {
            if (!is_string($value[$key] ?? null) || $value[$key] === '') {
                return null;
            }
        }

        return [
            'url' => $value['url'],
            'user' => $value['user'],
            'password' => $value['password'],
        ];
    }

    /** @param array<string, string> $params */
    public function changePassword(Request $request, array $params = []): Response
    {
        if ($request->input('new') !== $request->input('confirm')) {
            return $this->back('error', 'Die beiden neuen Passwörter stimmen nicht überein.');
        }
        try {
            $this->auth->changePassword($request, $request->input('current'), $request->input('new'));
        } catch (\InvalidArgumentException $e) {
            return $this->back('error', $e->getMessage());
        }
        $this->csrf->rotate();

        return $this->back('ok', 'Passwort geändert. Alle gemerkten Geräte wurden abgemeldet.');
    }

    /** @param array<string, string> $params */
    public function revoke(Request $request, array $params): Response
    {
        $ok = $this->auth->revokeDevice((int) $params['id']);

        return $this->back($ok ? 'ok' : 'error', $ok ? 'Gerät entfernt. Es braucht beim nächsten Mal wieder den Code.' : 'Gerät nicht gefunden.');
    }

    /** @param array<string, string> $params */
    public function revokeAll(Request $request, array $params = []): Response
    {
        $this->auth->revokeAllDevices();

        return $this->back('ok', 'Alle gemerkten Geräte wurden entfernt.');
    }

    private function back(string $type, string $message): Response
    {
        $this->session->set('flash', ['type' => $type, 'message' => $message]);

        return Response::redirect(self::BASE);
    }
}
