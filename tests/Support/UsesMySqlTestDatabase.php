<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Config\Configuration;
use App\Config\ConfigurationFactory;
use App\Database\Database;
use App\Database\DatabaseFactory;
use PDO;
use Throwable;

trait UsesMySqlTestDatabase
{
    protected function createMySqlDatabaseOrSkip(): Database
    {
        $host = getenv('TEST_MYSQL_HOST') ?: null;
        $database = getenv('TEST_MYSQL_DATABASE') ?: null;
        $username = getenv('TEST_MYSQL_USERNAME') ?: null;

        if ($host === null || $database === null || $username === null) {
            $this->markTestSkipped('MySQL test environment not configured. Set TEST_MYSQL_HOST, TEST_MYSQL_DATABASE, and TEST_MYSQL_USERNAME.');
        }

        $configuration = ConfigurationFactory::create(
            dirname(__DIR__, 2),
            [
                'DB_DRIVER' => 'mysql',
                'DB_MYSQL_HOST' => $host,
                'DB_MYSQL_PORT' => getenv('TEST_MYSQL_PORT') ?: '3306',
                'DB_MYSQL_DATABASE' => $database,
                'DB_MYSQL_USERNAME' => $username,
                'DB_MYSQL_PASSWORD' => getenv('TEST_MYSQL_PASSWORD') ?: '',
            ]
        );

        try {
            return DatabaseFactory::create($configuration);
        } catch (Throwable $throwable) {
            $this->markTestSkipped('Unable to connect to MySQL test database: ' . $throwable->getMessage());
        }
    }

    protected function resetMySqlSchema(Database $database): void
    {
        $pdo = $database->pdo();

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec('DROP TABLE IF EXISTS traffic_samples');
        $pdo->exec('DROP TABLE IF EXISTS interfaces');
        $pdo->exec('DROP TABLE IF EXISTS devices');
        $pdo->exec('DROP TABLE IF EXISTS schema_meta');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
