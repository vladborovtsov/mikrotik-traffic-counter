<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\Configuration;
use PDO;

abstract class Database
{
    private ?PDO $pdo = null;

    public function __construct(
        protected readonly Configuration $config
    ) {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $this->pdo = $this->connect();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->afterConnect($this->pdo);

        return $this->pdo;
    }

    abstract public function driver(): string;

    abstract public function hourlyBucketExpression(string $column): string;

    abstract public function bucketExpression(string $column, int $minutes): string;

    abstract public function initializeSchema(): void;

    abstract protected function connect(): PDO;

    protected function afterConnect(PDO $pdo): void
    {
    }
}
