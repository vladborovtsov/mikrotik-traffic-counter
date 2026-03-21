<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly string $body,
        private readonly int $statusCode = 200,
        private readonly array $headers = []
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $statusCode = 200): self
    {
        return new self(
            (string) json_encode($payload),
            $statusCode,
            [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ]
        );
    }

    public static function text(string $payload, int $statusCode = 200): self
    {
        return new self(
            $payload,
            $statusCode,
            [
                'Content-Type' => 'text/plain',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ]
        );
    }

    public static function csv(string $payload, string $filename, int $statusCode = 200): self
    {
        return new self(
            $payload,
            $statusCode,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . addcslashes($filename, "\"\\") . '"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ]
        );
    }

    public function send(): never
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->body;
        exit;
    }
}
