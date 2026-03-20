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
        $stmt = $this->db->query('SELECT d.*
            FROM devices d
            LEFT JOIN (
                SELECT device_id, MAX(last_seen_at) AS interface_last_seen_at
                FROM interfaces
                GROUP BY device_id
            ) latest_interface_activity ON latest_interface_activity.device_id = d.id
            ORDER BY
                CASE WHEN d.sort_index IS NULL THEN 1 ELSE 0 END ASC,
                d.sort_index ASC,
                CASE
                    WHEN latest_interface_activity.interface_last_seen_at IS NOT NULL
                        AND (d.last_seen_at IS NULL OR latest_interface_activity.interface_last_seen_at > d.last_seen_at)
                    THEN latest_interface_activity.interface_last_seen_at
                    ELSE d.last_seen_at
                END DESC,
                d.id DESC');
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
     * @param array{name?: ?string, comment?: ?string, sort_index?: ?int, home_scope?: ?string, home_interface_id?: ?int, last_seen_at?: ?string} $fields
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

        if (array_key_exists('sort_index', $fields)) {
            $assignments[] = 'sort_index = :sort_index';
            $params[':sort_index'] = $fields['sort_index'];
        }

        if (array_key_exists('home_scope', $fields)) {
            $assignments[] = 'home_scope = :home_scope';
            $params[':home_scope'] = $fields['home_scope'];
        }

        if (array_key_exists('home_interface_id', $fields)) {
            $assignments[] = 'home_interface_id = :home_interface_id';
            $params[':home_interface_id'] = $fields['home_interface_id'];
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

    public function moveDevice(int $id, string $direction, string $timestamp): ?Device
    {
        $devices = $this->listDevices();
        if ($devices === []) {
            return null;
        }

        $needsNormalization = false;
        foreach ($devices as $device) {
            if ($device->sortIndex === null) {
                $needsNormalization = true;
                break;
            }
        }

        if ($needsNormalization) {
            $this->rewriteSortIndexes($devices, $timestamp);
            $devices = $this->listDevices();
        }

        $currentIndex = null;
        foreach ($devices as $index => $device) {
            if ($device->id === $id) {
                $currentIndex = $index;
                break;
            }
        }

        if ($currentIndex === null) {
            return null;
        }

        $targetIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;
        if (!isset($devices[$targetIndex])) {
            return $this->getDeviceById($id);
        }

        [$devices[$currentIndex], $devices[$targetIndex]] = [$devices[$targetIndex], $devices[$currentIndex]];
        $this->rewriteSortIndexes($devices, $timestamp);

        return $this->getDeviceById($id);
    }

    /**
     * @param array<int, Device> $devices
     */
    private function rewriteSortIndexes(array $devices, string $timestamp): void
    {
        $stmt = $this->db->prepare('UPDATE devices SET sort_index = :sort_index, updated_at = :updated_at WHERE id = :id');

        foreach (array_values($devices) as $index => $device) {
            $stmt->execute([
                ':sort_index' => $index + 1,
                ':updated_at' => $timestamp,
                ':id' => $device->id,
            ]);
        }
    }
}
