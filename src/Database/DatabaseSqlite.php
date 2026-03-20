<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class DatabaseSqlite extends Database
{
    public function driver(): string
    {
        return 'sqlite';
    }

    public function hourlyBucketExpression(string $column): string
    {
        return sprintf("strftime('%%Y-%%m-%%d %%H:00:00', %s)", $column);
    }

    public function bucketExpression(string $column, int $minutes): string
    {
        if ($minutes <= 60 && 60 % $minutes === 0) {
            return sprintf(
                "strftime('%%Y-%%m-%%d %%H:%%M:00', datetime(%s, printf('-%d minutes', CAST(strftime('%%M', %s) AS INTEGER) %% %d), 'utc'))",
                $column,
                $minutes,
                $column,
                $minutes
            );
        }

        return $this->hourlyBucketExpression($column);
    }

    public function initializeSchema(): void
    {
        if ($this->tableExists('devices') && !$this->columnExists('devices', 'serial_number')) {
            throw new RuntimeException('Legacy SQLite schema detected. This is a clean break for v2; export the old SQLite data and start with a fresh database file.');
        }

        $this->pdo()->exec('CREATE TABLE IF NOT EXISTS schema_meta (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )');

        $this->pdo()->exec('CREATE TABLE IF NOT EXISTS global_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )');

        $this->pdo()->exec('CREATE TABLE IF NOT EXISTS devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            serial_number TEXT NOT NULL,
            name TEXT NULL,
            comment TEXT NULL,
            sort_index INTEGER NULL,
            home_scope TEXT NOT NULL DEFAULT \'all\',
            home_interface_id INTEGER NULL,
            last_seen_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE(serial_number)
        )');

        if (!$this->columnExists('devices', 'sort_index')) {
            $this->pdo()->exec('ALTER TABLE devices ADD COLUMN sort_index INTEGER NULL');
        }

        if (!$this->columnExists('devices', 'home_scope')) {
            $this->pdo()->exec("ALTER TABLE devices ADD COLUMN home_scope TEXT NOT NULL DEFAULT 'all'");
        }

        if (!$this->columnExists('devices', 'home_interface_id')) {
            $this->pdo()->exec('ALTER TABLE devices ADD COLUMN home_interface_id INTEGER NULL');
        }

        $this->pdo()->exec('CREATE TABLE IF NOT EXISTS interfaces (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            device_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            display_name TEXT NULL,
            comment TEXT NULL,
            last_seen_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE(device_id, name),
            FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE
        )');

        $this->pdo()->exec('CREATE TABLE IF NOT EXISTS traffic_samples (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            device_id INTEGER NOT NULL,
            interface_id INTEGER NOT NULL,
            recorded_at DATETIME NOT NULL,
            raw_tx INTEGER NOT NULL,
            raw_rx INTEGER NOT NULL,
            delta_tx INTEGER NOT NULL,
            delta_rx INTEGER NOT NULL,
            source_ip TEXT NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE,
            FOREIGN KEY(interface_id) REFERENCES interfaces(id) ON DELETE CASCADE
        )');

        $this->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_devices_serial_number ON devices(serial_number)');
        $this->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_interfaces_device_id ON interfaces(device_id)');
        $this->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_traffic_samples_device_recorded_at ON traffic_samples(device_id, recorded_at)');
        $this->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_traffic_samples_interface_recorded_at ON traffic_samples(interface_id, recorded_at)');
        $this->pdo()->exec("INSERT INTO schema_meta (key, value) VALUES ('schema_version', '2')
            ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    }

    protected function connect(): PDO
    {
        $databasePath = $this->config->requireString('DB_SQLITE_PATH');
        $databaseDirectory = dirname($databasePath);

        if (!is_dir($databaseDirectory) && !@mkdir($databaseDirectory, 0775, true) && !is_dir($databaseDirectory)) {
            throw new RuntimeException(sprintf('Failed to create SQLite directory: %s', $databaseDirectory));
        }

        return new PDO('sqlite:' . $databasePath);
    }

    protected function afterConnect(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo()->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name");
        $stmt->execute([
            ':name' => $table,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo()->query(sprintf('PRAGMA table_info(%s)', $table));
        $columns = $stmt->fetchAll();

        foreach ($columns as $definition) {
            if (($definition['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
}
