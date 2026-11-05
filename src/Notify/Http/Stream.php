<?php

declare(strict_types=1);

namespace App\Notify\Http;

use Psr\Http\Message\StreamInterface;

/** PSR-7-Stream im Speicher (für kleine Push-Nachrichten). */
final class Stream implements StreamInterface
{
    private int $position = 0;

    public function __construct(private string $content = '')
    {
    }

    public function __toString(): string
    {
        return $this->content;
    }

    public function close(): void
    {
        $this->content = '';
        $this->position = 0;
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return strlen($this->content);
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= strlen($this->content);
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen($this->content) + $offset,
            default => throw new \RuntimeException('Ungültiger Seek-Modus.'),
        };
        if ($target < 0) {
            throw new \RuntimeException('Ungültige Position.');
        }
        $this->position = $target;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function write(string $string): int
    {
        $this->content = substr($this->content, 0, $this->position) . $string . substr($this->content, $this->position + strlen($string));
        $this->position += strlen($string);

        return strlen($string);
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $chunk = substr($this->content, $this->position, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $rest = substr($this->content, $this->position);
        $this->position = strlen($this->content);

        return $rest;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
