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
        // true nur hinter einem eigenen Proxy, der X-Forwarded-Proto setzt. Sonst bleibt der Header unbeachtet.
        'trust_proxy' => false,
    ],
    'crypto' => [
        'key_path' => __DIR__ . '/keys/app.key',
    ],
    'push' => [
        // Zusätzlich erlaubte Push-Dienste (Host-Endungen). Standard: Google, Mozilla, Apple, Microsoft.
        'allowed_hosts' => [],
    ],
    'cron' => [
        // Erzeugen mit: php bin/create-cron-credentials.php
        // Nur die Hashes eintragen, nie Token oder Passwort im Klartext.
        'token_hash' => '',
        'basic_user' => '',
        'basic_password_hash' => '',
    ],
    'vapid' => [
        'subject' => 'mailto:admin@example.invalid',
        'public_key' => 'replace-me', // erzeugen mit: php bin/create-vapid.php
        'private_key' => 'replace-me',
    ],
];
