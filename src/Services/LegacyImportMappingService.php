<?php

declare(strict_types=1);

namespace App\Services;

final class LegacyImportMappingService
{
    /**
     * @param array<string, mixed> $mapping
     */
    public function __construct(private readonly array $mapping = [])
    {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function mapDeviceRow(array $row): array
    {
        $deviceMapping = $this->mappingFor($row);
        if ($deviceMapping === null) {
            return $row;
        }

        if ($this->isSkipped($deviceMapping)) {
            return [];
        }

        if (isset($deviceMapping['target_serial_number'])) {
            $row['serial_number'] = $this->nullableString($deviceMapping['target_serial_number']) ?? $row['serial_number'] ?? null;
        }

        if (array_key_exists('target_device_name', $deviceMapping)) {
            $row['name'] = $this->nullableString($deviceMapping['target_device_name']);
        }

        if (array_key_exists('target_device_comment', $deviceMapping)) {
            $row['comment'] = $this->nullableString($deviceMapping['target_device_comment']);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function mapInterfaceRow(array $row): array
    {
        $deviceMapping = $this->mappingFor($row);
        if ($deviceMapping === null) {
            return $row;
        }

        if ($this->isSkipped($deviceMapping)) {
            return [];
        }

        if (isset($deviceMapping['target_serial_number'])) {
            $row['serial_number'] = $this->nullableString($deviceMapping['target_serial_number']) ?? $row['serial_number'] ?? null;
        }

        if (isset($deviceMapping['target_interface_name'])) {
            $row['name'] = $this->nullableString($deviceMapping['target_interface_name']) ?? $row['name'] ?? null;
        }

        if (array_key_exists('target_interface_display_name', $deviceMapping)) {
            $row['display_name'] = $this->nullableString($deviceMapping['target_interface_display_name']);
        }

        if (array_key_exists('target_interface_comment', $deviceMapping)) {
            $row['comment'] = $this->nullableString($deviceMapping['target_interface_comment']);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function mapTrafficRow(array $row): array
    {
        $deviceMapping = $this->mappingFor($row);
        if ($deviceMapping === null) {
            return $row;
        }

        if ($this->isSkipped($deviceMapping)) {
            return [];
        }

        if (isset($deviceMapping['target_serial_number'])) {
            $row['serial_number'] = $this->nullableString($deviceMapping['target_serial_number']) ?? $row['serial_number'] ?? null;
        }

        if (isset($deviceMapping['target_interface_name'])) {
            $row['interface_name'] = $this->nullableString($deviceMapping['target_interface_name']) ?? $row['interface_name'] ?? null;
        }

        return $row;
    }

    /**
     * @return array{skip: bool, serial_number: string, interface_name: string}
     */
    public function mapSnapshotTarget(string $legacySerialNumber, string $defaultInterfaceName = 'legacy'): array
    {
        $deviceMapping = $this->mappingFor([
            'serial_number' => $legacySerialNumber,
        ]);

        if ($deviceMapping === null) {
            return [
                'skip' => false,
                'serial_number' => $legacySerialNumber,
                'interface_name' => $defaultInterfaceName,
            ];
        }

        if ($this->isSkipped($deviceMapping)) {
            return [
                'skip' => true,
                'serial_number' => '',
                'interface_name' => '',
            ];
        }

        return [
            'skip' => false,
            'serial_number' => $this->nullableString($deviceMapping['target_serial_number'] ?? null) ?? $legacySerialNumber,
            'interface_name' => $this->nullableString($deviceMapping['target_interface_name'] ?? null) ?? $defaultInterfaceName,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function mappingFor(array $row): ?array
    {
        $serialNumber = trim((string) ($row['serial_number'] ?? ''));
        if ($serialNumber === '') {
            return null;
        }

        $mapping = $this->mapping['legacy_devices'][$serialNumber] ?? null;

        return is_array($mapping) ? $mapping : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function isSkipped(array $mapping): bool
    {
        $value = $mapping['skip'] ?? false;

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
