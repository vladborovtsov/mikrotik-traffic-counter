<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\Configuration;
use RuntimeException;

final class DatabaseFactory
{
    public static function create(Configuration $config): Database
    {
        return match ($config->requireString('DB_DRIVER')) {
            'sqlite' => new DatabaseSqlite($config),
            'mysql' => new DatabaseMysql($config),
            default => throw new RuntimeException('Unsupported database driver configured.'),
        };
    }
}
