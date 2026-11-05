-- AP 2: Kontexte, Kalenderquellen und zwischengespeicherte Termine. Alle Zeiten in UTC.

CREATE TABLE contexts (
    `key` VARCHAR(40) NOT NULL PRIMARY KEY,
    label VARCHAR(80) NOT NULL,
    color VARCHAR(9) NOT NULL,
    sort INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO contexts (`key`, label, color, sort) VALUES
    ('dienstlich', 'Dienstlich', '#2563EB', 10),
    ('betriebsrat', 'Betriebsrat', '#C2410C', 20),
    ('ehrenamt', 'Ehrenamt', '#7C3AED', 30),
    ('privat', 'Privat', '#15803D', 40),
    ('projekte', 'Projekte', '#0F766E', 50);

CREATE TABLE calendar_sources (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    context VARCHAR(40) NOT NULL,
    color VARCHAR(9) NOT NULL,
    url_encrypted VARBINARY(4096) NOT NULL,
    url_hint VARCHAR(190) NOT NULL,
    interval_min SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    active TINYINT(1) NOT NULL DEFAULT 1,
    etag VARCHAR(255) NULL,
    last_attempt_at DATETIME NULL,
    last_ok_at DATETIME NULL,
    last_error VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_calendar_sources_active (active),
    CONSTRAINT fk_calendar_sources_context FOREIGN KEY (context) REFERENCES contexts (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE calendar_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_id INT UNSIGNED NOT NULL,
    uid_hash CHAR(40) NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    title VARCHAR(500) NOT NULL,
    location VARCHAR(500) NOT NULL DEFAULT '',
    fetched_at DATETIME NOT NULL,
    UNIQUE KEY uq_calendar_events_instance (source_id, uid_hash, starts_at),
    KEY idx_calendar_events_range (starts_at, ends_at),
    CONSTRAINT fk_calendar_events_source FOREIGN KEY (source_id) REFERENCES calendar_sources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
