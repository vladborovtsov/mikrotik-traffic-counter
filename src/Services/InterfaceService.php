<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InterfaceModel;
use PDO;

final class InterfaceService
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array<int, InterfaceModel>
     */
    public function listByDeviceId(int $deviceId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM interfaces WHERE device_id = :device_id ORDER BY name ASC');
        $stmt->execute([':device_id' => $deviceId]);
        $rows = $stmt->fetchAll();

        return array_map(static fn (array $row): InterfaceModel => InterfaceModel::fromRow($row), $rows);
    }

    public function getById(int $id): ?InterfaceModel
    {
        $stmt = $this->db->prepare('SELECT * FROM interfaces WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ? InterfaceModel::fromRow($row) : null;
    }

    public function getByDeviceAndName(int $deviceId, string $name): ?InterfaceModel
    {
        $stmt = $this->db->prepare('SELECT * FROM interfaces WHERE device_id = :device_id AND name = :name');
        $stmt->execute([
            ':device_id' => $deviceId,
            ':name' => $name,
        ]);
        $row = $stmt->fetch();

        return $row ? InterfaceModel::fromRow($row) : null;
    }

    public function createInterface(int $deviceId, string $name, string $timestamp, ?string $displayName = null, ?string $comment = null): InterfaceModel
    {
        $stmt = $this->db->prepare('INSERT INTO interfaces (device_id, name, display_name, comment, last_seen_at, created_at, updated_at)
            VALUES (:device_id, :name, :display_name, :comment, :last_seen_at, :created_at, :updated_at)');
        $stmt->execute([
            ':device_id' => $deviceId,
            ':name' => $name,
            ':display_name' => $displayName ?? $name,
            ':comment' => $comment,
            ':last_seen_at' => $timestamp,
            ':created_at' => $timestamp,
            ':updated_at' => $timestamp,
        ]);

        return $this->getById((int) $this->db->lastInsertId());
    }

    public function createOrTouchInterface(int $deviceId, string $name, string $timestamp): InterfaceModel
    {
        $interface = $this->getByDeviceAndName($deviceId, $name);

        if ($interface === null) {
            return $this->createInterface($deviceId, $name, $timestamp);
        }

        $this->touchInterface($interface->id, $timestamp);

        return $this->getById($interface->id);
    }

    /**
     * @param array{display_name?: ?string, comment?: ?string, last_seen_at?: ?string} $fields
     */
    public function updateInterface(int $id, array $fields, string $timestamp): ?InterfaceModel
    {
        $assignments = [];
        $params = [':id' => $id, ':updated_at' => $timestamp];

        if (array_key_exists('display_name', $fields)) {
            $assignments[] = 'display_name = :display_name';
            $params[':display_name'] = $fields['display_name'];
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
            return $this->getById($id);
        }

        $assignments[] = 'updated_at = :updated_at';

        $stmt = $this->db->prepare(sprintf('UPDATE interfaces SET %s WHERE id = :id', implode(', ', $assignments)));
        $stmt->execute($params);

        return $this->getById($id);
    }

    public function touchInterface(int $id, string $timestamp): void
    {
        $this->updateInterface($id, ['last_seen_at' => $timestamp], $timestamp);
    }
}
