-- AP 8: Einstellungen und vorgemerkte Zeitblöcke. Alle Zeiten in UTC.

CREATE TABLE settings (
    `key` VARCHAR(80) NOT NULL PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE planned_blocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    target VARCHAR(20) NOT NULL,
    status ENUM('pending', 'confirmed', 'expired') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    confirmed_at DATETIME NULL,
    KEY idx_planned_blocks_range (status, starts_at),
    CONSTRAINT fk_planned_blocks_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
