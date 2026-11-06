<?php

declare(strict_types=1);

namespace App\Install;

use App\Auth\AuthService;
use App\Auth\TotpVerifier;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/**
 * Einrichtung bis zum Abschluss. Die Konfiguration entsteht erst nach einem gültigen TOTP-Code.
 */
final class InstallController
{
    public function __construct(
        private readonly InstallCode $code,
        private readonly SystemCheck $check,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly View $view,
        private readonly InstallDraft $draft,
        private readonly DatabaseProbe $probe,
        private readonly InstallFinisher $finisher,
        private readonly TotpVerifier $totp,
        private readonly TotpQr $qr,
        private readonly string $root = '',
        private readonly DocumentRoot $documentRoot = new DocumentRoot(),
        private readonly InstallTransport $transport = new InstallTransport(),
    ) {
    }

    public function handle(Request $request): Response
    {
        if (!$this->documentRoot->matches($this->root, $this->documentRootPath($request))) {
            return $this->render('install/docroot', ['title' => 'Einrichten']);
        }
        $path = rtrim($request->path, '/') ?: '/';
        if ($this->installerStep($request->method, $path) && !$this->transport->allows($request)) {
            return $this->render('install/https', ['title' => 'Einrichten']);
        }
        if ($request->method === 'POST' && $path === '/einrichten/code') {
            return $this->submitCode($request);
        }
        if ($request->method === 'POST' && $path === '/einrichten/datenbank/testen') {
            return $this->testDatabase($request);
        }
        if ($request->method === 'POST' && $path === '/einrichten/datenbank') {
            return $this->saveDatabase($request);
        }
        if ($request->method === 'POST' && $path === '/einrichten/benutzer') {
            return $this->submitUser($request);
        }
        if ($request->method === 'POST' && $path === '/einrichten/abschluss') {
            return $this->finish($request);
        }
        if ($request->method === 'GET' && ($path === '/' || $path === '/einrichten')) {
            return $this->page($request);
        }

        return Response::notFound();
    }

    private function page(Request $request): Response
    {
        if ($this->session->get('install_unlocked') !== true) {
            if (!$this->code->ensure()) {
                return $this->render('install/unavailable', ['title' => 'Einrichten']);
            }

            return $this->render('install/code', [
                'title' => 'Einrichten',
                'error' => null,
            ]);
        }

        $problems = $this->check->problems();
        if ($problems !== []) {
            return $this->render('install/check', [
                'title' => 'Einrichten',
                'problems' => $problems,
                'ready' => false,
            ]);
        }

        if ($this->draft->saved()) {
            if ($this->draft->account() === null) {
                return $this->userForm([]);
            }

            return $this->totpForm([]);
        }

        return $this->databaseForm($request, [], null);
    }

    private function documentRootPath(Request $request): string
    {
        $value = $request->server['DOCUMENT_ROOT'] ?? '';

        return is_string($value) ? $value : '';
    }

    private function installerStep(string $method, string $path): bool
    {
        if ($method === 'GET' && ($path === '/' || $path === '/einrichten')) {
            return true;
        }

        return $method === 'POST' && in_array($path, [
            '/einrichten/code',
            '/einrichten/datenbank/testen',
            '/einrichten/datenbank',
            '/einrichten/benutzer',
            '/einrichten/abschluss',
        ], true);
    }

    /** @param list<string> $errors */
    private function databaseForm(Request $request, array $errors, ?string $notice, bool $keepPosted = false): Response
    {
        $stored = $this->draft->get();
        $source = $keepPosted ? $request : null;

        return $this->render('install/database', [
            'title' => 'Einrichten',
            'host' => $this->field($source, 'host', $stored['host'] ?? '127.0.0.1'),
            'port' => $this->field($source, 'port', (string) ($stored['port'] ?? 3306)),
            'name' => $this->field($source, 'name', $stored['name'] ?? ''),
            'user' => $this->field($source, 'user', $stored['user'] ?? ''),
            'url' => $this->field($source, 'url', $stored['url'] ?? $this->suggestedUrl($request)),
            'timezone' => $this->field($source, 'timezone', $stored['timezone'] ?? 'Europe/Berlin'),
            'errors' => $errors,
            'notice' => $notice,
        ]);
    }

    private function testDatabase(Request $request): Response
    {
        $blocked = $this->guardDatabase($request);
        if ($blocked !== null) {
            return $blocked;
        }
        $read = $this->readDatabase($request);
        if ($read['errors'] !== []) {
            return $this->databaseForm($request, $read['errors'], null, true);
        }
        $values = $read['values'];
        if (!$this->probe->succeeds($values['host'], $values['port'], $values['name'], $values['user'], $values['password'])) {
            return $this->databaseForm($request, ['Die Verbindung ist fehlgeschlagen. Bitte den Zugang prüfen.'], null, true);
        }
        $this->draft->rememberProbe($values['host'], $values['port'], $values['name'], $values['user'], $values['password']);

        return $this->databaseForm($request, [], 'Die Verbindung steht. Der Zugang ist noch nicht gemerkt.', true);
    }

    private function saveDatabase(Request $request): Response
    {
        $blocked = $this->guardDatabase($request);
        if ($blocked !== null) {
            return $blocked;
        }
        $read = $this->readDatabase($request);
        if ($read['errors'] !== []) {
            return $this->databaseForm($request, $read['errors'], null, true);
        }
        $values = $read['values'];
        if (!$this->draft->probeMatches($values['host'], $values['port'], $values['name'], $values['user'], $values['password'])) {
            return $this->databaseForm($request, ['Bitte die Verbindung zuerst prüfen.'], null, true);
        }
        $this->draft->save($values);

        return Response::redirect('/einrichten');
    }

    /** @param list<string> $errors */
    private function userForm(array $errors, string $username = ''): Response
    {
        return $this->render('install/user', [
            'title' => 'Einrichten',
            'errors' => $errors,
            'username' => $username,
            'minLength' => AuthService::MIN_PASSWORD_LENGTH,
        ]);
    }

    /** @param list<string> $errors */
    private function totpForm(array $errors, int $status = 200): Response
    {
        $account = $this->draft->account();
        if ($account === null) {
            return Response::notFound();
        }
        $qr = null;
        try {
            $qr = $this->qr->dataUri($this->totp->provisioningUri($account['secret'], $account['username'], 'Personal Hub'));
        } catch (\Throwable) {
            $errors[] = 'Der QR-Code konnte nicht erzeugt werden. Bitte den Schlüssel manuell eingeben.';
        }

        return $this->render('install/totp', [
            'title' => 'Einrichten',
            'errors' => $errors,
            'secret' => $account['secret'],
            'qr' => $qr,
        ], $status);
    }

    private function submitUser(Request $request): Response
    {
        $blocked = $this->guardAccount($request);
        if ($blocked !== null) {
            return $blocked;
        }
        $username = trim($request->input('username'));
        $password = $request->input('password');
        $errors = [];
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $username) !== 1) {
            $errors[] = 'Der Benutzername darf Buchstaben, Ziffern, Punkt, Unterstrich und Bindestrich enthalten.';
        }
        if (mb_strlen($password) < AuthService::MIN_PASSWORD_LENGTH) {
            $errors[] = 'Das Passwort braucht mindestens ' . AuthService::MIN_PASSWORD_LENGTH . ' Zeichen.';
        } elseif (mb_strlen($password) > 200) {
            $errors[] = 'Das Passwort ist zu lang.';
        }
        if ($errors !== []) {
            return $this->userForm($errors, $username);
        }
        $this->draft->saveAccount($username, $password, $this->totp->generateSecret());

        return Response::redirect('/einrichten');
    }

    private function finish(Request $request): Response
    {
        $blocked = $this->guardAccount($request);
        if ($blocked !== null) {
            return $blocked;
        }
        $account = $this->draft->account();
        $database = $this->draft->get();
        if ($account === null || $database === null) {
            return Response::notFound();
        }
        if ($this->totp->verify($account['secret'], $request->input('code'), time()) === null) {
            return $this->totpForm(['Der Code stimmt nicht.'], 401);
        }
        try {
            $result = $this->finisher->finish($database, $account['username'], $account['password_hash'], $account['secret']);
        } catch (InstallException) {
            return $this->totpForm(['Die Einrichtung konnte nicht abgeschlossen werden. Es wurde keine Konfiguration geschrieben.']);
        }
        $html = $this->view->render('install/done', [
            'title' => 'Einrichtung abgeschlossen',
            'bodyClass' => 'auth',
            'username' => $result->username,
            'cronUrl' => $result->cronUrl,
            'cronUser' => $result->cronUser,
            'cronPassword' => $result->cronPassword,
        ]);
        $secure = $this->session->secure();
        $this->session->clear();

        return Response::html($html)->withCookie(Session::NAME, '', [
            'expires' => 1,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function guardAccount(Request $request): ?Response
    {
        if (!$this->csrf->isValid($request)) {
            return new Response(403, "Ungültige Anfrage\n", ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($this->session->get('install_unlocked') !== true || $this->check->problems() !== [] || !$this->draft->saved()) {
            return Response::notFound();
        }

        return null;
    }

    private function guardDatabase(Request $request): ?Response
    {
        if (!$this->csrf->isValid($request)) {
            return new Response(403, "Ungültige Anfrage\n", ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($this->session->get('install_unlocked') !== true || $this->check->problems() !== []) {
            return Response::notFound();
        }

        return null;
    }

    /**
     * @return array{errors: list<string>, values: array{host: string, port: int, name: string, user: string, password: string, url: string, timezone: string}}
     */
    private function readDatabase(Request $request): array
    {
        $host = trim($request->input('host'));
        $portText = trim($request->input('port'));
        $name = trim($request->input('name'));
        $user = trim($request->input('user'));
        $password = $request->input('password');
        $url = trim($request->input('url'));
        $timezone = trim($request->input('timezone'));
        $errors = [];
        if ($host === '' || preg_match('/^[A-Za-z0-9.-]{1,253}$/', $host) !== 1) {
            $errors[] = 'Bitte einen gültigen Host angeben.';
        }
        if (preg_match('/^\d{1,5}$/', $portText) !== 1 || (int) $portText < 1 || (int) $portText > 65535) {
            $errors[] = 'Der Port muss zwischen 1 und 65535 liegen.';
        }
        if ($name === '' || strlen($name) > 64) {
            $errors[] = 'Bitte einen Datenbanknamen angeben.';
        }
        if ($user === '' || strlen($user) > 64) {
            $errors[] = 'Bitte einen Datenbankbenutzer angeben.';
        }
        if (strlen($password) > 200) {
            $errors[] = 'Das Datenbankpasswort ist zu lang.';
        }
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            $errors[] = 'Die App-Adresse muss mit http:// oder https:// beginnen.';
        } elseif (!InstallTransport::allowsHttpUrl($url)) {
            $errors[] = 'Eine http-Adresse ist nur für localhost zulässig.';
        }
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $errors[] = 'Die Zeitzone ist unbekannt.';
        }

        return ['errors' => $errors, 'values' => [
            'host' => $host,
            'port' => (int) $portText,
            'name' => $name,
            'user' => $user,
            'password' => $password,
            'url' => $url,
            'timezone' => $timezone,
        ]];
    }

    private function field(?Request $request, string $name, string $fallback): string
    {
        if ($request === null) {
            return $fallback;
        }
        $value = $request->input($name);

        return $value !== '' ? $value : $fallback;
    }

    private function suggestedUrl(Request $request): string
    {
        $host = $request->server['HTTP_HOST'] ?? '';
        if (!is_string($host) || preg_match('/^[A-Za-z0-9.-]+(?::\d{1,5})?$/', $host) !== 1) {
            return '';
        }

        return ($request->isHttps(false) ? 'https://' : 'http://') . $host;
    }

    private function submitCode(Request $request): Response
    {
        if (!$this->csrf->isValid($request)) {
            return new Response(403, "Ungültige Anfrage\n", ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $result = $this->code->check($request->input('code'), $request->ip());
        if ($result === 'ok') {
            $this->session->set('install_unlocked', true);
            $this->session->regenerate();

            return Response::redirect('/einrichten');
        }

        return $this->render('install/code', [
            'title' => 'Einrichten',
            'error' => $result === 'renewed'
                ? 'Nach mehreren Fehlversuchen liegt ein neuer Code in der Datei. Bitte ihn erneut ablesen.'
                : 'Der Code stimmt nicht.',
        ], 401);
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data, int $status = 200): Response
    {
        $data += [
            'csrf' => $this->csrf->token(),
            'bodyClass' => 'auth',
        ];

        return Response::html($this->view->render($template, $data), $status);
    }
}
