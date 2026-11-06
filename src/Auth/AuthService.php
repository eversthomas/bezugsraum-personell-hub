<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Contracts\AuthenticatorInterface;
use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;

/**
 * Anmeldung mit Passwort und TOTP, Sperre nach Fehlversuchen, „Gerät merken“.
 */
final class AuthService implements AuthenticatorInterface
{
    public const MAX_FAILURES = 5;
    public const MAX_FAILURES_PER_ACCOUNT = 20;
    public const LOCK_SECONDS = 900;
    public const PENDING_SECONDS = 300;
    public const DEVICE_DAYS = 30;
    public const MIN_PASSWORD_LENGTH = 12;

    /** Hash eines zufälligen Textes: gleicher Aufwand, auch wenn der Nutzername nicht existiert. */
    private const DUMMY_HASH = '$2y$12$Lus4ZDmUArdHQJT.b/v21O1UwKaXtWzf7eumiNneen8XOBFXXWWZC';

    /** @var \Closure(): int */
    private \Closure $clock;
    private ?User $current = null;

    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginAttemptRepository $attempts,
        private readonly TrustedDeviceRepository $devices,
        private readonly TotpVerifier $totp,
        private readonly Crypto $crypto,
        private readonly Session $session,
        private readonly Csrf $csrf,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function isAuthenticated(Request $request): bool
    {
        return $this->currentUser() !== null;
    }

    public function currentUser(): ?User
    {
        if ($this->current !== null) {
            return $this->current;
        }
        $id = $this->session->get('uid');
        if (!is_int($id)) {
            return null;
        }

        return $this->current = $this->users->findById($id);
    }

    /** @phpstan-impure */
    public function hasPendingTotp(): bool
    {
        $id = $this->session->get('pending_uid');
        $until = $this->session->get('pending_until');

        return is_int($id) && is_int($until) && $until >= $this->now();
    }

    public function cancelPending(): void
    {
        $this->session->remove('pending_uid');
        $this->session->remove('pending_until');
    }

    public function attemptPassword(Request $request, string $username, string $password): LoginResult
    {
        $username = trim($username);
        $ipHash = $this->crypto->hmac('ip', $request->ip());
        $userHash = $this->crypto->hmac('user', mb_strtolower(mb_substr($username, 0, 190)));

        $this->attempts->purgeOlderThan($this->time($this->now() - 86400));
        if ($this->isLocked($ipHash, $userHash)) {
            return new LoginResult(LoginStatus::Locked);
        }

        $user = $username === '' ? null : $this->users->findByUsername($username);
        // Auch bei unbekanntem Namen wird gegen einen Hash geprüft (gleicher Zeitaufwand).
        $valid = password_verify($password, $user !== null ? $user->passwordHash : self::DUMMY_HASH);
        if ($user === null || !$valid) {
            $this->attempts->record($ipHash, $userHash, false, $this->time($this->now()));

            return new LoginResult(LoginStatus::Invalid);
        }

        if (password_needs_rehash($user->passwordHash, PASSWORD_DEFAULT)) {
            $this->users->updatePasswordHash($user->id, password_hash($password, PASSWORD_DEFAULT));
        }

        $cookie = $request->cookie(DeviceCookie::NAME);
        if ($cookie !== null && $cookie !== '') {
            $deviceId = $this->devices->findValid($user->id, hash('sha256', $cookie), $this->time($this->now()));
            if ($deviceId !== null) {
                $this->devices->touch($deviceId, $this->time($this->now()));
                $this->attempts->clearFailuresForIp($ipHash);
                $this->completeLogin($user);

                return new LoginResult(LoginStatus::LoggedIn);
            }
        }

        $this->session->regenerate();
        $this->session->set('pending_uid', $user->id);
        $this->session->set('pending_until', $this->now() + self::PENDING_SECONDS);

        return new LoginResult(LoginStatus::NeedsTotp);
    }

    public function attemptTotp(Request $request, string $code, bool $remember): LoginResult
    {
        $pendingId = $this->session->get('pending_uid');
        if (!$this->hasPendingTotp() || !is_int($pendingId)) {
            $this->cancelPending();

            return new LoginResult(LoginStatus::Invalid);
        }
        $user = $this->users->findById($pendingId);
        if ($user === null) {
            $this->cancelPending();

            return new LoginResult(LoginStatus::Invalid);
        }

        $ipHash = $this->crypto->hmac('ip', $request->ip());
        $userHash = $this->crypto->hmac('user', mb_strtolower(mb_substr($user->username, 0, 190)));
        if ($this->isLocked($ipHash, $userHash)) {
            $this->cancelPending();

            return new LoginResult(LoginStatus::Locked);
        }

        $step = $this->totp->verify($this->crypto->decrypt($user->totpSecretEncrypted), $code, $this->now());
        if ($step === null || !$this->users->consumeTotpStep($user->id, $step)) {
            $this->attempts->record($ipHash, $userHash, false, $this->time($this->now()));

            return new LoginResult(LoginStatus::Invalid);
        }

        $this->attempts->clearFailuresForIp($ipHash);
        $this->cancelPending();
        $this->completeLogin($user);

        $token = null;
        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $this->devices->create(
                $user->id,
                hash('sha256', $token),
                $this->deviceLabel($request->userAgent()),
                $this->time($this->now()),
                $this->time($this->now() + self::DEVICE_DAYS * 86400),
            );
        }

        return new LoginResult(LoginStatus::LoggedIn, $token);
    }

    public function confirmPassword(Request $request, string $password): bool
    {
        $user = $this->currentUser();
        if ($user === null) {
            return false;
        }
        $keys = $this->openAttempt($request, $user);
        if ($keys === null) {
            return false;
        }
        [$ipHash, $userHash] = $keys;
        if (!password_verify($password, $user->passwordHash)) {
            $this->attempts->record($ipHash, $userHash, false, $this->time($this->now()));

            return false;
        }
        $this->clearFailures($ipHash, $userHash);

        return true;
    }

    /**
     * Ändert das Passwort. Alle gemerkten Geräte werden danach widerrufen.
     *
     * @throws \InvalidArgumentException bei falschem aktuellem Passwort oder zu kurzem neuem
     */
    public function changePassword(Request $request, string $current, string $new): void
    {
        $user = $this->currentUser() ?? throw new \InvalidArgumentException('Nicht angemeldet.');
        $keys = $this->openAttempt($request, $user);
        if ($keys === null) {
            throw new \InvalidArgumentException('Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.');
        }
        [$ipHash, $userHash] = $keys;
        if (!password_verify($current, $user->passwordHash)) {
            $this->attempts->record($ipHash, $userHash, false, $this->time($this->now()));
            throw new \InvalidArgumentException('Das aktuelle Passwort stimmt nicht.');
        }
        $this->clearFailures($ipHash, $userHash);
        if (mb_strlen($new) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException('Das neue Passwort braucht mindestens ' . self::MIN_PASSWORD_LENGTH . ' Zeichen.');
        }
        if ($new === $current) {
            throw new \InvalidArgumentException('Das neue Passwort muss sich vom alten unterscheiden.');
        }
        $this->users->updatePasswordHash($user->id, password_hash($new, PASSWORD_DEFAULT));
        $this->devices->revokeAll($user->id);
        $this->current = null;
        $this->session->regenerate();
    }

    /** @return list<array<string, mixed>> */
    public function trustedDevices(): array
    {
        $user = $this->currentUser();

        return $user === null ? [] : $this->devices->listForUser($user->id);
    }

    public function revokeDevice(int $id): bool
    {
        $user = $this->currentUser();

        return $user !== null && $this->devices->revoke($user->id, $id);
    }

    public function revokeAllDevices(): void
    {
        $user = $this->currentUser();
        if ($user !== null) {
            $this->devices->revokeAll($user->id);
        }
    }

    public function logout(): void
    {
        $this->current = null;
        $this->session->clear();
    }

    private function completeLogin(User $user): void
    {
        $this->session->regenerate();
        $this->csrf->rotate();
        $this->session->set('uid', $user->id);
        $this->current = $user;
    }

    /**
     * @return array{0: string, 1: string}|null Schlüssel für IP und Konto, null wenn die Sperre greift
     */
    private function openAttempt(Request $request, User $user): ?array
    {
        $ipHash = $this->crypto->hmac('ip', $request->ip());
        $userHash = $this->crypto->hmac('user', mb_strtolower(mb_substr($user->username, 0, 190)));
        $this->attempts->purgeOlderThan($this->time($this->now() - 86400));
        if ($this->isLocked($ipHash, $userHash)) {
            $this->logout();

            return null;
        }

        return [$ipHash, $userHash];
    }

    private function clearFailures(string $ipHash, string $userHash): void
    {
        $this->attempts->clearFailuresForIp($ipHash);
        $this->attempts->clearFailuresForUser($userHash);
    }

    private function isLocked(string $ipHash, string $userHash): bool
    {
        $since = $this->time($this->now() - self::LOCK_SECONDS);

        return $this->attempts->failuresByIp($ipHash, $since) >= self::MAX_FAILURES
            || $this->attempts->failuresByUser($userHash, $since) >= self::MAX_FAILURES_PER_ACCOUNT;
    }

    private function deviceLabel(string $userAgent): string
    {
        $os = 'Gerät';
        foreach (['Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Windows' => 'Windows',
            'Macintosh' => 'Mac', 'Linux' => 'Linux'] as $needle => $label) {
            if (str_contains($userAgent, $needle)) {
                $os = $label;
                break;
            }
        }
        $browser = 'Browser';
        foreach (['Edg/' => 'Edge', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'] as $needle => $label) {
            if (str_contains($userAgent, $needle)) {
                $browser = $label;
                break;
            }
        }

        return $browser . ' auf ' . $os;
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    private function time(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
