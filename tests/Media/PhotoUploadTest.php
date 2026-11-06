<?php

declare(strict_types=1);

namespace Tests\Media;

use App\Auth\UserRepository;
use App\Core\Config;
use App\Core\Contracts\MediaStorageInterface;
use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Media\LocalMediaStorage;
use Tests\DbTestCase;

final class PhotoUploadTest extends DbTestCase
{
    private const UUID = '3f2b8c1e-7a4d-4e0b-9c6a-1d2e3f4a5b6c';

    private Kernel $kernel;
    private Session $session;
    private string $keyFile;
    private string $uploads;
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->keyFile = tempnam(sys_get_temp_dir(), 'phkey') ?: '';
        file_put_contents($this->keyFile, Crypto::generateKeyHex());
        $this->uploads = sys_get_temp_dir() . '/phup' . bin2hex(random_bytes(4));
        $this->session = new Session(null, true);
        $this->kernel = new Kernel(dirname(__DIR__, 2), new Config([
            'app' => ['url' => 'https://hub.example.invalid'],
            'crypto' => ['key_path' => $this->keyFile],
        ]));
        $c = $this->kernel->container();
        $c->instance(Session::class, $this->session);
        $c->instance(Db::class, $this->db);
        $c->instance(MediaStorageInterface::class, new LocalMediaStorage($this->uploads));
        $this->kernel->boot(require dirname(__DIR__, 2) . '/config/modules.php');
    }

    protected function tearDown(): void
    {
        if (isset($this->keyFile)) {
            @unlink($this->keyFile);
        }
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        if (!isset($this->uploads)) {
            return;
        }
        foreach (glob($this->uploads . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->uploads . '/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->uploads);
    }

    private function login(): void
    {
        $c = $this->kernel->container();
        $id = $c->get(UserRepository::class)->create('thomas', 'x', $c->get(Crypto::class)->encrypt('S'), gmdate('Y-m-d H:i:s'));
        $this->session->set('uid', $id);
    }

    private function jpegWithGps(): string
    {
        $im = imagecreatetruecolor(300, 200);
        ob_start();
        imagejpeg($im);
        $jpeg = (string) ob_get_clean();
        $payload = "Exif\x00\x00MM\x00\x2A\x00\x00\x00\x08\x00\x00\x00\x00\x00\x00GPSLatitude=52.5200N GPSLongitude=13.4050E";

        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }

    /** @param array<string, mixed> $post */
    private function upload(string $binary, array $post = [], bool $csrf = true, string $field = 'photo'): Response
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phupl') ?: '';
        file_put_contents($tmp, $binary);
        $this->tmpFiles[] = $tmp;
        if ($csrf) {
            $post['_csrf'] = $this->kernel->container()->get(Csrf::class)->token();
        }

        return $this->kernel->handle(new Request(
            'POST',
            '/api/v1/photos',
            post: $post,
            server: ['HTTPS' => 'on'],
            files: [$field => ['tmp_name' => $tmp, 'size' => strlen($binary), 'error' => UPLOAD_ERR_OK]],
        ));
    }

    private function get(string $path): Response
    {
        return $this->kernel->handle(new Request('GET', $path, server: ['HTTPS' => 'on']));
    }

    public function testUploadRequiresLoginAndCsrf(): void
    {
        self::assertSame(404, $this->upload($this->jpegWithGps())->status);

        $this->login();
        self::assertSame(403, $this->upload($this->jpegWithGps(), [], false)->status);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM items'));
    }

    public function testStoredAndServedPhotoContainsNoLocationData(): void
    {
        $this->login();
        $input = $this->jpegWithGps();
        self::assertStringContainsString('GPSLatitude', $input);

        $response = $this->upload($input, ['text' => 'Whiteboard', 'client_uuid' => self::UUID]);
        self::assertSame(201, $response->status);

        $item = $this->db->fetchOne('SELECT * FROM items');
        self::assertSame('photo', $item['origin']);
        self::assertSame('Whiteboard', $item['title']);
        $mediaId = (int) $this->db->fetchValue('SELECT id FROM item_media');

        $served = $this->get('/media/' . $mediaId);
        self::assertSame(200, $served->status);
        self::assertSame('image/jpeg', $served->headers['Content-Type']);
        self::assertStringNotContainsString('GPS', $served->body);
        self::assertStringNotContainsString('Exif', $served->body);

        $onDisk = file_get_contents((string) glob($this->uploads . '/*/*')[0]);
        self::assertStringNotContainsString('GPSLatitude', (string) $onDisk, 'auch auf der Platte kein Standort');
    }

    public function testMediaIsOnlyServedAfterLogin(): void
    {
        $this->login();
        $this->upload($this->jpegWithGps());
        $mediaId = (int) $this->db->fetchValue('SELECT id FROM item_media');
        $this->kernel->container()->get(\App\Auth\AuthService::class)->logout();

        self::assertSame(404, $this->get('/media/' . $mediaId)->status);
    }

    public function testMediaUnknownIdIs404(): void
    {
        $this->login();

        self::assertSame(404, $this->get('/media/99999')->status);
        self::assertSame(404, $this->get('/media/abc')->status);
    }

    public function testSameClientUuidStoresPhotoOnce(): void
    {
        $this->login();
        $first = $this->upload($this->jpegWithGps(), ['client_uuid' => self::UUID]);
        $second = $this->upload($this->jpegWithGps(), ['client_uuid' => self::UUID]);

        self::assertSame(201, $first->status);
        self::assertSame(200, $second->status);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM items'));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM item_media'));
        self::assertCount(1, glob($this->uploads . '/*/*') ?: []);
    }

    public function testInvalidFilesAreRejectedWithoutCreatingAnything(): void
    {
        $this->login();
        foreach (['<?php system("id");', '<svg xmlns="http://www.w3.org/2000/svg"/>', 'GIF89a' . str_repeat("\x00", 5), ''] as $content) {
            self::assertSame(422, $this->upload($content)->status);
        }
        self::assertSame(422, $this->upload($this->jpegWithGps(), [], true, 'falsch')->status, 'Feld "photo" fehlt');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM items'));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM item_media'));
    }

    public function testInboxShowsPhotoAndDeletingRemovesFile(): void
    {
        $this->login();
        $this->upload($this->jpegWithGps(), ['text' => 'Foto-Notiz']);
        $mediaId = (int) $this->db->fetchValue('SELECT id FROM item_media');
        $itemId = (int) $this->db->fetchValue('SELECT id FROM items');

        self::assertStringContainsString('src="/media/' . $mediaId . '"', $this->get('/inbox')->body);

        $this->kernel->handle(new Request('POST', "/inbox/$itemId/loeschen", post: ['_csrf' => $this->kernel->container()->get(Csrf::class)->token()], server: ['HTTPS' => 'on']));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM item_media'));
        self::assertSame([], glob($this->uploads . '/*/*') ?: [], 'Datei wurde mit gelöscht');
    }
}
