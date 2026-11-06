<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Prüft Bilder anhand ihres Inhalts und codiert sie neu als JPEG (max. 1600 px längste Seite).
 * Durch das Neucodieren verschwinden EXIF, Standort und alle anderen Metadaten.
 */
final class ImageProcessor
{
    public const MAX_BYTES = 12 * 1024 * 1024;
    public const MAX_PIXELS = 40_000_000;
    public const MAX_SIDE = 1600;
    public const QUALITY = 82;

    private const ALLOWED = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];

    /** @var callable(\GdImage, int, int, int): (\GdImage|false) */
    private $scale;

    /**
     * @param (callable(\GdImage, int, int, int): (\GdImage|false))|null $scale
     *        Ersatz für imagescale(), nur für Tests. Parameter: Bild, Breite, Höhe, Modus.
     */
    public function __construct(?callable $scale = null)
    {
        $this->scale = $scale ?? $this->gdScale(...);
    }

    /** @throws ImageException */
    public function process(string $binary): ProcessedImage
    {
        if ($binary === '' || strlen($binary) > self::MAX_BYTES) {
            throw new ImageException('Das Bild ist leer oder zu groß.');
        }
        $info = @getimagesizefromstring($binary);
        if ($info === false || !in_array($info[2], self::ALLOWED, true)) {
            throw new ImageException('Nur Bilder (JPEG, PNG, WebP, GIF) sind erlaubt.');
        }
        // Dekompressionsbomben: die Größe steht im Kopf und wird vor dem Dekodieren geprüft.
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new ImageException('Das Bild hat zu viele Pixel.');
        }

        $orientation = $info[2] === IMAGETYPE_JPEG ? $this->jpegOrientation($binary) : 1;
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            throw new ImageException('Das Bild konnte nicht gelesen werden.');
        }

        $image = $this->orient($image, $orientation);
        $image = $this->shrink($image);

        // Transparenz auf Weiß setzen, JPEG kennt keinen Alphakanal.
        $width = imagesx($image);
        $height = imagesy($image);
        $flat = imagecreatetruecolor($width, $height);
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);

        ob_start();
        imageinterlace($flat, true);
        imagejpeg($flat, null, self::QUALITY);
        $data = (string) ob_get_clean();

        return new ProcessedImage($data, 'image/jpeg', $width, $height);
    }

    private function shrink(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);
        if ($longest <= self::MAX_SIDE) {
            return $image;
        }
        $scale = self::MAX_SIDE / $longest;
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        // Manche GD-Builds liefern für IMG_BICUBIC false. Dann der einfachere Modus.
        // Ein Fehlschlag darf nie das Original durchlassen: die Kante bliebe über 1600 px.
        foreach ([IMG_BICUBIC, IMG_BILINEAR_FIXED] as $mode) {
            $scaled = ($this->scale)($image, $targetWidth, $targetHeight, $mode);
            if ($scaled instanceof \GdImage && max(imagesx($scaled), imagesy($scaled)) <= self::MAX_SIDE) {
                return $scaled;
            }
        }

        throw new ImageException('Das Bild konnte nicht verkleinert werden.');
    }

    private function gdScale(\GdImage $image, int $width, int $height, int $mode): \GdImage|false
    {
        return imagescale($image, $width, $height, $mode);
    }

    private function orient(\GdImage $image, int $orientation): \GdImage
    {
        $result = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $result === false ? $image : $result;
    }

    /** Liest nur die Ausrichtung (EXIF-Tag 0x0112) aus dem JPEG, ohne die exif-Erweiterung. */
    private function jpegOrientation(string $jpeg): int
    {
        $length = strlen($jpeg);
        $pos = 2;
        while ($pos + 4 < $length) {
            if ($jpeg[$pos] !== "\xFF") {
                break;
            }
            $marker = ord($jpeg[$pos + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break; // ab hier nur noch Bilddaten
            }
            $size = (ord($jpeg[$pos + 2]) << 8) | ord($jpeg[$pos + 3]);
            if ($marker === 0xE1 && substr($jpeg, $pos + 4, 6) === "Exif\0\0") {
                return $this->tiffOrientation(substr($jpeg, $pos + 10, max(0, $size - 8)));
            }
            $pos += 2 + $size;
        }

        return 1;
    }

    private function tiffOrientation(string $tiff): int
    {
        if (strlen($tiff) < 14) {
            return 1;
        }
        $little = substr($tiff, 0, 2) === 'II';
        if (!$little && substr($tiff, 0, 2) !== 'MM') {
            return 1;
        }
        $u16 = static fn (int $o): int => $o + 2 <= strlen($tiff) ? (int) unpack($little ? 'v' : 'n', substr($tiff, $o, 2))[1] : 0;
        $u32 = static fn (int $o): int => $o + 4 <= strlen($tiff) ? (int) unpack($little ? 'V' : 'N', substr($tiff, $o, 4))[1] : 0;

        $ifd = $u32(4);
        $entries = min($u16($ifd), 64);
        for ($i = 0; $i < $entries; ++$i) {
            $entry = $ifd + 2 + $i * 12;
            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }
}
