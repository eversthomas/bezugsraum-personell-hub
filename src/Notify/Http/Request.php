<?php

declare(strict_types=1);

namespace App\Notify\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

final class Request implements RequestInterface
{
    use MessageTrait;

    private string $method;
    private UriInterface $uri;
    private ?string $requestTarget = null;

    public function __construct(string $method, UriInterface|string $uri)
    {
        if (preg_match('/^[A-Za-z]+$/', $method) !== 1) {
            throw new \InvalidArgumentException('Ungültige HTTP-Methode.');
        }
        $this->method = strtoupper($method);
        $this->uri = $uri instanceof UriInterface ? $uri : new Uri($uri);
        $this->body = new Stream();
        if ($this->uri->getHost() !== '') {
            $this->headers['host'] = [$this->uri->getHost() . ($this->uri->getPort() !== null ? ':' . $this->uri->getPort() : '')];
            $this->headerNames['host'] = 'Host';
        }
    }

    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }
        $target = $this->uri->getPath() === '' ? '/' : $this->uri->getPath();

        return $this->uri->getQuery() === '' ? $target : $target . '?' . $this->uri->getQuery();
    }

    public function withRequestTarget(string $requestTarget): static
    {
        $new = clone $this;
        $new->requestTarget = $requestTarget;

        return $new;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): static
    {
        $new = clone $this;
        $new->method = strtoupper($method);

        return $new;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        $new = clone $this;
        $new->uri = $uri;
        if (!$preserveHost || !$new->hasHeader('host')) {
            if ($uri->getHost() !== '') {
                $new->headers['host'] = [$uri->getHost() . ($uri->getPort() !== null ? ':' . $uri->getPort() : '')];
                $new->headerNames['host'] = 'Host';
            }
        }

        return $new;
    }
}
