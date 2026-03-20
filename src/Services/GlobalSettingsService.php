<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class GlobalSettingsService
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{theme_mode: string}
     */
    public function getSettings(): array
    {
        return [
            'theme_mode' => $this->get('theme_mode') ?? 'auto',
        ];
    }

    public function get(string $key): ?string
    {
        $stmt = $this->db->prepare('SELECT `value` FROM global_settings WHERE `key` = :key');
        $stmt->execute([
            ':key' => $key,
        ]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $stmt = $this->db->prepare('INSERT INTO global_settings (`key`, `value`) VALUES (:key, :value)
                ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
            $stmt->execute([
                ':key' => $key,
                ':value' => $value,
            ]);

            return;
        }

        $stmt = $this->db->prepare('INSERT INTO global_settings (`key`, `value`) VALUES (:key, :value)
            ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`');
        $stmt->execute([
            ':key' => $key,
            ':value' => $value,
        ]);
    }
}
