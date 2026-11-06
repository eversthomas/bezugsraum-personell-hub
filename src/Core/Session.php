<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Sitzung mit sicheren Cookie-Einstellungen. Lazy: wird erst bei Bedarf gestartet.
 */
final class Session
{
    public const NAME = 'ph_session';

    private bool $started = false;
    /** @var array<string, mixed> Nur im Speicher-Modus (Tests) */
    private array $memory = [];

    /** @param string|null $savePath null = Speicher-Modus ohne PHP-Sitzung (für Tests) */
    public function __construct(
        private readonly ?string $savePath,
        private readonly bool $secure,
        private readonly int $lifetime = 28800,
    ) {
    }

    public function start(): void
    {
        if ($this->savePath === null) {
            $this->started = true;

            return;
        }
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;

            return;
        }
        if (!is_dir($this->savePath)) {
            mkdir($this->savePath, 0700, true);
        }
        session_save_path($this->savePath);
        session_name(self::NAME);
        session_cache_limiter('');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $this->lifetime);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        $this->started = true;

        $lastSeen = $_SESSION['last_seen'] ?? null;
        if (is_int($lastSeen) && time() - $lastSeen > $this->lifetime) {
            $this->clear();
            session_start();
        }
        $_SESSION['last_seen'] = time();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $this->savePath === null ? ($this->memory[$key] ?? $default) : ($_SESSION[$key] ?? $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        if ($this->savePath === null) {
            $this->memory[$key] = $value;

            return;
        }
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        if ($this->savePath === null) {
            unset($this->memory[$key]);

            return;
        }
        unset($_SESSION[$key]);
    }

    /** Neue Sitzungs-ID (nach Login und Rechteänderung), Daten bleiben erhalten. */
    public function regenerate(): void
    {
        $this->start();
        if ($this->savePath !== null) {
            session_regenerate_id(true);
        }
    }

    public function secure(): bool
    {
        return $this->secure;
    }

    /** Sitzung vollständig beenden. */
    public function clear(): void
    {
        $this->memory = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        $this->started = false;
    }
}
