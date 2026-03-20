<?php

declare(strict_types=1);

namespace App\Database;

use PDOException;
use PDO;
use RuntimeException;

final class DatabaseMysql extends Database
{
    public function driver(): string
    {
        return 'mysql';
    }

    public function hourlyBucketExpression(string $column): string
    {
        return sprintf("DATE_FORMAT(%s, '%%Y-%%m-%%d %%H:00:00')", $column);
    }

    public function bucketExpression(string $column, int $minutes): string
    {
        if ($minutes <= 60 && 60 % $minutes === 0) {
            return sprintf(
                "DATE_FORMAT(DATE_SUB(%s, INTERVAL (MINUTE(%s) %% %d) MINUTE), '%%Y-%%m-%%d %%H:%%i:00')",
                $column,
                $column,
                $minutes
            );
        }

        return $this->hourlyBucketExpression($column);
    }

    public function initializeSchema(): void
    {
        if ($this->tableExists('devices') && !$this->columnExists('devices', 'serial_number')) {
            throw new RuntimeException('Legacy MySQL schema detected. This is a clean break for v2; export the old data and initialize a fresh v2 schema.');
        }

        $this->pdo()->exec('CREATE TABLE IF NOT EXISTS schema_meta (
            `key` VARCHAR(191) PRIMARY KEY,
            `value` VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->pdo()->exec('CREATE TABLE IF NOT EXISTS devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            serial_number VARCHAR(191) NOT NULL,
            name VARCHAR(255) NULL,
            comment VARCHAR(255) NULL,
            home_scope VARCHAR(32) NOT NULL DEFAULT \'all\',
            home_interface_id INT NULL,
            last_seen_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_devices_serial_number (serial_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        if (!$this->columnExists('devices', 'home_scope')) {
            $this->pdo()->exec("ALTER TABLE devices ADD COLUMN home_scope VARCHAR(32) NOT NULL DEFAULT 'all' AFTER comment");
        }

        if (!$this->columnExists('devices', 'home_interface_id')) {
            $this->pdo()->exec('ALTER TABLE devices ADD COLUMN home_interface_id INT NULL AFTER home_scope');
        }

        $this->pdo()->exec('CREATE TABLE IF NOT EXISTS interfaces (
            id INT AUTO_INCREMENT PRIMARY KEY,
            device_id INT NOT NULL,
            name VARCHAR(191) NOT NULL,
            display_name VARCHAR(255) NULL,
            comment VARCHAR(255) NULL,
            last_seen_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_interfaces_device_name (device_id, name),
            KEY idx_interfaces_device_id (device_id),
            CONSTRAINT fk_interfaces_device_id FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->pdo()->exec('CREATE TABLE IF NOT EXISTS traffic_samples (
            id INT AUTO_INCREMENT PRIMARY KEY,
            device_id INT NOT NULL,
            interface_id INT NOT NULL,
            recorded_at DATETIME NOT NULL,
            raw_tx BIGINT NOT NULL,
            raw_rx BIGINT NOT NULL,
            delta_tx BIGINT NOT NULL,
            delta_rx BIGINT NOT NULL,
            source_ip VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_traffic_samples_device_recorded_at (device_id, recorded_at),
            KEY idx_traffic_samples_interface_recorded_at (interface_id, recorded_at),
            CONSTRAINT fk_traffic_samples_device_id FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
            CONSTRAINT fk_traffic_samples_interface_id FOREIGN KEY (interface_id) REFERENCES interfaces(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->pdo()->exec("INSERT INTO schema_meta (`key`, `value`) VALUES ('schema_version', '2')
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    }

    protected function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config->requireString('DB_MYSQL_HOST'),
            $this->config->getInt('DB_MYSQL_PORT', 3306),
            $this->config->requireString('DB_MYSQL_DATABASE')
        );

        return new PDO(
            $dsn,
            $this->config->requireString('DB_MYSQL_USERNAME'),
            $this->config->getString('DB_MYSQL_PASSWORD', '') ?? ''
        );
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo()->prepare('SHOW TABLES LIKE :table_name');
            $stmt->execute([
                ':table_name' => $table,
            ]);
        } catch (PDOException) {
            return false;
        }

        return (bool) $stmt->fetchColumn();
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->pdo()->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        } catch (PDOException) {
            return false;
        }

        $columns = $stmt->fetchAll();

        foreach ($columns as $definition) {
            if (($definition['Field'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
}
