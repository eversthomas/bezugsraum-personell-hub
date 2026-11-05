<?php

declare(strict_types=1);

use App\Core\Db;
use App\Core\Migrator;

$kernel = require __DIR__ . '/bootstrap.php';

$migrator = new Migrator($kernel->container()->get(Db::class), dirname(__DIR__) . '/migrations');
$applied = $migrator->migrate();

echo $applied === [] ? "Datenbank ist aktuell.\n" : 'Eingespielt: ' . implode(', ', $applied) . "\n";
