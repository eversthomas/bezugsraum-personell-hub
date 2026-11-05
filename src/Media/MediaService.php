<?php

declare(strict_types=1);

namespace App\Media;

use App\Core\Contracts\MediaStorageInterface;
use App\Core\Db;

/**
 * Fotos zu Einträgen. Gespeichert wird nur das neu codierte Bild.
 */
final class MediaService
{
    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        private readonly Db $db,
        private readonly MediaStorageInterface $storage,
        private readonly ImageProcessor $processor,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @throws ImageException bei ungültigen Bildern
     * @return int Medien-ID
     */
    public function attach(int $itemId, string $binary): int
    {
        $image = $this->processor->process($binary);
        $name = $this->uuid();
        $this->storage->put($name, $image->data);
        try {
            $this->db->execute(
                'INSERT INTO item_media (item_id, file_name, mime, width, height, bytes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$itemId, $name, $image->mime, $image->width, $image->height, strlen($image->data), gmdate('Y-m-d H:i:s', ($this->clock)())],
            );
        } catch (\Throwable $e) {
            $this->storage->delete($name); // keine verwaisten Dateien
            throw $e;
        }

        return $this->db->lastInsertId();
    }

    /** @return array{data: string, mime: string}|null */
    public function load(int $mediaId): ?array
    {
        $row = $this->db->fetchOne('SELECT file_name, mime FROM item_media WHERE id = ?', [$mediaId]);
        if ($row === null) {
            return null;
        }
        $data = $this->storage->get((string) $row['file_name']);

        return $data === null ? null : ['data' => $data, 'mime' => (string) $row['mime']];
    }

    /** @return list<array{id: int, width: int, height: int}> */
    public function forItem(int $itemId): array
    {
        $result = [];
        foreach ($this->db->fetchAll('SELECT id, width, height FROM item_media WHERE item_id = ? ORDER BY id', [$itemId]) as $row) {
            $result[] = ['id' => (int) $row['id'], 'width' => (int) $row['width'], 'height' => (int) $row['height']];
        }

        return $result;
    }

    /**
     * @param list<int> $itemIds
     * @return array<int, list<int>> Eintrags-ID => Medien-IDs
     */
    public function idsForItems(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT item_id, id FROM item_media WHERE item_id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ') ORDER BY id',
            $itemIds,
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['item_id']][] = (int) $row['id'];
        }

        return $map;
    }

    /** Löscht die Dateien eines Eintrags (die Datenbankzeilen entfernt der Fremdschlüssel). */
    public function purgeFilesForItem(int $itemId): void
    {
        foreach ($this->db->fetchAll('SELECT file_name FROM item_media WHERE item_id = ?', [$itemId]) as $row) {
            $this->storage->delete((string) $row['file_name']);
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
