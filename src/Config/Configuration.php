<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

final class Configuration
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        private readonly string $rootPath,
        private readonly array $values
    ) {
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function getString(string $key, ?string $default = null): ?string
    {
        $value = $this->get($key, $default);

        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    public function requireString(string $key): string
    {
        $value = $this->getString($key);

        if ($value === null || $value === '') {
            throw new RuntimeException(sprintf('Missing required configuration value: %s', $key));
        }

        return $value;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return (bool) $value;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @return array<int, string>
     */
    public function getList(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);

        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), static fn (string $item): bool => $item !== ''));
        }

        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        $items = array_map(static fn (string $item): string => trim($item), explode(',', $value));

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
