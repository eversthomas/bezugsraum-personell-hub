<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecurityHeaders;

$root = dirname(__DIR__);
header_remove('X-Powered-By');

try {
    require $root . '/vendor/autoload.php';

    if (!is_file($root . '/config/config.php')) {
        (new App\Install\InstallerApp($root))->handle(Request::fromGlobals())->send();

        return;
    }

    $kernel = new Kernel($root, Config::fromFile($root . '/config/config.php'));
    $kernel->boot(require $root . '/config/modules.php');
    $kernel->handle(Request::fromGlobals())->send();
} catch (Throwable) {
    // Nicht startfähig (z. B. Konfiguration fehlt): neutrale Antwort ohne Details.
    $fallback = new Response(500, "Interner Fehler\n", ['Content-Type' => 'text/plain; charset=utf-8']);
    if (class_exists(SecurityHeaders::class)) {
        $fallback = SecurityHeaders::apply($fallback, false);
    } else {
        header('X-Robots-Tag: noindex, nofollow, noarchive');
    }
    $fallback->send();
}
