<?php

declare(strict_types=1);

namespace App\Config;

use App\Support\EnvFileLoader;
use DateTimeZone;
use RuntimeException;

final class ConfigurationFactory
{
    /**
     * @param array<string, mixed> $runtimeEnvironment
     */
    public static function create(string $rootPath, array $runtimeEnvironment): Configuration
    {
        $envValues = EnvFileLoader::load($rootPath . '/.env');
        $values = self::mergeEnvironment($envValues, $runtimeEnvironment);
        $normalized = self::normalize($rootPath, $values);

        self::validate($normalized);

        return new Configuration($rootPath, $normalized);
    }

    /**
     * @param array<string, string> $envValues
     * @param array<string, mixed> $runtimeEnvironment
     * @return array<string, mixed>
     */
    private static function mergeEnvironment(array $envValues, array $runtimeEnvironment): array
    {
        $merged = $envValues;

        foreach ($runtimeEnvironment as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function normalize(string $rootPath, array $values): array
    {
        $sqlitePath = (string) ($values['DB_SQLITE_PATH'] ?? 'var/database/tikstats.sqlite');

        if (!self::isAbsolutePath($sqlitePath)) {
            $sqlitePath = $rootPath . '/' . ltrim($sqlitePath, '/');
        }

        return [
            'APP_ENV' => (string) ($values['APP_ENV'] ?? 'production'),
            'APP_DEBUG' => self::toBool($values['APP_DEBUG'] ?? false),
            'APP_TIMEZONE' => (string) ($values['APP_TIMEZONE'] ?? 'UTC'),
            'DB_DRIVER' => strtolower((string) ($values['DB_DRIVER'] ?? 'sqlite')),
            'DB_SQLITE_PATH' => $sqlitePath,
            'DB_MYSQL_HOST' => (string) ($values['DB_MYSQL_HOST'] ?? '127.0.0.1'),
            'DB_MYSQL_PORT' => self::toInt($values['DB_MYSQL_PORT'] ?? 3306),
            'DB_MYSQL_DATABASE' => (string) ($values['DB_MYSQL_DATABASE'] ?? ''),
            'DB_MYSQL_USERNAME' => (string) ($values['DB_MYSQL_USERNAME'] ?? ''),
            'DB_MYSQL_PASSWORD' => (string) ($values['DB_MYSQL_PASSWORD'] ?? ''),
            'AUTH_ENABLED' => self::toBool($values['AUTH_ENABLED'] ?? false),
            'AUTH_TOKEN' => (string) ($values['AUTH_TOKEN'] ?? ''),
            'SOURCE_IP_ENABLED' => self::toBool($values['SOURCE_IP_ENABLED'] ?? false),
            'SOURCE_IP_ALLOWLIST' => self::toList($values['SOURCE_IP_ALLOWLIST'] ?? ''),
            'TRAFFIC_USE_DELTA' => self::toBool($values['TRAFFIC_USE_DELTA'] ?? true),
            'DEFAULT_WINDOW_HOURS' => self::toInt($values['DEFAULT_WINDOW_HOURS'] ?? 48),
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function validate(array $values): void
    {
        if ($values['APP_TIMEZONE'] === '') {
            throw new RuntimeException('APP_TIMEZONE must not be empty.');
        }

        if (!in_array($values['APP_TIMEZONE'], DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException('APP_TIMEZONE must be a valid timezone identifier.');
        }

        if (!in_array($values['DB_DRIVER'], ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('DB_DRIVER must be either "sqlite" or "mysql".');
        }

        if ($values['DB_DRIVER'] === 'sqlite' && $values['DB_SQLITE_PATH'] === '') {
            throw new RuntimeException('DB_SQLITE_PATH is required when DB_DRIVER=sqlite.');
        }

        if ($values['DB_DRIVER'] === 'mysql') {
            foreach (['DB_MYSQL_HOST', 'DB_MYSQL_DATABASE', 'DB_MYSQL_USERNAME'] as $requiredKey) {
                if ($values[$requiredKey] === '') {
                    throw new RuntimeException(sprintf('%s is required when DB_DRIVER=mysql.', $requiredKey));
                }
            }
        }

        if ($values['AUTH_ENABLED'] && $values['AUTH_TOKEN'] === '') {
            throw new RuntimeException('AUTH_TOKEN is required when AUTH_ENABLED=true.');
        }

        if ($values['SOURCE_IP_ENABLED'] && $values['SOURCE_IP_ALLOWLIST'] === []) {
            throw new RuntimeException('SOURCE_IP_ALLOWLIST is required when SOURCE_IP_ENABLED=true.');
        }

        if ($values['DEFAULT_WINDOW_HOURS'] < 1) {
            throw new RuntimeException('DEFAULT_WINDOW_HOURS must be greater than zero.');
        }
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array<int, string>
     */
    private static function toList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), static fn (string $item): bool => $item !== ''));
        }

        $stringValue = trim((string) $value);

        if ($stringValue === '') {
            return [];
        }

        $items = array_map(static fn (string $item): string => trim($item), explode(',', $stringValue));

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    private static function isAbsolutePath(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || preg_match('/^[A-Za-z]:\\\\/', $path) === 1);
    }
}
