-- AP 6: Fotos zu Einträgen. Dateien liegen in storage/uploads/, nie im Webroot.

CREATE TABLE item_media (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_id BIGINT UNSIGNED NOT NULL,
    file_name CHAR(36) NOT NULL,
    mime VARCHAR(40) NOT NULL,
    width SMALLINT UNSIGNED NOT NULL,
    height SMALLINT UNSIGNED NOT NULL,
    bytes INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_item_media_file (file_name),
    KEY idx_item_media_item (item_id),
    CONSTRAINT fk_item_media_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
