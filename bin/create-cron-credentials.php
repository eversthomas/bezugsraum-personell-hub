<?php

declare(strict_types=1);

use App\Core\CronCredentials;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$appUrl = 'https://hub.example.invalid';
$configPath = dirname(__DIR__) . '/config/config.php';
if (is_file($configPath)) {
    $values = require $configPath;
    if (is_array($values) && is_string($values['app']['url'] ?? null) && $values['app']['url'] !== '') {
        $appUrl = $values['app']['url'];
    }
}

$credentials = CronCredentials::generate($appUrl);

echo "Cron-Zugang, einmal anzeigen. Danach nur die Hashes in config/config.php eintragen.\n";
echo "Die Datei wird von diesem Befehl nicht verändert.\n\n";
echo "Adresse:  {$credentials->url}\n";
echo "Benutzer: {$credentials->user}\n";
echo "Passwort: {$credentials->password}\n\n";
echo "    'cron' => [\n";
echo "        'token_hash' => '{$credentials->tokenHash}',\n";
echo "        'basic_user' => '{$credentials->user}',\n";
echo "        'basic_password_hash' => '{$credentials->passwordHash}',\n";
echo "    ],\n";
