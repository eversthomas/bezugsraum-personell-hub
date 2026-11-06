<?php

declare(strict_types=1);

use App\Core\CronRunner;

/** @var \App\Core\Kernel $kernel */
$kernel = require __DIR__ . '/bootstrap.php';
$runner = $kernel->container()->get(CronRunner::class);
$status = $runner->run();
if ($status !== CronRunner::STATUS_OK) {
    echo $runner->notice($status);
}
