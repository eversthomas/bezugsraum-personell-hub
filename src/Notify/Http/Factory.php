<?php

declare(strict_types=1);

namespace App\Notify\Http;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

final class Factory implements RequestFactoryInterface, StreamFactoryInterface
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new Request($method, $uri instanceof UriInterface ? $uri : (string) $uri);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return new Stream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        $content = @file_get_contents($filename);
        if ($content === false) {
            throw new \RuntimeException('Datei nicht lesbar.');
        }

        return new Stream($content);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        $content = stream_get_contents($resource);

        return new Stream($content === false ? '' : $content);
    }
}
