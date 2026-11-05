<?php

declare(strict_types=1);

namespace App\Notify;

use App\Calendar\CalendarService;
use App\Core\Config;
use App\Core\Container;
use App\Core\Contracts\ModuleInterface;
use App\Core\Contracts\NotificationChannelInterface;
use App\Core\Csrf;
use App\Core\CronJob;
use App\Core\Db;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Items\TaskService;

final class Module implements ModuleInterface
{
    public function services(Container $container): void
    {
        $container->set(PushSubscriptionRepository::class, fn (Container $c): PushSubscriptionRepository => new PushSubscriptionRepository($c->get(Db::class)));
        $container->set(SubscriptionService::class, function (Container $c): SubscriptionService {
            $extra = $c->get(Config::class)->get('push.allowed_hosts', []);

            return new SubscriptionService(
                $c->get(PushSubscriptionRepository::class),
                is_array($extra) ? array_values(array_filter($extra, 'is_string')) : [],
            );
        });
        $container->set(PushSender::class, function (Container $c): PushSender {
            $config = $c->get(Config::class);

            return new MinishlinkPushSender(
                $config->string('vapid.subject', 'mailto:admin@example.invalid'),
                $this->publicKey($config),
                $config->string('vapid.private_key'),
            );
        });
        $container->set(NotificationChannelInterface::class, fn (Container $c): NotificationChannelInterface => new WebPushChannel(
            $c->get(PushSubscriptionRepository::class),
            $c->get(PushSender::class),
        ));
        $container->set(ReminderService::class, fn (Container $c): ReminderService => new ReminderService(
            $c->get(TaskService::class),
            $c->get(CalendarService::class),
            $c->get(NotificationChannelInterface::class),
        ));
        $container->set(PushController::class, fn (Container $c): PushController => new PushController(
            $c->get(SubscriptionService::class),
            $c->get(PushSubscriptionRepository::class),
            $c->get(NotificationChannelInterface::class),
            $c->get(View::class),
            $c->get(Csrf::class),
            $c->get(Session::class),
            $this->publicKey($c->get(Config::class)),
        ));
    }

    public function routes(Router $router, Container $container): void
    {
        $c = static fn (): PushController => $container->get(PushController::class);
        $router->get('/einstellungen/benachrichtigungen', fn ($r, $p) => $c()->page($r, $p));
        $router->post('/einstellungen/benachrichtigungen/{id}/entfernen', fn ($r, $p) => $c()->remove($r, $p));
        $router->get('/api/v1/push/key', fn ($r, $p) => $c()->key($r, $p));
        $router->post('/api/v1/push/subscribe', fn ($r, $p) => $c()->subscribe($r, $p));
        $router->post('/api/v1/push/unsubscribe', fn ($r, $p) => $c()->unsubscribe($r, $p));
        $router->post('/api/v1/push/test', fn ($r, $p) => $c()->test($r, $p));
    }

    public function cronJobs(): array
    {
        return [
            new CronJob('notify.reminders', 15, static function (Container $c): void {
                $c->get(ReminderService::class)->run();
            }),
        ];
    }

    public function navItems(): array
    {
        return [];
    }

    private function publicKey(Config $config): string
    {
        $key = $config->string('vapid.public_key');

        return $key === '' || $key === 'replace-me' ? '' : $key;
    }
}
