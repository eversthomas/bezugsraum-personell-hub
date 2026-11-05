<?php

declare(strict_types=1);

/**
 * Aktive Module in Ladereihenfolge.
 */
return [
    App\Core\CoreModule::class,
    App\Auth\Module::class,
    App\Calendar\Module::class,
    App\Items\Module::class,
    App\Media\Module::class,
    App\Links\Module::class,
    App\Notify\Module::class,
];
