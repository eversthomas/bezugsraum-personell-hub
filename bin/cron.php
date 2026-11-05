<?php

declare(strict_types=1);

use App\Core\Container;
use App\Core\Logger;

/** @var \App\Core\Kernel $kernel */
$kernel = require __DIR__ . '/bootstrap.php';
$container = $kernel->container();
$logger = $container->get(Logger::class);

// Ein Lauf zur Zeit: verhindert, dass sich langsame Läufe überlappen.
$lock = fopen(dirname(__DIR__) . '/storage/cache/cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

foreach ($kernel->cronJobs() as $job) {
    try {
        ($job->run)($container);
    } catch (Throwable $e) {
        $logger->error('cron.' . $job->name, $e);
    }
}
