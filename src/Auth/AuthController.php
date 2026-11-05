<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly View $view,
        private readonly Csrf $csrf,
    ) {
    }

    /** @param array<string, string> $params */
    public function showLogin(Request $request, array $params = []): Response
    {
        if ($this->auth->isAuthenticated($request)) {
            return Response::redirect('/');
        }
        $this->auth->cancelPending();

        return $this->page('auth/login', ['error' => null, 'username' => '']);
    }

    /** @param array<string, string> $params */
    public function login(Request $request, array $params = []): Response
    {
        $username = $request->input('username');
        $result = $this->auth->attemptPassword($request, $username, $request->input('password'));

        return match ($result->status) {
            LoginStatus::LoggedIn => Response::redirect('/'),
            LoginStatus::NeedsTotp => Response::redirect('/login/code'),
            LoginStatus::Locked => $this->page('auth/login', [
                'error' => 'Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.',
                'username' => $username,
            ], 429),
            LoginStatus::Invalid => $this->page('auth/login', [
                'error' => 'Anmeldung nicht möglich. Bitte Eingaben prüfen.',
                'username' => $username,
            ], 401),
        };
    }

    /** @param array<string, string> $params */
    public function showCode(Request $request, array $params = []): Response
    {
        if (!$this->auth->hasPendingTotp()) {
            return Response::redirect('/login');
        }

        return $this->page('auth/code', ['error' => null]);
    }

    /** @param array<string, string> $params */
    public function code(Request $request, array $params = []): Response
    {
        if (!$this->auth->hasPendingTotp()) {
            return Response::redirect('/login');
        }
        $remember = $request->input('remember') === '1';
        $result = $this->auth->attemptTotp($request, $request->input('code'), $remember);

        if ($result->status === LoginStatus::LoggedIn) {
            $response = Response::redirect('/');
            if ($result->deviceToken !== null) {
                $response = $response->withCookie(DeviceCookie::NAME, $result->deviceToken, [
                    'expires' => time() + AuthService::DEVICE_DAYS * 86400,
                    'path' => '/',
                    'secure' => $request->isHttps(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }

            return $response;
        }
        if ($result->status === LoginStatus::Locked) {
            return $this->page('auth/login', [
                'error' => 'Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.',
                'username' => '',
            ], 429);
        }
        if (!$this->auth->hasPendingTotp()) {
            return Response::redirect('/login');
        }

        return $this->page('auth/code', ['error' => 'Der Code stimmt nicht.'], 401);
    }

    /** @param array<string, string> $params */
    public function logout(Request $request, array $params = []): Response
    {
        $this->auth->logout();

        return Response::redirect('/login');
    }

    /** @param array<string, mixed> $data */
    private function page(string $template, array $data, int $status = 200): Response
    {
        $data += ['title' => 'Anmelden', 'csrf' => $this->csrf->token(), 'bodyClass' => 'auth'];

        return Response::html($this->view->render($template, $data), $status);
    }
}
