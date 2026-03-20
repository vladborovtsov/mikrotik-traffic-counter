<?php

declare(strict_types=1);

namespace App\Models;

final class TrafficSample
{
    public function __construct(
        public readonly int $id,
        public readonly int $deviceId,
        public readonly int $interfaceId,
        public readonly string $recordedAt,
        public readonly int $rawTx,
        public readonly int $rawRx,
        public readonly int $deltaTx,
        public readonly int $deltaRx,
        public readonly ?string $sourceIp,
        public readonly string $createdAt,
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
            interfaceId: (int) $row['interface_id'],
            recordedAt: (string) $row['recorded_at'],
            rawTx: (int) $row['raw_tx'],
            rawRx: (int) $row['raw_rx'],
            deltaTx: (int) $row['delta_tx'],
            deltaRx: (int) $row['delta_rx'],
            sourceIp: isset($row['source_ip']) ? (string) $row['source_ip'] : null,
            createdAt: (string) $row['created_at'],
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
            'interface_id' => $this->interfaceId,
            'recorded_at' => $this->recordedAt,
            'raw_tx' => $this->rawTx,
            'raw_rx' => $this->rawRx,
            'delta_tx' => $this->deltaTx,
            'delta_rx' => $this->deltaRx,
            'source_ip' => $this->sourceIp,
            'created_at' => $this->createdAt,
        ];
    }
}
