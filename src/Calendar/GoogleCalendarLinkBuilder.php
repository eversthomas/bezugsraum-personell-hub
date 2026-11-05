<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Core\Contracts\EventLinkBuilderInterface;
use App\Core\PlannedEvent;

/** Google Kalender: Vorlage-Link („Termin hinzufügen“). */
final class GoogleCalendarLinkBuilder implements EventLinkBuilderInterface
{
    public function key(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google Kalender';
    }

    public function url(PlannedEvent $event): string
    {
        $utc = new \DateTimeZone('UTC');
        $query = http_build_query([
            'action' => 'TEMPLATE',
            'text' => $event->title,
            'dates' => $event->start->setTimezone($utc)->format('Ymd\THis\Z') . '/' . $event->end->setTimezone($utc)->format('Ymd\THis\Z'),
            'details' => $event->notes,
            'ctz' => 'Europe/Berlin',
        ], '', '&', PHP_QUERY_RFC3986);

        return 'https://calendar.google.com/calendar/render?' . $query;
    }
}
