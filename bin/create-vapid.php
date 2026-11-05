<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/vendor/autoload.php';

$keys = Minishlink\WebPush\VAPID::createVapidKeys();

echo "VAPID-Schlüssel erzeugt. In config/config.php unter 'vapid' eintragen:\n\n";
echo "    'public_key' => '{$keys['publicKey']}',\n";
echo "    'private_key' => '{$keys['privateKey']}',\n\n";
echo "Den privaten Schlüssel geheim halten und mitsichern. Wird er getauscht, müssen alle Geräte neu aktiviert werden.\n";
