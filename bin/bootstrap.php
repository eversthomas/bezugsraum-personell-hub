<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Kernel;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$kernel = new Kernel($root, Config::fromFile($root . '/config/config.php'));
$kernel->boot(require $root . '/config/modules.php');

return $kernel;
