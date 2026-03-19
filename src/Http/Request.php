<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $server
     */
    public function __construct(
        private readonly array $query,
        private readonly array $server
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_GET, $_SERVER);
    }

    public function action(): string
    {
        return trim((string) ($this->query['action'] ?? ''));
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function hasQuery(string $key): bool
    {
        return array_key_exists($key, $this->query);
    }

    public function intQuery(string $key): ?int
    {
        $value = $this->query($key);

        return ($value !== null && is_numeric($value)) ? (int) $value : null;
    }

    public function remoteAddress(): ?string
    {
        $value = $this->server['REMOTE_ADDR'] ?? null;

        return $value !== null ? (string) $value : null;
    }

    public function wantsPlainText(): bool
    {
        return $this->query('_format') === 'plain';
    }

    /**
     * @return array<string, mixed>
     */
    public function queryParams(): array
    {
        return $this->query;
    }
}
