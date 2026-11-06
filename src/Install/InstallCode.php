<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\RestrictedFile;

/**
 * Einrichtungscode außerhalb des Webroots. Fehlversuche zählen je IP.
 * Ein neuer Code entsteht, wenn der bisherige älter als 15 Minuten ist.
 */
final class InstallCode
{
    public const LENGTH = 20;
    public const MAX_FAILURES = 5;
    public const DELAY_SECONDS = 2;
    public const MAX_AGE_SECONDS = 900;

    /** Ohne 0, O, 1, I und L, damit der Code abtippbar bleibt. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @var \Closure(int): void */
    private \Closure $delay;

    public function __construct(
        private readonly string $codeFile,
        private readonly string $attemptFile,
        ?\Closure $clock = null,
        ?\Closure $delay = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->delay = $delay ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function ensure(): bool
    {
        if (is_file($this->codeFile) && $this->read() !== '' && !$this->isStale()) {
            return true;
        }

        return $this->regenerate();
    }

    /** ok, wrong oder renewed. */
    public function check(string $given, string $ip = ''): string
    {
        $stale = $this->isStale();
        if (!$this->ensure()) {
            return 'wrong';
        }
        if ($stale) {
            return 'renewed';
        }
        $expected = self::normalize($this->read());
        $input = self::normalize($given);
        if ($expected !== '' && $input !== '' && hash_equals($expected, $input)) {
            $this->clearIp($ip);

            return 'ok';
        }

        $count = $this->recordFailure($ip);
        if ($count >= self::MAX_FAILURES) {
            ($this->delay)(self::DELAY_SECONDS);
        }

        return 'wrong';
    }

    public function attempts(string $ip = ''): int
    {
        return $this->readCounts()[$this->ipHash($ip)] ?? 0;
    }

    public function discard(): void
    {
        if (is_file($this->codeFile)) {
            unlink($this->codeFile);
        }
        if (is_file($this->attemptFile)) {
            unlink($this->attemptFile);
        }
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

        return $this->clearCounts();
    }

    private function read(): string
    {
        if (!is_file($this->codeFile)) {
            return '';
        }

        return trim((string) file_get_contents($this->codeFile));
    }

    private function isStale(): bool
    {
        if (!is_file($this->codeFile)) {
            return false;
        }
        $modified = filemtime($this->codeFile);

        return $modified !== false && $this->now() - $modified >= self::MAX_AGE_SECONDS;
    }

    private function recordFailure(string $ip): int
    {
        $hash = $this->ipHash($ip);
        $count = 0;
        $this->withCounts(static function (array $counts) use ($hash, &$count): array {
            $count = ($counts[$hash] ?? 0) + 1;
            if ($count > self::MAX_FAILURES) {
                $count = self::MAX_FAILURES;
            }
            $counts[$hash] = $count;

            return $counts;
        });

        return $count;
    }

    private function clearIp(string $ip): void
    {
        $hash = $this->ipHash($ip);
        $this->withCounts(static function (array $counts) use ($hash): array {
            unset($counts[$hash]);

            return $counts;
        });
    }

    private function clearCounts(): bool
    {
        return $this->withCounts(static fn (array $counts): array => []);
    }

    /** @return array<string, int> */
    private function readCounts(): array
    {
        $counts = [];
        $this->withCounts(static function (array $existing) use (&$counts): array {
            $counts = $existing;

            return $existing;
        });

        return $counts;
    }

    /**
     * @param \Closure(array<string, int>): array<string, int> $change
     */
    private function withCounts(\Closure $change): bool
    {
        $dir = dirname($this->attemptFile);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        if (!is_writable($dir)) {
            return false;
        }
        $previous = umask(0077);
        try {
            $handle = fopen($this->attemptFile, 'c+');
            if ($handle === false) {
                return false;
            }
            try {
                if (!flock($handle, LOCK_EX)) {
                    return false;
                }
                chmod($this->attemptFile, 0600);
                $raw = stream_get_contents($handle);
                $updated = $change($this->decodeCounts(is_string($raw) ? $raw : ''));
                $encoded = json_encode($updated);
                if ($encoded === false) {
                    return false;
                }
                rewind($handle);
                if (!ftruncate($handle, 0)) {
                    return false;
                }
                if (fwrite($handle, $encoded) === false) {
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

    /** @return array<string, int> */
    private function decodeCounts(string $raw): array
    {
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            return [];
        }
        $counts = [];
        foreach ($parsed as $hash => $count) {
            if (is_string($hash) && is_int($count) && $count > 0) {
                $counts[$hash] = $count;
            }
        }

        return $counts;
    }

    private function ipHash(string $ip): string
    {
        return hash('sha256', $ip);
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    private function writeFile(string $path, string $contents): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        if (!is_writable($dir)) {
            return false;
        }

        return RestrictedFile::tryPut($path, $contents);
    }
}
