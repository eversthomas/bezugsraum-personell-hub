<?php

declare(strict_types=1);

use App\Core\CronCredentials;
use App\Core\CronCredentialStore;

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
(new CronCredentialStore(dirname(__DIR__) . '/storage/cron-credentials.php'))->save($credentials);

echo "Cron-Zugang, einmal anzeigen. Der bisherige gilt sofort nicht mehr.\n";
echo "Gespeichert wurden nur die Hashes in storage/cron-credentials.php.\n";
echo "config/config.php bleibt unverändert.\n\n";
echo "Adresse:  {$credentials->url}\n";
echo "Benutzer: {$credentials->user}\n";
echo "Passwort: {$credentials->password}\n";
