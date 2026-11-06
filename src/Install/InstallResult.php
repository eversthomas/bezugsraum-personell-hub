<?php

declare(strict_types=1);

namespace App\Install;

/**
 * Einmalige Abschlussangaben. Sie stehen nur in der Antwort, nicht in der Sitzung.
 */
final class InstallResult
{
    public function __construct(
        public readonly string $username,
        public readonly string $cronUrl,
        public readonly string $cronUser,
        public readonly string $cronPassword,
    ) {
    }
}
