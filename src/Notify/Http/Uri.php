<?php

declare(strict_types=1);

namespace App\Notify\Http;

use Psr\Http\Message\UriInterface;

/** Minimale, unveränderliche PSR-7-URI. */
final class Uri implements UriInterface
{
    private string $scheme = '';
    private string $userInfo = '';
    private string $host = '';
    private ?int $port = null;
    private string $path = '';
    private string $query = '';
    private string $fragment = '';

    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            return;
        }
        $parts = parse_url($uri);
        if ($parts === false) {
            throw new \InvalidArgumentException('Ungültige URI.');
        }
        $this->scheme = strtolower($parts['scheme'] ?? '');
        $this->userInfo = ($parts['user'] ?? '') . (isset($parts['pass']) ? ':' . $parts['pass'] : '');
        $this->host = strtolower($parts['host'] ?? '');
        $this->port = $parts['port'] ?? null;
        $this->path = $parts['path'] ?? '';
        $this->query = $parts['query'] ?? '';
        $this->fragment = $parts['fragment'] ?? '';
        $this->port = $this->normalizePort($this->port);
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        return ($this->userInfo !== '' ? $this->userInfo . '@' : '') . $this->host . ($this->port !== null ? ':' . $this->port : '');
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function withScheme(string $scheme): UriInterface
    {
        $new = clone $this;
        $new->scheme = strtolower($scheme);
        $new->port = $new->normalizePort($new->port);

        return $new;
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $new = clone $this;
        $new->userInfo = $user . ($password !== null && $password !== '' ? ':' . $password : '');

        return $new;
    }

    public function withHost(string $host): UriInterface
    {
        $new = clone $this;
        $new->host = strtolower($host);

        return $new;
    }

    public function withPort(?int $port): UriInterface
    {
        $new = clone $this;
        $new->port = $new->normalizePort($port);

        return $new;
    }

    public function withPath(string $path): UriInterface
    {
        $new = clone $this;
        $new->path = $path;

        return $new;
    }

    public function withQuery(string $query): UriInterface
    {
        $new = clone $this;
        $new->query = ltrim($query, '?');

        return $new;
    }

    public function withFragment(string $fragment): UriInterface
    {
        $new = clone $this;
        $new->fragment = ltrim($fragment, '#');

        return $new;
    }

    public function __toString(): string
    {
        $uri = '';
        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }
        $authority = $this->getAuthority();
        if ($authority !== '') {
            $uri .= '//' . $authority;
        }
        $uri .= $this->path;
        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }
        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }

    private function normalizePort(?int $port): ?int
    {
        $default = ['http' => 80, 'https' => 443][$this->scheme] ?? null;

        return $port === $default ? null : $port;
    }
}
