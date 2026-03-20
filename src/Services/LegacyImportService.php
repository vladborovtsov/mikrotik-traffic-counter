<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class LegacyImportService
{
    /** @var array<string, \App\Models\Device> */
    private array $deviceBySerial = [];

    /** @var array<string, \App\Models\InterfaceModel> */
    private array $interfaceByKey = [];

    /** @var array<string, array{tx: int, rx: int}> */
    private array $currentRaw = [];

    /** @var array<string, string> */
    private array $lastRecordedAt = [];

    /** @var array<string, array{last_seen_at: string, last_tx: ?int, last_rx: ?int}> */
    private array $deviceSnapshotTargets = [];

    /** @var array{devices: int, interfaces: int, traffic_samples: int, synthetic_snapshots: int} */
    private array $counts = [
        'devices' => 0,
        'interfaces' => 0,
        'traffic_samples' => 0,
        'synthetic_snapshots' => 0,
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly DeviceService $deviceService,
        private readonly InterfaceService $interfaceService,
        private readonly TrafficService $trafficService
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{devices: int, interfaces: int, traffic_samples: int, synthetic_snapshots: int}
     */
    public function import(array $payload): array
    {
        $devices = is_array($payload['devices'] ?? null) ? $payload['devices'] : [];
        $interfaces = is_array($payload['interfaces'] ?? null) ? $payload['interfaces'] : [];
        $samples = is_array($payload['traffic_samples'] ?? null) ? $payload['traffic_samples'] : [];
        $this->beginImport();

        try {
            foreach ($devices as $deviceRow) {
                $this->importDeviceRow(is_array($deviceRow) ? $deviceRow : []);
            }

            foreach ($interfaces as $interfaceRow) {
                $this->importInterfaceRow(is_array($interfaceRow) ? $interfaceRow : []);
            }

            foreach ($samples as $sampleRow) {
                $this->importTrafficRow(is_array($sampleRow) ? $sampleRow : []);
            }

            return $this->finishImport();
        } catch (\Throwable $exception) {
            $this->rollbackImport();
            throw $exception;
        }
    }

    public function beginImport(): void
    {
        $this->deviceBySerial = [];
        $this->interfaceByKey = [];
        $this->currentRaw = [];
        $this->lastRecordedAt = [];
        $this->deviceSnapshotTargets = [];
        $this->counts = [
            'devices' => 0,
            'interfaces' => 0,
            'traffic_samples' => 0,
            'synthetic_snapshots' => 0,
        ];

        $this->db->beginTransaction();
    }

    /**
     * @param array<string, mixed> $deviceRow
     */
    public function importDeviceRow(array $deviceRow): void
    {
        $serial = trim((string) ($deviceRow['serial_number'] ?? ''));
        if ($serial === '') {
            return;
        }

        $timestamp = $this->normalizeTimestamp($deviceRow['last_seen_at'] ?? null);
        $device = $this->deviceService->getDeviceBySerial($serial)
            ?? $this->deviceService->createDevice(
                $serial,
                $timestamp,
                $this->nullableString($deviceRow['name'] ?? null),
                $this->nullableString($deviceRow['comment'] ?? null)
            );

        $device = $this->deviceService->updateDevice($device->id, [
            'name' => $this->nullableString($deviceRow['name'] ?? null),
            'comment' => $this->nullableString($deviceRow['comment'] ?? null),
            'last_seen_at' => $timestamp,
        ], $timestamp) ?? $device;

        $this->deviceBySerial[$serial] = $device;
        $this->deviceSnapshotTargets[$serial] = [
            'last_seen_at' => $timestamp,
            'last_tx' => $this->nullableInt($deviceRow['last_tx'] ?? null),
            'last_rx' => $this->nullableInt($deviceRow['last_rx'] ?? null),
        ];
        $this->counts['devices']++;
    }

    /**
     * @param array<string, mixed> $interfaceRow
     */
    public function importInterfaceRow(array $interfaceRow): void
    {
        $serial = trim((string) ($interfaceRow['serial_number'] ?? ''));
        $name = trim((string) ($interfaceRow['name'] ?? ''));
        if ($serial === '' || $name === '' || !isset($this->deviceBySerial[$serial])) {
            return;
        }

        $device = $this->deviceBySerial[$serial];
        $timestamp = $this->deviceSnapshotTargets[$serial]['last_seen_at'] ?? $this->now();
        $interface = $this->interfaceService->getByDeviceAndName($device->id, $name)
            ?? $this->interfaceService->createInterface(
                $device->id,
                $name,
                $timestamp,
                $this->nullableString($interfaceRow['display_name'] ?? null),
                $this->nullableString($interfaceRow['comment'] ?? null)
            );

        $interface = $this->interfaceService->updateInterface($interface->id, [
            'display_name' => $this->nullableString($interfaceRow['display_name'] ?? null),
            'comment' => $this->nullableString($interfaceRow['comment'] ?? null),
            'last_seen_at' => $timestamp,
        ], $timestamp) ?? $interface;

        $this->interfaceByKey[$this->makeInterfaceKey($serial, $name)] = $interface;
        $this->counts['interfaces']++;
    }

    /**
     * @param array<string, mixed> $sampleRow
     */
    public function importTrafficRow(array $sampleRow): void
    {
        $serial = trim((string) ($sampleRow['serial_number'] ?? ''));
        $interfaceName = trim((string) ($sampleRow['interface_name'] ?? ''));
        if ($serial === '' || $interfaceName === '') {
            return;
        }

        $key = $this->makeInterfaceKey($serial, $interfaceName);
        $device = $this->deviceBySerial[$serial] ?? null;
        $interface = $this->interfaceByKey[$key] ?? null;

        if ($device === null || $interface === null) {
            return;
        }

        $deltaTx = max(0, (int) ($sampleRow['delta_tx'] ?? 0));
        $deltaRx = max(0, (int) ($sampleRow['delta_rx'] ?? 0));
        $rawTx = $this->nullableInt($sampleRow['raw_tx'] ?? null);
        $rawRx = $this->nullableInt($sampleRow['raw_rx'] ?? null);

        if ($rawTx === null) {
            $rawTx = ($this->currentRaw[$key]['tx'] ?? 0) + $deltaTx;
        }
        if ($rawRx === null) {
            $rawRx = ($this->currentRaw[$key]['rx'] ?? 0) + $deltaRx;
        }

        $recordedAt = $this->normalizeTimestamp($sampleRow['recorded_at'] ?? null);

        $this->trafficService->importSample(
            $device->id,
            $interface->id,
            $rawTx,
            $rawRx,
            $deltaTx,
            $deltaRx,
            $recordedAt
        );

        $this->currentRaw[$key] = ['tx' => $rawTx, 'rx' => $rawRx];
        $this->lastRecordedAt[$key] = $recordedAt;
        $this->counts['traffic_samples']++;
    }

    /**
     * @return array{devices: int, interfaces: int, traffic_samples: int, synthetic_snapshots: int}
     */
    public function finishImport(): array
    {
        foreach ($this->deviceSnapshotTargets as $serial => $snapshot) {
            $device = $this->deviceBySerial[$serial] ?? null;
            if ($device === null) {
                continue;
            }

            $interface = $this->findFirstInterfaceForSerial($serial, $this->interfaceByKey);
            if ($interface === null) {
                continue;
            }

            $key = $this->makeInterfaceKey($serial, $interface->name);
            $snapshotTx = $snapshot['last_tx'];
            $snapshotRx = $snapshot['last_rx'];

            if ($snapshotTx === null && $snapshotRx === null) {
                continue;
            }

            $currentTx = $this->currentRaw[$key]['tx'] ?? null;
            $currentRx = $this->currentRaw[$key]['rx'] ?? null;
            $targetTx = $snapshotTx ?? $currentTx ?? 0;
            $targetRx = $snapshotRx ?? $currentRx ?? 0;

            if ($currentTx !== null && $targetTx < $currentTx) {
                $targetTx = $currentTx;
            }
            if ($currentRx !== null && $targetRx < $currentRx) {
                $targetRx = $currentRx;
            }

            if ($currentTx === $targetTx && $currentRx === $targetRx) {
                continue;
            }

            $recordedAt = $snapshot['last_seen_at'] ?? $this->now();
            if (isset($this->lastRecordedAt[$key]) && strtotime($recordedAt) < strtotime($this->lastRecordedAt[$key])) {
                $recordedAt = $this->lastRecordedAt[$key];
            }

            $this->trafficService->importSample(
                $device->id,
                $interface->id,
                $targetTx,
                $targetRx,
                0,
                0,
                $recordedAt
            );

            $this->counts['synthetic_snapshots']++;
        }

        $this->db->commit();

        return $this->counts;
    }

    public function rollbackImport(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    /**
     * @param array<string, \App\Models\InterfaceModel> $interfaceByKey
     */
    private function findFirstInterfaceForSerial(string $serial, array $interfaceByKey): ?\App\Models\InterfaceModel
    {
        foreach ($interfaceByKey as $key => $interface) {
            if (str_starts_with($key, $serial . '::')) {
                return $interface;
            }
        }

        return null;
    }

    private function makeInterfaceKey(string $serial, string $name): string
    {
        return $serial . '::' . $name;
    }

    private function normalizeTimestamp(mixed $value): string
    {
        $timestamp = $this->nullableString($value);

        return $timestamp !== null && $timestamp !== '' ? $timestamp : $this->now();
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
