-- AP 7: Push-Abos, Aufgaben-Erinnerungen und Terminhinweise. Alle Zeiten in UTC.

CREATE TABLE push_subscriptions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    endpoint_hash CHAR(64) NOT NULL,
    endpoint VARCHAR(1000) NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth VARCHAR(255) NOT NULL,
    device_label VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL,
    last_ok_at DATETIME NULL,
    failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_push_subscriptions_endpoint (endpoint_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reminders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_id BIGINT UNSIGNED NOT NULL,
    remind_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_reminders_item_time (item_id, remind_at),
    KEY idx_reminders_due (sent_at, remind_at),
    CONSTRAINT fk_reminders_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE calendar_sources ADD COLUMN remind_before_min SMALLINT UNSIGNED NULL AFTER interval_min;

CREATE TABLE event_reminders_sent (
    source_id INT UNSIGNED NOT NULL,
    uid_hash CHAR(40) NOT NULL,
    starts_at DATETIME NOT NULL,
    sent_at DATETIME NOT NULL,
    PRIMARY KEY (source_id, uid_hash, starts_at),
    KEY idx_event_reminders_sent_at (sent_at),
    CONSTRAINT fk_event_reminders_source FOREIGN KEY (source_id) REFERENCES calendar_sources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
