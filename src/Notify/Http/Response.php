<?php

declare(strict_types=1);

namespace App\Notify\Http;

use Psr\Http\Message\ResponseInterface;

final class Response implements ResponseInterface
{
    use MessageTrait;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(private int $status = 200, array $headers = [], string $body = '', private string $reason = '')
    {
        $this->body = new Stream($body);
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = [$value];
            $this->headerNames[strtolower($name)] = $name;
        }
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $new = clone $this;
        $new->status = $code;
        $new->reason = $reasonPhrase;

        return $new;
    }

    public function getReasonPhrase(): string
    {
        return $this->reason;
    }
}
