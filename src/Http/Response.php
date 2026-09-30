<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    public function __construct(
        public readonly string $body,
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {
    }

    public function send(bool $headOnly = false): void
    {
        http_response_code($this->status);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if (!$headOnly) {
            echo $this->body;
        }
    }
}
