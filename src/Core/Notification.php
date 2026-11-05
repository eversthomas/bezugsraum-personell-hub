<?php

declare(strict_types=1);

namespace App\Core;

/** Eine Benachrichtigung, unabhängig vom Kanal (Web Push, später E-Mail). */
final class Notification
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $url = '/',
        public readonly string $tag = '',
    ) {
    }

    public function toJson(): string
    {
        return json_encode(
            ['title' => $this->title, 'body' => $this->body, 'url' => $this->url, 'tag' => $this->tag],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
