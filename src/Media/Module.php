<?php

declare(strict_types=1);

namespace App\Media;

use App\Core\Container;
use App\Core\Contracts\MediaStorageInterface;
use App\Core\Contracts\ModuleInterface;
use App\Core\Db;
use App\Core\Router;
use App\Items\ItemService;

final class Module implements ModuleInterface
{
    public function __construct(private readonly string $uploadDir = '')
    {
    }

    public function services(Container $container): void
    {
        $dir = $this->uploadDir !== '' ? $this->uploadDir : dirname(__DIR__, 2) . '/storage/uploads';
        $container->set(MediaStorageInterface::class, static fn (): MediaStorageInterface => new LocalMediaStorage($dir));
        $container->set(ImageProcessor::class, static fn (): ImageProcessor => new ImageProcessor());
        $container->set(MediaService::class, fn (Container $c): MediaService => new MediaService(
            $c->get(Db::class),
            $c->get(MediaStorageInterface::class),
            $c->get(ImageProcessor::class),
        ));
        $container->set(MediaController::class, fn (Container $c): MediaController => new MediaController(
            $c->get(MediaService::class),
            $c->get(ItemService::class),
        ));
    }

    public function routes(Router $router, Container $container): void
    {
        $c = static fn (): MediaController => $container->get(MediaController::class);
        $router->post('/api/v1/photos', fn ($r, $p) => $c()->upload($r, $p));
        $router->get('/media/{id}', fn ($r, $p) => $c()->show($r, $p));
    }

    public function cronJobs(): array
    {
        return [];
    }

    public function navItems(): array
    {
        return [];
    }
}
