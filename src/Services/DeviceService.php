<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use PDO;

final class DeviceService
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array<int, Device>
     */
    public function listDevices(): array
    {
        $stmt = $this->db->query('SELECT * FROM devices ORDER BY last_seen_at DESC, id DESC');
        $rows = $stmt->fetchAll();

        return array_map(static fn (array $row): Device => Device::fromRow($row), $rows);
    }

    public function getDeviceById(int $id): ?Device
    {
        $stmt = $this->db->prepare('SELECT * FROM devices WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ? Device::fromRow($row) : null;
    }

    public function getDeviceBySerial(string $serial): ?Device
    {
        $stmt = $this->db->prepare('SELECT * FROM devices WHERE serial_number = :serial');
        $stmt->execute([':serial' => $serial]);
        $row = $stmt->fetch();

        return $row ? Device::fromRow($row) : null;
    }

    public function createDevice(string $serial, string $timestamp, ?string $name = null, ?string $comment = null): Device
    {
        $stmt = $this->db->prepare('INSERT INTO devices (serial_number, name, comment, last_seen_at, created_at, updated_at)
            VALUES (:serial_number, :name, :comment, :last_seen_at, :created_at, :updated_at)');
        $stmt->execute([
            ':serial_number' => $serial,
            ':name' => $name,
            ':comment' => $comment,
            ':last_seen_at' => $timestamp,
            ':created_at' => $timestamp,
            ':updated_at' => $timestamp,
        ]);

        return $this->getDeviceById((int) $this->db->lastInsertId());
    }

    public function createOrTouchDevice(string $serial, string $timestamp): Device
    {
        $device = $this->getDeviceBySerial($serial);

        if ($device === null) {
            return $this->createDevice($serial, $timestamp);
        }

        $this->touchDevice($device->id, $timestamp);

        return $this->getDeviceById($device->id);
    }

    /**
     * @param array{name?: ?string, comment?: ?string, last_seen_at?: ?string} $fields
     */
    public function updateDevice(int $id, array $fields, string $timestamp): ?Device
    {
        $assignments = [];
        $params = [':id' => $id, ':updated_at' => $timestamp];

        if (array_key_exists('name', $fields)) {
            $assignments[] = 'name = :name';
            $params[':name'] = $fields['name'];
        }

        if (array_key_exists('comment', $fields)) {
            $assignments[] = 'comment = :comment';
            $params[':comment'] = $fields['comment'];
        }

        if (array_key_exists('last_seen_at', $fields)) {
            $assignments[] = 'last_seen_at = :last_seen_at';
            $params[':last_seen_at'] = $fields['last_seen_at'];
        }

        if ($assignments === []) {
            return $this->getDeviceById($id);
        }

        $assignments[] = 'updated_at = :updated_at';

        $stmt = $this->db->prepare(sprintf('UPDATE devices SET %s WHERE id = :id', implode(', ', $assignments)));
        $stmt->execute($params);

        return $this->getDeviceById($id);
    }

    public function renameDevice(int $id, string $name, string $timestamp): ?Device
    {
        return $this->updateDevice($id, ['name' => $name], $timestamp);
    }

    public function touchDevice(int $id, string $timestamp): void
    {
        $this->updateDevice($id, ['last_seen_at' => $timestamp], $timestamp);
    }

    public function deleteDevice(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM devices WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
