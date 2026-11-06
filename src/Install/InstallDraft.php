<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Session;

/**
 * Gemerkte Einrichtungswerte. Sie liegen nur in der Sitzung, bis der Abschluss die Konfiguration schreibt.
 */
final class InstallDraft
{
    public const LIFETIME_SECONDS = 1800;

    private const VALUES = 'install_draft';
    private const PROBE = 'install_db_probe';
    private const UNTIL = 'install_draft_until';
    private const ACCOUNT = 'install_account';

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(private readonly Session $session, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function rememberProbe(string $host, int $port, string $name, string $user, string $password): void
    {
        $this->startLifetime();
        $this->session->set(self::PROBE, self::fingerprint($host, $port, $name, $user, $password));
    }

    public function probeMatches(string $host, int $port, string $name, string $user, string $password): bool
    {
        if (!$this->current()) {
            return false;
        }
        $known = $this->session->get(self::PROBE);

        return is_string($known) && hash_equals($known, self::fingerprint($host, $port, $name, $user, $password));
    }

    /**
     * @param array{host: string, port: int, name: string, user: string, password: string, url: string, timezone: string} $values
     */
    public function save(array $values): void
    {
        $this->startLifetime();
        $this->session->set(self::VALUES, $values);
        $this->session->set('install_db_saved', true);
    }

    public function saved(): bool
    {
        return $this->current() && $this->session->get('install_db_saved') === true;
    }

    /**
     * @return array{host: string, port: int, name: string, user: string, password: string, url: string, timezone: string}|null
     */
    public function get(): ?array
    {
        if (!$this->current()) {
            return null;
        }
        $values = $this->session->get(self::VALUES);
        if (!is_array($values)) {
            return null;
        }
        foreach (['host', 'name', 'user', 'password', 'url', 'timezone'] as $key) {
            if (!is_string($values[$key] ?? null)) {
                return null;
            }
        }
        if (!is_int($values['port'] ?? null)) {
            return null;
        }

        return [
            'host' => $values['host'],
            'port' => $values['port'],
            'name' => $values['name'],
            'user' => $values['user'],
            'password' => $values['password'],
            'url' => $values['url'],
            'timezone' => $values['timezone'],
        ];
    }

    public function saveAccount(string $username, string $password, string $secret): void
    {
        $this->startLifetime();
        $this->session->set(self::ACCOUNT, [
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'secret' => $secret,
        ]);
    }

    /**
     * @return array{username: string, password_hash: string, secret: string}|null
     */
    public function account(): ?array
    {
        if (!$this->current()) {
            return null;
        }
        $values = $this->session->get(self::ACCOUNT);
        if (!is_array($values)) {
            return null;
        }
        foreach (['username', 'password_hash', 'secret'] as $key) {
            if (!is_string($values[$key] ?? null) || $values[$key] === '') {
                return null;
            }
        }

        return [
            'username' => $values['username'],
            'password_hash' => $values['password_hash'],
            'secret' => $values['secret'],
        ];
    }

    private function startLifetime(): void
    {
        if (!is_int($this->session->get(self::UNTIL))) {
            $this->session->set(self::UNTIL, $this->now() + self::LIFETIME_SECONDS);
        }
    }

    private function current(): bool
    {
        $until = $this->session->get(self::UNTIL);
        if (!is_int($until)) {
            return false;
        }
        if ($until < $this->now()) {
            $this->discard();

            return false;
        }

        return true;
    }

    private function discard(): void
    {
        $this->session->remove(self::VALUES);
        $this->session->remove(self::PROBE);
        $this->session->remove('install_db_saved');
        $this->session->remove(self::ACCOUNT);
        $this->session->remove(self::UNTIL);
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    public static function fingerprint(string $host, int $port, string $name, string $user, string $password): string
    {
        return hash('sha256', implode("\0", [$host, (string) $port, $name, $user, $password]));
    }
}
