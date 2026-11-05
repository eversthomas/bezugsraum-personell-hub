<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Crypto;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::fromFile(dirname(__DIR__) . '/config/config.php');
$path = $config->string('crypto.key_path');
if (is_file($path)) {
    fwrite(STDERR, "Schlüssel existiert bereits, nichts geändert.\n");
    exit(1);
}
if (!is_dir(dirname($path))) {
    mkdir(dirname($path), 0700, true);
}
file_put_contents($path, Crypto::generateKeyHex() . "\n");
chmod($path, 0600);
echo "Schlüssel angelegt. Unbedingt mitsichern: ohne ihn sind verschlüsselte Daten verloren.\n";
