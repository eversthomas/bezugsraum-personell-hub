<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $headers  Schlüssel kleingeschrieben
     * @param array<string, string> $cookies
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly array $server = [],
        public readonly string $body = '',
        /** @var array<string, array{tmp_name: string, size: int, error: int}> */
        public readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        if ($path === '' || $path[0] !== '/') {
            $path = '/';
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $src => $dst) {
            if (isset($_SERVER[$src]) && is_string($_SERVER[$src])) {
                $headers[$dst] = $_SERVER[$src];
            }
        }

        /** @var array<string, string> $query */
        $query = array_filter($_GET, 'is_string');
        /** @var array<string, string> $cookies */
        $cookies = array_filter($_COOKIE, 'is_string');
        $body = (string) file_get_contents('php://input');

        // Nur tatsächlich hochgeladene Dateien übernehmen (kein Verweis auf fremde Pfade).
        $files = [];
        foreach ($_FILES as $name => $file) {
            if (is_array($file) && is_string($file['tmp_name'] ?? null) && is_uploaded_file($file['tmp_name'])) {
                $files[(string) $name] = [
                    'tmp_name' => $file['tmp_name'],
                    'size' => (int) ($file['size'] ?? 0),
                    'error' => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
                ];
            }
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $query,
            $_POST,
            $headers,
            $cookies,
            $_SERVER,
            $body,
            $files,
        );
    }

    /** @return array{tmp_name: string, size: int, error: int}|null */
    public function file(string $name): ?array
    {
        $file = $this->files[$name] ?? null;

        return $file !== null && $file['error'] === UPLOAD_ERR_OK ? $file : null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function input(string $name, string $default = ''): string
    {
        $value = $this->post[$name] ?? null;
        if ($value === null) {
            $json = $this->json();
            $value = $json[$name] ?? null;
        }

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if (!str_contains((string) $this->header('content-type'), 'application/json') || $this->body === '') {
            return [];
        }
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : [];
    }

    /** $trustProxy nur setzen, wenn ein eigener Proxy X-Forwarded-Proto zuverlässig setzt. */
    public function isHttps(bool $trustProxy = false): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        if (!$trustProxy) {
            return false;
        }

        return ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public function ip(): string
    {
        $ip = $this->server['REMOTE_ADDR'] ?? '';

        return is_string($ip) ? $ip : '';
    }

    public function userAgent(): string
    {
        return $this->header('user-agent') ?? '';
    }

    public function wantsJson(): bool
    {
        return str_starts_with($this->path, '/api/')
            || str_contains((string) $this->header('accept'), 'application/json');
    }

    public function isMutating(): bool
    {
        return !in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }
}
