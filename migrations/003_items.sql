-- AP 4: gemeinsamer Eintragstyp für Inbox, Aufgaben und Notizen (ADR 0002). Alle Zeiten in UTC.

CREATE TABLE items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    type ENUM('inbox', 'task', 'note') NOT NULL DEFAULT 'inbox',
    origin ENUM('text', 'voice', 'photo', 'link', 'share') NOT NULL DEFAULT 'text',
    title VARCHAR(500) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    context VARCHAR(40) NULL,
    due_date DATE NULL,
    recurrence VARCHAR(40) NULL,
    done_at DATETIME NULL,
    archived_at DATETIME NULL,
    client_uuid CHAR(36) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_items_client_uuid (client_uuid),
    KEY idx_items_type (type, archived_at, created_at),
    KEY idx_items_due (due_date),
    CONSTRAINT fk_items_context FOREIGN KEY (context) REFERENCES contexts (`key`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
