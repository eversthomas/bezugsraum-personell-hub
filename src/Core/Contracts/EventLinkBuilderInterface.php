<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Core\PlannedEvent;

interface EventLinkBuilderInterface
{
    /** Kurzname für Einstellungen und Formulare, z. B. "outlook". */
    public function key(): string;

    public function label(): string;

    /** Vorausgefüllter Link, der den Termin im Zielkalender zum Speichern öffnet. */
    public function url(PlannedEvent $event): string;
}
