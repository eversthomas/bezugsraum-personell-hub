<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Core\Container;
use App\Core\CronJob;
use App\Core\NavItem;
use App\Core\Router;

interface ModuleInterface
{
    public function routes(Router $router, Container $container): void;

    public function services(Container $container): void;

    /** @return list<CronJob> */
    public function cronJobs(): array;

    /** @return list<NavItem> */
    public function navItems(): array;
}
