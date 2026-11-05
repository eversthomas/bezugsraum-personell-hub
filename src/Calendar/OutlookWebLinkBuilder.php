<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Core\Contracts\EventLinkBuilderInterface;
use App\Core\PlannedEvent;

/**
 * Outlook im Web (Microsoft 365). Der Link öffnet das Formular „Neuer Termin“ mit ausgefüllten Feldern;
 * gespeichert wird im Konto, mit dem der Browser dort angemeldet ist.
 */
final class OutlookWebLinkBuilder implements EventLinkBuilderInterface
{
    public function __construct(private readonly string $host = 'outlook.office.com')
    {
    }

    public function key(): string
    {
        return 'outlook';
    }

    public function label(): string
    {
        return 'Outlook im Web';
    }

    public function url(PlannedEvent $event): string
    {
        $query = http_build_query([
            'path' => '/calendar/action/compose',
            'rru' => 'addevent',
            'subject' => $event->title,
            'startdt' => $event->start->format('Y-m-d\TH:i:sP'),
            'enddt' => $event->end->format('Y-m-d\TH:i:sP'),
            'body' => $event->notes,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'https://' . $this->host . '/calendar/0/deeplink/compose?' . $query;
    }
}
