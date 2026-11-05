<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Einfacher Schlüssel-Wert-Speicher für Einstellungen (Werte als JSON).
 */
final class SettingsRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->db->fetchValue('SELECT value FROM settings WHERE `key` = ?', [$key]);
        if (!is_string($raw)) {
            return $default;
        }

        return json_decode($raw, true, 8) ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->db->execute(
            'INSERT INTO settings (`key`, value, updated_at) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [$key, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s')],
        );
    }
}
