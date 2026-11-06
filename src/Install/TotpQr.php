<?php

declare(strict_types=1);

namespace App\Install;

use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * SVG-QR-Code für die Authenticator-App, ohne GD.
 */
final class TotpQr
{
    public function dataUri(string $payload): string
    {
        $rendered = (new QRCode(new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => true,
            'svgAddXmlHeader' => false,
        ])))->render($payload);
        if (!is_string($rendered) || !str_starts_with($rendered, 'data:image/svg+xml;base64,')) {
            throw new \RuntimeException('Der QR-Code konnte nicht erzeugt werden.');
        }

        return $rendered;
    }
}
