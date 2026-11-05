-- AP 5: Schnellzugriff (Linkliste für Seitenleiste und mobiles Menü).

CREATE TABLE quick_links (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(80) NOT NULL,
    url VARCHAR(2000) NOT NULL,
    context VARCHAR(40) NULL,
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY idx_quick_links_sort (sort, id),
    CONSTRAINT fk_quick_links_context FOREIGN KEY (context) REFERENCES contexts (`key`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
