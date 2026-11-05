<?php

declare(strict_types=1);

namespace Tests\Media;

use App\Items\ItemRepository;
use App\Media\ImageException;
use App\Media\ImageProcessor;
use App\Media\LocalMediaStorage;
use App\Media\MediaService;
use Tests\DbTestCase;

final class MediaServiceTest extends DbTestCase
{
    private string $dir;
    private MediaService $service;
    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phmedia' . bin2hex(random_bytes(4));
        $this->service = new MediaService($this->db, new LocalMediaStorage($this->dir), new ImageProcessor());
        $this->itemId = (new ItemRepository($this->db))->insert('photo', 'Foto', '', null, gmdate('Y-m-d H:i:s'));
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*/*') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($this->dir . '/*') ?: [] as $sub) {
                @rmdir($sub);
            }
            @rmdir($this->dir);
        }
    }

    private function jpeg(): string
    {
        $im = imagecreatetruecolor(80, 60);
        ob_start();
        imagejpeg($im);

        return (string) ob_get_clean();
    }

    /** @return list<string> */
    private function files(): array
    {
        return glob($this->dir . '/*/*') ?: [];
    }

    public function testAttachStoresReencodedFileAndRow(): void
    {
        $id = $this->service->attach($this->itemId, $this->jpeg());

        $loaded = $this->service->load($id);
        self::assertNotNull($loaded);
        self::assertSame('image/jpeg', $loaded['mime']);
        self::assertSame([80, 60], array_slice((array) getimagesizefromstring($loaded['data']), 0, 2));
        self::assertCount(1, $this->files());
        self::assertSame(0600, fileperms($this->files()[0]) & 0777);
        self::assertSame([['id' => $id, 'width' => 80, 'height' => 60]], $this->service->forItem($this->itemId));
    }

    public function testInvalidImageLeavesNothingBehind(): void
    {
        try {
            $this->service->attach($this->itemId, 'kein bild');
            self::fail('Erwartet ImageException');
        } catch (ImageException) {
            self::assertSame([], $this->files());
            self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM item_media'));
        }
    }

    public function testDatabaseFailureRemovesStoredFile(): void
    {
        try {
            $this->service->attach(999999, $this->jpeg()); // Eintrag existiert nicht: Fremdschlüssel schlägt fehl
            self::fail('Erwartet Ausnahme');
        } catch (\PDOException) {
            self::assertSame([], $this->files(), 'keine verwaiste Datei');
        }
    }

    public function testDeletingItemRemovesRowsAndFilesCanBePurged(): void
    {
        $this->service->attach($this->itemId, $this->jpeg());
        $this->service->purgeFilesForItem($this->itemId);
        self::assertSame([], $this->files());

        (new ItemRepository($this->db))->delete($this->itemId);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM item_media'));
    }

    public function testStorageRejectsUnsafeNames(): void
    {
        $storage = new LocalMediaStorage($this->dir);
        foreach (['../etc/passwd', 'a/b', '', 'x', '..%2f', str_repeat('a', 36) . '.php', '../../' . str_repeat('a', 30)] as $name) {
            try {
                $storage->get($name);
                self::fail('Erwartet Ausnahme für ' . $name);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testLoadUnknownReturnsNull(): void
    {
        self::assertNull($this->service->load(12345));
        self::assertSame([], $this->service->idsForItems([]));
    }
}
