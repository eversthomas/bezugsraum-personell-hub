<?php

declare(strict_types=1);

use App\Core\Db;
use App\Core\MigrationException;
use App\Core\Migrator;

$kernel = require __DIR__ . '/bootstrap.php';

$migrator = new Migrator($kernel->container()->get(Db::class), dirname(__DIR__) . '/migrations');
try {
    $applied = $migrator->migrate();
} catch (MigrationException $e) {
    $message = $e->busy
        ? "Aktualisierung läuft bereits.\n"
        : 'Die Datei ' . $e->filename . " konnte nicht eingespielt werden. Spiele die Sicherung der Datenbank ein.\n";
    fwrite(STDERR, $message);
    exit(1);
}

echo $applied === [] ? "Datenbank ist aktuell.\n" : 'Eingespielt: ' . implode(', ', $applied) . "\n";
