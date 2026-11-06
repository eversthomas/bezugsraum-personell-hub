<?php

declare(strict_types=1);

use App\Core\CronRunner;

/** @var \App\Core\Kernel $kernel */
$kernel = require __DIR__ . '/bootstrap.php';
$kernel->container()->get(CronRunner::class)->run();
