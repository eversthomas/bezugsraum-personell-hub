<?php

declare(strict_types=1);

namespace App\Install;

/**
 * Einrichtungscode außerhalb des Webroots. Fünf Fehlversuche erzeugen einen neuen Code.
 */
final class InstallCode
{
    public const LENGTH = 20;
    public const MAX_FAILURES = 5;

    /** Ohne 0, O, 1, I und L, damit der Code abtippbar bleibt. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly string $codeFile,
        private readonly string $attemptFile,
    ) {
    }

    public function ensure(): bool
    {
        if (is_file($this->codeFile) && $this->read() !== '') {
            return true;
        }

        return $this->regenerate();
    }

    /** ok, wrong oder renewed. */
    public function check(string $given): string
    {
        if (!$this->ensure()) {
            return 'wrong';
        }
        $expected = self::normalize($this->read());
        $input = self::normalize($given);
        if ($expected !== '' && $input !== '' && hash_equals($expected, $input)) {
            $this->writeAttempts(0);

            return 'ok';
        }

        $count = $this->attempts() + 1;
        if ($count >= self::MAX_FAILURES) {
            $this->regenerate();

            return 'renewed';
        }
        $this->writeAttempts($count);

        return 'wrong';
    }

    public function attempts(): int
    {
        if (!is_file($this->attemptFile)) {
            return 0;
        }
        $value = trim((string) file_get_contents($this->attemptFile));

        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : 0;
    }

    public static function normalize(string $value): string
    {
        return (string) preg_replace('/[^A-Z2-9]/', '', strtoupper($value));
    }

    private function regenerate(): bool
    {
        $chars = '';
        $size = strlen(self::ALPHABET);
        for ($i = 0; $i < self::LENGTH; $i++) {
            $chars .= self::ALPHABET[random_int(0, $size - 1)];
        }
        $grouped = implode('-', str_split($chars, 4));
        if (!$this->writeFile($this->codeFile, $grouped . "\n")) {
            return false;
        }

        return $this->writeAttempts(0);
    }

    private function read(): string
    {
        if (!is_file($this->codeFile)) {
            return '';
        }

        return trim((string) file_get_contents($this->codeFile));
    }

    private function writeAttempts(int $count): bool
    {
        return $this->writeFile($this->attemptFile, (string) $count);
    }

    private function writeFile(string $path, string $contents): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        $handle = fopen($path, 'c');
        if ($handle === false) {
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            ftruncate($handle, 0);
            rewind($handle);
            if (fwrite($handle, $contents) === false) {
                return false;
            }
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        chmod($path, 0600);

        return true;
    }
}
