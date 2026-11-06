<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Schreibt Dateien, die von Anfang an nur für den Besitzer lesbar sind.
 */
final class RestrictedFile
{
    public static function put(string $path, string $contents): void
    {
        if (!self::tryPut($path, $contents)) {
            throw new \RuntimeException('Die Datei konnte nicht geschrieben werden.');
        }
    }

    public static function tryPut(string $path, string $contents): bool
    {
        $previous = umask(0077);
        try {
            $handle = fopen($path, 'c');
            if ($handle === false) {
                return false;
            }
            try {
                if (!flock($handle, LOCK_EX)) {
                    return false;
                }
                chmod($path, 0600);
                ftruncate($handle, 0);
                rewind($handle);
                if (fwrite($handle, $contents) === false) {
                    return false;
                }
                fflush($handle);

                return true;
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        } finally {
            umask($previous);
        }
    }
}
