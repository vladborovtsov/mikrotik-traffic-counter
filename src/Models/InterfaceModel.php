<?php

declare(strict_types=1);

namespace App\Models;

final class InterfaceModel
{
    public function __construct(
        public readonly int $id,
        public readonly int $deviceId,
        public readonly string $name,
        public readonly ?string $displayName,
        public readonly ?string $comment,
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
            deviceId: (int) $row['device_id'],
            name: (string) $row['name'],
            displayName: isset($row['display_name']) ? (string) $row['display_name'] : null,
            comment: isset($row['comment']) ? (string) $row['comment'] : null,
            lastSeenAt: isset($row['last_seen_at']) ? (string) $row['last_seen_at'] : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->deviceId,
            'name' => $this->name,
            'display_name' => $this->displayName,
            'comment' => $this->comment,
            'last_seen_at' => $this->lastSeenAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
