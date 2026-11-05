<?php

declare(strict_types=1);

namespace App\Notify;

use App\Calendar\CalendarService;
use App\Core\Contracts\NotificationChannelInterface;
use App\Core\Notification;
use App\Core\Time;
use App\Items\TaskService;

/**
 * Cronjob-Teil: sammelt fällige Erinnerungen aus Aufgaben und Terminen und verschickt sie.
 * Die Module beanspruchen jede Erinnerung genau einmal; scheitert der Versand komplett, wird sie freigegeben.
 */
final class ReminderService
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly CalendarService $calendar,
        private readonly NotificationChannelInterface $channel,
    ) {
    }

    /** @return int Anzahl verschickter Erinnerungen */
    public function run(): int
    {
        $sent = 0;
        foreach ($this->tasks->claimDueReminders() as $claim) {
            $item = $claim['item'];
            $report = $this->channel->send(new Notification(
                'Aufgabe fällig',
                $item->title,
                '/aufgaben',
                'task-' . $item->id,
            ));
            if ($report->failedEntirely()) {
                $this->tasks->releaseReminder($item->id, $claim['remindAt']);
                continue;
            }
            ++$sent;
        }

        foreach ($this->calendar->claimDueEventReminders() as $claim) {
            $start = Time::berlin($claim['starts_at'], 'H:i');
            $report = $this->channel->send(new Notification(
                'Gleich: ' . $claim['title'],
                trim($start . ' Uhr' . ($claim['location'] !== '' ? ' · ' . $claim['location'] : '') . ' · ' . $claim['source']),
                '/',
                'event-' . substr($claim['uid_hash'], 0, 12),
            ));
            if ($report->failedEntirely()) {
                $this->calendar->releaseEventReminder($claim['source_id'], $claim['uid_hash'], $claim['starts_at']);
                continue;
            }
            ++$sent;
        }

        return $sent;
    }
}
