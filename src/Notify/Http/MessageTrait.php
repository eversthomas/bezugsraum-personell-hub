<?php

declare(strict_types=1);

namespace App\Notify\Http;

use Psr\Http\Message\StreamInterface;

/** Gemeinsamer Teil von Anfrage und Antwort (Header, Version, Inhalt). */
trait MessageTrait
{
    private string $protocol = '1.1';
    /** @var array<string, list<string>> Schlüssel kleingeschrieben */
    private array $headers = [];
    /** @var array<string, string> kleingeschriebener Name => Originalschreibweise */
    private array $headerNames = [];
    private StreamInterface $body;

    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    public function withProtocolVersion(string $version): static
    {
        $new = clone $this;
        $new->protocol = $version;

        return $new;
    }

    /** @return array<string, list<string>> */
    public function getHeaders(): array
    {
        $result = [];
        foreach ($this->headers as $lower => $values) {
            $result[$this->headerNames[$lower]] = $values;
        }

        return $result;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /** @return list<string> */
    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): static
    {
        $this->assertHeader($name);
        $new = clone $this;
        $lower = strtolower($name);
        $new->headers[$lower] = $this->normalizeValues($value);
        $new->headerNames[$lower] = $name;

        return $new;
    }

    public function withAddedHeader(string $name, $value): static
    {
        $this->assertHeader($name);
        $new = clone $this;
        $lower = strtolower($name);
        $new->headers[$lower] = [...($new->headers[$lower] ?? []), ...$this->normalizeValues($value)];
        $new->headerNames[$lower] ??= $name;

        return $new;
    }

    public function withoutHeader(string $name): static
    {
        $new = clone $this;
        unset($new->headers[strtolower($name)], $new->headerNames[strtolower($name)]);

        return $new;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): static
    {
        $new = clone $this;
        $new->body = $body;

        return $new;
    }

    /** @return list<string> */
    private function normalizeValues(mixed $value): array
    {
        $values = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            $text = trim((string) $item);
            if (preg_match('/[\r\n\0]/', $text) === 1) {
                throw new \InvalidArgumentException('Ungültiger Header-Wert.');
            }
            $values[] = $text;
        }

        return $values;
    }

    private function assertHeader(string $name): void
    {
        if (preg_match('/^[A-Za-z0-9\'`#$%&*+.^_|~!-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('Ungültiger Header-Name.');
        }
    }
}
