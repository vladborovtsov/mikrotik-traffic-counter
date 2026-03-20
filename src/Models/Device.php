<?php

declare(strict_types=1);

namespace App\Models;

final class Device
{
    public function __construct(
        public readonly int $id,
        public readonly string $serialNumber,
        public readonly ?string $name,
        public readonly ?string $comment,
        public readonly ?int $sortIndex,
        public readonly string $homeScope,
        public readonly ?int $homeInterfaceId,
        public readonly ?string $lastSeenAt,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            serialNumber: (string) $row['serial_number'],
            name: isset($row['name']) ? (string) $row['name'] : null,
            comment: isset($row['comment']) ? (string) $row['comment'] : null,
            sortIndex: isset($row['sort_index']) && $row['sort_index'] !== null ? (int) $row['sort_index'] : null,
            homeScope: isset($row['home_scope']) && is_string($row['home_scope']) && $row['home_scope'] !== '' ? (string) $row['home_scope'] : 'all',
            homeInterfaceId: isset($row['home_interface_id']) && $row['home_interface_id'] !== null ? (int) $row['home_interface_id'] : null,
            lastSeenAt: isset($row['last_seen_at']) ? (string) $row['last_seen_at'] : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function toArray(array $extra = []): array
    {
        return array_merge([
            'id' => $this->id,
            'serial_number' => $this->serialNumber,
            'sn' => $this->serialNumber,
            'name' => $this->name,
            'comment' => $this->comment,
            'sort_index' => $this->sortIndex,
            'home_scope' => $this->homeScope,
            'home_interface_id' => $this->homeInterfaceId,
            'last_seen_at' => $this->lastSeenAt,
            'last_check' => $this->lastSeenAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ], $extra);
    }
}
