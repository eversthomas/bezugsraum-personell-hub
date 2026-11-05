<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /**
     * @param array<string, string> $headers
     * @param list<array{name: string, value: string, options: array<string, mixed>}> $cookies
     */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public readonly array $headers = [],
        public readonly array $cookies = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            $status,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    /** Einheitliche, nichtssagende Antwort für alles Unbekannte oder Nichtberechtigte. */
    public static function notFound(): self
    {
        return new self(404, "Nicht gefunden\n", ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->body, [...$this->headers, $name => $value], $this->cookies);
    }

    /** @param array<string, mixed> $options expires, path, secure, httponly, samesite */
    public function withCookie(string $name, string $value, array $options): self
    {
        return new self(
            $this->status,
            $this->body,
            $this->headers,
            [...$this->cookies, ['name' => $name, 'value' => $value, 'options' => $options]],
        );
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        foreach ($this->cookies as $cookie) {
            setcookie($cookie['name'], $cookie['value'], $cookie['options']);
        }
        echo $this->body;
    }
}
