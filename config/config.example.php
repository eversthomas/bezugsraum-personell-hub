<?php

declare(strict_types=1);

/**
 * Beispielkonfiguration. Nach config/config.php kopieren und die Platzhalter setzen.
 * config.php wird nicht versioniert.
 */

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'personal_hub',
        'user' => 'personal_hub',
        'password' => 'change-me',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'url' => 'https://hub.example.invalid',
        'timezone' => 'Europe/Berlin',
    ],
    'crypto' => [
        'key_path' => __DIR__ . '/keys/app.key',
    ],
    'vapid' => [
        'subject' => 'mailto:admin@example.invalid',
        'public_key' => 'replace-me',
        'private_key' => 'replace-me',
    ],
];
