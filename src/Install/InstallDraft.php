<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Session;

/**
 * Gemerkte Einrichtungswerte. Sie liegen nur in der Sitzung, bis der Abschluss die Konfiguration schreibt.
 */
final class InstallDraft
{
    private const VALUES = 'install_draft';
    private const PROBE = 'install_db_probe';

    public function __construct(private readonly Session $session)
    {
    }

    public function rememberProbe(string $host, int $port, string $name, string $user, string $password): void
    {
        $this->session->set(self::PROBE, self::fingerprint($host, $port, $name, $user, $password));
    }

    public function probeMatches(string $host, int $port, string $name, string $user, string $password): bool
    {
        $known = $this->session->get(self::PROBE);

        return is_string($known) && hash_equals($known, self::fingerprint($host, $port, $name, $user, $password));
    }

    /**
     * @param array{host: string, port: int, name: string, user: string, password: string, url: string, timezone: string} $values
     */
    public function save(array $values): void
    {
        $this->session->set(self::VALUES, $values);
        $this->session->set('install_db_saved', true);
    }

    public function saved(): bool
    {
        return $this->session->get('install_db_saved') === true;
    }

    /**
     * @return array{host: string, port: int, name: string, user: string, password: string, url: string, timezone: string}|null
     */
    public function get(): ?array
    {
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
        $this->session->set('install_account', [
            'username' => $username,
            'password' => $password,
            'secret' => $secret,
        ]);
    }

    /**
     * @return array{username: string, password: string, secret: string}|null
     */
    public function account(): ?array
    {
        $values = $this->session->get('install_account');
        if (!is_array($values)) {
            return null;
        }
        foreach (['username', 'password', 'secret'] as $key) {
            if (!is_string($values[$key] ?? null) || $values[$key] === '') {
                return null;
            }
        }

        return [
            'username' => $values['username'],
            'password' => $values['password'],
            'secret' => $values['secret'],
        ];
    }

    public static function fingerprint(string $host, int $port, string $name, string $user, string $password): string
    {
        return hash('sha256', implode("\0", [$host, (string) $port, $name, $user, $password]));
    }
}
