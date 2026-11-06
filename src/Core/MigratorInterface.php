<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Vorwärts gerichtete Schemaänderungen.
 */
interface MigratorInterface
{
    /** @return list<string> Dateinamen, die noch nicht eingespielt sind */
    public function pending(): array;

    /**
     * @return list<string> Namen der neu eingespielten Migrationen
     * @throws MigrationException
     */
    public function migrate(): array;
}
