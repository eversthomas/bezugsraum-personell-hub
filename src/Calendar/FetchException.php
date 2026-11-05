<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Abruffehler mit Meldung, die ohne Bedenken angezeigt und gespeichert werden darf
 * (nie die URL oder Inhalte enthält).
 */
final class FetchException extends \RuntimeException
{
}
