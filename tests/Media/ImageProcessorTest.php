<?php

declare(strict_types=1);

namespace Tests\Media;

use App\Media\ImageException;
use App\Media\ImageProcessor;
use PHPUnit\Framework\TestCase;

final class ImageProcessorTest extends TestCase
{
    private function jpeg(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, (int) imagecolorallocate($im, 200, 50, 50));
        imagefilledrectangle($im, 0, 0, intdiv($w, 2), intdiv($h, 2), (int) imagecolorallocate($im, 20, 20, 200));
        ob_start();
        imagejpeg($im, null, 90);

        return (string) ob_get_clean();
    }

    /** Fügt ein EXIF-Segment mit Ausrichtung und einem (erfundenen) Standort ein. */
    private function withExif(string $jpeg, int $orientation = 1): string
    {
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00" . "\x00\x00\x00\x00";
        $payload = "Exif\x00\x00" . $tiff . 'GPSLatitude=52.5200N GPSLongitude=13.4050E Make=Pixel';
        $segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        return substr($jpeg, 0, 2) . $segment . substr($jpeg, 2);
    }

    private function dimensions(string $binary): array
    {
        $info = getimagesizefromstring($binary);
        self::assertNotFalse($info);

        return [$info[0], $info[1]];
    }

    public function testExifAndLocationAreRemoved(): void
    {
        $input = $this->withExif($this->jpeg(400, 300));
        self::assertStringContainsString('GPSLatitude', $input, 'Testbild enthält Standort');

        $result = (new ImageProcessor())->process($input);

        self::assertSame('image/jpeg', $result->mime);
        self::assertStringNotContainsString('GPSLatitude', $result->data);
        self::assertStringNotContainsString('Exif', $result->data);
        self::assertStringNotContainsString('Pixel', $result->data);
        self::assertSame("\xFF\xD8", substr($result->data, 0, 2));
        self::assertSame([400, 300], $this->dimensions($result->data));
    }

    public function testNoMetadataSegmentsRemainAfterReencode(): void
    {
        $result = (new ImageProcessor())->process($this->withExif($this->jpeg(100, 100)));

        $markers = [];
        for ($pos = 2; $pos + 4 < strlen($result->data) && ord($result->data[$pos + 1]) !== 0xDA; $pos += 2 + ((ord($result->data[$pos + 2]) << 8) | ord($result->data[$pos + 3]))) {
            $markers[] = ord($result->data[$pos + 1]);
        }
        self::assertNotContains(0xE1, $markers, 'kein APP1 (EXIF/XMP)');
    }

    public function testLargeImageIsScaledToMaxSideKeepingAspect(): void
    {
        $result = (new ImageProcessor())->process($this->jpeg(3200, 2400));

        self::assertSame([1600, 1200], $this->dimensions($result->data));
        self::assertSame(1600, $result->width);
        self::assertSame(1200, $result->height);
    }

    public function testSmallImageIsNotUpscaled(): void
    {
        self::assertSame([320, 200], $this->dimensions((new ImageProcessor())->process($this->jpeg(320, 200))->data));
    }

    public function testOrientationIsAppliedBeforeMetadataIsDropped(): void
    {
        $result = (new ImageProcessor())->process($this->withExif($this->jpeg(200, 100), 6));

        self::assertSame([100, 200], $this->dimensions($result->data), 'Hochformat nach Drehung');
    }

    public function testPngWithTransparencyBecomesJpeg(): void
    {
        $im = imagecreatetruecolor(50, 50);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();

        $result = (new ImageProcessor())->process($png);

        self::assertSame('image/jpeg', $result->mime);
        $out = imagecreatefromstring($result->data);
        self::assertNotFalse($out);
        $rgb = imagecolorat($out, 25, 25);
        self::assertGreaterThan(240, ($rgb >> 16) & 0xFF, 'transparent wird weiß');
    }

    public function testPhpCodeAppendedToImageIsNotKept(): void
    {
        $evil = $this->jpeg(60, 60) . '<?php system($_GET["c"]); ?>';
        $result = (new ImageProcessor())->process($evil);

        self::assertStringNotContainsString('<?php', $result->data);
        self::assertStringNotContainsString('system', $result->data);
    }

    /** @return array<string, array{string}> */
    public static function invalid(): array
    {
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 20000, 20000) . "\x08\x06\x00\x00\x00" . pack('N', 0);

        return [
            'leer' => [''],
            'Text' => ['das ist kein Bild'],
            'PHP' => ['<?php echo 1;'],
            'SVG' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'HTML' => ['<html><img src=x onerror=alert(1)>'],
            'zu viele Pixel' => [$png],
            'abgeschnitten' => ["\xFF\xD8\xFF\xE0" . str_repeat("\x00", 10)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalidInputIsRejected(string $binary): void
    {
        $this->expectException(ImageException::class);
        (new ImageProcessor())->process($binary);
    }

    public function testTooLargeFileIsRejected(): void
    {
        $this->expectException(ImageException::class);
        (new ImageProcessor())->process($this->jpeg(10, 10) . str_repeat('A', ImageProcessor::MAX_BYTES));
    }
}
