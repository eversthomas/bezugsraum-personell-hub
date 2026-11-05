<?php

declare(strict_types=1);

namespace App\Notify;

use App\Core\Db;

final class PushSubscriptionRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function upsert(string $endpoint, string $p256dh, string $auth, string $label, string $now): void
    {
        $this->db->execute(
            'INSERT INTO push_subscriptions (endpoint_hash, endpoint, p256dh, auth, device_label, created_at) VALUES (?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), device_label = VALUES(device_label), failures = 0',
            [hash('sha256', $endpoint), $endpoint, $p256dh, $auth, $label, $now],
        );
    }

    /** @return list<array{id: int, endpoint: string, p256dh: string, auth: string, label: string, created_at: string, last_ok_at: ?string}> */
    public function all(): array
    {
        $rows = [];
        foreach ($this->db->fetchAll('SELECT id, endpoint, p256dh, auth, device_label, created_at, last_ok_at FROM push_subscriptions ORDER BY id') as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'endpoint' => (string) $row['endpoint'],
                'p256dh' => (string) $row['p256dh'],
                'auth' => (string) $row['auth'],
                'label' => (string) $row['device_label'],
                'created_at' => (string) $row['created_at'],
                'last_ok_at' => $row['last_ok_at'] === null ? null : (string) $row['last_ok_at'],
            ];
        }

        return $rows;
    }

    public function delete(int $id): bool
    {
        return $this->db->execute('DELETE FROM push_subscriptions WHERE id = ?', [$id]) === 1;
    }

    public function deleteByEndpoint(string $endpoint): void
    {
        $this->db->execute('DELETE FROM push_subscriptions WHERE endpoint_hash = ?', [hash('sha256', $endpoint)]);
    }

    public function markOk(int $id, string $now): void
    {
        $this->db->execute('UPDATE push_subscriptions SET last_ok_at = ?, failures = 0 WHERE id = ?', [$now, $id]);
    }

    /** Zählt einen Fehlversuch; nach 20 Fehlern in Folge wird das Abo entfernt. */
    public function markFailure(int $id): void
    {
        $this->db->execute('UPDATE push_subscriptions SET failures = failures + 1 WHERE id = ?', [$id]);
        $this->db->execute('DELETE FROM push_subscriptions WHERE id = ? AND failures >= 20', [$id]);
    }
}
