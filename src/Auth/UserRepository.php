<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Db;

final class UserRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function findByUsername(string $username): ?User
    {
        return $this->hydrate($this->db->fetchOne('SELECT * FROM users WHERE username = ?', [$username]));
    }

    public function findById(int $id): ?User
    {
        return $this->hydrate($this->db->fetchOne('SELECT * FROM users WHERE id = ?', [$id]));
    }

    public function create(string $username, string $passwordHash, string $totpSecretEncrypted, string $now): int
    {
        $this->db->execute(
            'INSERT INTO users (username, password_hash, totp_secret_encrypted, created_at) VALUES (?, ?, ?, ?)',
            [$username, $passwordHash, $totpSecretEncrypted, $now],
        );

        return $this->db->lastInsertId();
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->db->execute('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $id]);
    }

    /**
     * Merkt den zuletzt benutzten TOTP-Zeitschritt. Gibt false zurück, wenn er schon verbraucht war
     * (Schutz gegen Wiederverwendung desselben Codes).
     */
    public function consumeTotpStep(int $id, int $step): bool
    {
        return $this->db->execute(
            'UPDATE users SET totp_last_step = ? WHERE id = ? AND totp_last_step < ?',
            [$step, $id, $step],
        ) === 1;
    }

    /** @param array<string, mixed>|null $row */
    private function hydrate(?array $row): ?User
    {
        if ($row === null) {
            return null;
        }

        return new User(
            (int) $row['id'],
            (string) $row['username'],
            (string) $row['password_hash'],
            (string) $row['totp_secret_encrypted'],
            (int) $row['totp_last_step'],
        );
    }
}
