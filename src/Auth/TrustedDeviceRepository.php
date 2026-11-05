<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Db;

final class TrustedDeviceRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function create(int $userId, string $tokenHash, string $label, string $now, string $expiresAt): int
    {
        $this->db->execute(
            'INSERT INTO trusted_devices (user_id, token_hash, device_label, created_at, last_seen_at, expires_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $tokenHash, $label, $now, $now, $expiresAt],
        );

        return $this->db->lastInsertId();
    }

    /** Liefert die Geräte-ID, wenn das Token zum Nutzer gehört und noch gültig ist. */
    public function findValid(int $userId, string $tokenHash, string $now): ?int
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM trusted_devices WHERE user_id = ? AND token_hash = ? AND expires_at > ?',
            [$userId, $tokenHash, $now],
        );

        return $row === null ? null : (int) $row['id'];
    }

    public function touch(int $id, string $now): void
    {
        $this->db->execute('UPDATE trusted_devices SET last_seen_at = ? WHERE id = ?', [$now, $id]);
    }

    public function revoke(int $userId, int $id): bool
    {
        return $this->db->execute('DELETE FROM trusted_devices WHERE id = ? AND user_id = ?', [$id, $userId]) === 1;
    }

    public function revokeAll(int $userId): void
    {
        $this->db->execute('DELETE FROM trusted_devices WHERE user_id = ?', [$userId]);
    }

    /** @return list<array<string, mixed>> */
    public function listForUser(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT id, device_label, created_at, last_seen_at, expires_at FROM trusted_devices '
            . 'WHERE user_id = ? ORDER BY last_seen_at DESC',
            [$userId],
        );
    }

    public function purgeExpired(string $now): void
    {
        $this->db->execute('DELETE FROM trusted_devices WHERE expires_at <= ?', [$now]);
    }
}
