<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Db;

final class LoginAttemptRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function record(string $ipHash, ?string $userHash, bool $success, string $now): void
    {
        $this->db->execute(
            'INSERT INTO login_attempts (ip_hash, user_hash, attempted_at, success) VALUES (?, ?, ?, ?)',
            [$ipHash, $userHash, $now, $success ? 1 : 0],
        );
    }

    public function failuresByIp(string $ipHash, string $since): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND success = 0 AND attempted_at >= ?',
            [$ipHash, $since],
        );
    }

    public function failuresByUser(string $userHash, string $since): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_attempts WHERE user_hash = ? AND success = 0 AND attempted_at >= ?',
            [$userHash, $since],
        );
    }

    public function clearFailuresForIp(string $ipHash): void
    {
        $this->db->execute('DELETE FROM login_attempts WHERE ip_hash = ? AND success = 0', [$ipHash]);
    }

    public function clearFailuresForUser(string $userHash): void
    {
        $this->db->execute('DELETE FROM login_attempts WHERE user_hash = ? AND success = 0', [$userHash]);
    }

    public function purgeOlderThan(string $cutoff): void
    {
        $this->db->execute('DELETE FROM login_attempts WHERE attempted_at < ?', [$cutoff]);
    }
}
