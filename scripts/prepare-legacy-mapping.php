<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$arguments = $argv;
array_shift($arguments);

if (count($arguments) < 1) {
    fwrite(STDERR, "Usage: php scripts/prepare-legacy-mapping.php <export-json-path> [output-mapping-path]\n");
    exit(1);
}

$inputPath = $arguments[0];
$outputPath = $arguments[1] ?? 'var/legacy-mapping.json';

if (!is_file($inputPath)) {
    fwrite(STDERR, "Export JSON file not found: {$inputPath}\n");
    exit(1);
}

/** @var array{config: \App\Config\Configuration, database: \App\Database\Database, pdo: \PDO} $runtime */
$runtime = require __DIR__ . '/../src/bootstrap.php';
$database = $runtime['database'];
$pdo = $runtime['pdo'];
$database->initializeSchema();

$deviceService = new App\Services\DeviceService($pdo);
$interfaceService = new App\Services\InterfaceService($pdo);
$reader = new App\Support\LegacyExportReader();

$metadata = null;
$legacyDevices = [];
$trafficRanges = [];

$reader->stream(
    $inputPath,
    static function (array $row) use (&$metadata): void {
        $metadata = $row;
    },
    static function (array $row) use (&$legacyDevices): void {
        $serial = trim((string) ($row['serial_number'] ?? ''));
        if ($serial === '') {
            return;
        }

        $legacyDevices[$serial] = [
            'serial_number' => $serial,
            'name' => $row['name'] ?? null,
            'comment' => $row['comment'] ?? null,
            'last_seen_at' => $row['last_seen_at'] ?? null,
            'last_tx' => $row['last_tx'] ?? null,
            'last_rx' => $row['last_rx'] ?? null,
        ];
    },
    static function (array $row): void {
    },
    static function (array $row) use (&$trafficRanges): void {
        $serial = trim((string) ($row['serial_number'] ?? ''));
        $recordedAt = trim((string) ($row['recorded_at'] ?? ''));
        if ($serial === '' || $recordedAt === '') {
            return;
        }

        if (!isset($trafficRanges[$serial])) {
            $trafficRanges[$serial] = [
                'count' => 0,
                'min_recorded_at' => $recordedAt,
                'max_recorded_at' => $recordedAt,
            ];
        }

        $trafficRanges[$serial]['count']++;
        if ($recordedAt < $trafficRanges[$serial]['min_recorded_at']) {
            $trafficRanges[$serial]['min_recorded_at'] = $recordedAt;
        }
        if ($recordedAt > $trafficRanges[$serial]['max_recorded_at']) {
            $trafficRanges[$serial]['max_recorded_at'] = $recordedAt;
        }
    }
);

if (!is_array($metadata) || ($metadata['schema'] ?? null) !== 'legacy-sqlite-v1') {
    fwrite(STDERR, "Unsupported import schema.\n");
    exit(1);
}

$existingDevices = $deviceService->listDevices();

fwrite(STDOUT, "Target database devices:\n");
if ($existingDevices === []) {
    fwrite(STDOUT, "  (no devices found in target database)\n");
} else {
    foreach ($existingDevices as $device) {
        fwrite(STDOUT, sprintf(
            "  [%d] %s%s%s\n",
            $device->id,
            $device->serialNumber,
            $device->name !== null ? ' (' . $device->name . ')' : '',
            $device->comment !== null && $device->comment !== ''
                ? ' - ' . $device->comment
                : ''
        ));

        foreach ($interfaceService->listByDeviceId($device->id) as $interface) {
            fwrite(STDOUT, sprintf(
                "       - %s%s%s\n",
                $interface->name,
                $interface->displayName !== null && $interface->displayName !== $interface->name
                    ? ' [' . $interface->displayName . ']'
                    : '',
                $interface->comment !== null && $interface->comment !== ''
                    ? ' - ' . $interface->comment
                    : ''
            ));
        }
    }
}

$mapping = [
    'legacy_devices' => [],
];

ksort($legacyDevices);

foreach ($legacyDevices as $serial => $deviceRow) {
    $range = $trafficRanges[$serial] ?? null;

    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Legacy device\n");
    fwrite(STDOUT, sprintf("  serial: %s\n", $serial));
    fwrite(STDOUT, sprintf("  name: %s\n", (string) ($deviceRow['name'] ?? '')));
    fwrite(STDOUT, sprintf("  comment: %s\n", (string) ($deviceRow['comment'] ?? '')));
    fwrite(STDOUT, sprintf("  last_seen_at: %s\n", (string) ($deviceRow['last_seen_at'] ?? '')));
    if ($range !== null) {
        fwrite(STDOUT, sprintf(
            "  traffic: %d samples from %s to %s\n",
            $range['count'],
            $range['min_recorded_at'],
            $range['max_recorded_at']
        ));
    }

    $skipImport = promptYesNo('Skip this legacy device?', false);
    if ($skipImport) {
        $mapping['legacy_devices'][$serial] = [
            'skip' => true,
        ];
        continue;
    }

    $defaultInterfaceName = sanitizeInterfaceName($serial);
    $targetDeviceInput = prompt(
        "Target device serial or existing device ID",
        $serial
    );

    $selectedDevice = resolveTargetDevice($targetDeviceInput, $existingDevices);
    $targetSerial = $selectedDevice?->serialNumber ?? trim($targetDeviceInput);
    if ($targetSerial === '') {
        $targetSerial = $serial;
    }

    $defaultDeviceName = $selectedDevice?->name ?? nullableString($deviceRow['name'] ?? null);
    $defaultDeviceComment = $selectedDevice?->comment ?? nullableString($deviceRow['comment'] ?? null);

    $existingInterfaces = [];
    if ($selectedDevice !== null) {
        $existingInterfaces = $interfaceService->listByDeviceId($selectedDevice->id);
        if ($existingInterfaces !== []) {
            fwrite(STDOUT, "  existing target interfaces:\n");
            foreach (array_values($existingInterfaces) as $index => $interface) {
                fwrite(STDOUT, sprintf(
                    "    [%d] %s%s\n",
                    $index + 1,
                    $interface->name,
                    $interface->displayName !== null && $interface->displayName !== $interface->name
                        ? ' [' . $interface->displayName . ']'
                        : ''
                ));
            }
        }
    }

    $targetDeviceName = $defaultDeviceName;
    $targetDeviceComment = $defaultDeviceComment;

    if ($selectedDevice === null || promptYesNo('Override target device metadata?', false)) {
        $targetDeviceName = promptNullable("Target device name", $defaultDeviceName);
        $targetDeviceComment = promptNullable("Target device comment", $defaultDeviceComment);
    }

    $interfacePrompt = 'Target interface name';
    if ($existingInterfaces !== []) {
        $choices = [];
        foreach (array_values($existingInterfaces) as $index => $interface) {
            $choices[] = sprintf('%d=%s', $index + 1, $interface->name);
        }
        $interfacePrompt .= ' (' . implode(', ', $choices) . ')';
    }

    $targetInterfaceInput = prompt($interfacePrompt, $defaultInterfaceName);
    $targetInterfaceName = resolveTargetInterfaceName(
        $targetInterfaceInput,
        $existingInterfaces
    );
    if ($targetInterfaceName === '') {
        $targetInterfaceName = $defaultInterfaceName;
    }
    $targetInterfaceDisplayName = promptNullable("Target interface display name", $targetInterfaceName);
    $targetInterfaceComment = promptNullable(
        "Target interface comment",
        sprintf('Imported from legacy device %s', $serial)
    );

    $mapping['legacy_devices'][$serial] = [
        'target_serial_number' => $targetSerial,
        'target_device_name' => $targetDeviceName,
        'target_device_comment' => $targetDeviceComment,
        'target_interface_name' => $targetInterfaceName,
        'target_interface_display_name' => $targetInterfaceDisplayName,
        'target_interface_comment' => $targetInterfaceComment,
    ];
}

$outputDirectory = dirname($outputPath);
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
    fwrite(STDERR, "Unable to create output directory: {$outputDirectory}\n");
    exit(1);
}

$encoded = json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($encoded === false) {
    fwrite(STDERR, "Unable to encode mapping JSON.\n");
    exit(1);
}

file_put_contents($outputPath, $encoded . PHP_EOL);

fwrite(STDOUT, "\nMapping written to {$outputPath}\n");
fwrite(STDOUT, "Import with:\n");
fwrite(STDOUT, sprintf(
    "  php scripts/import-legacy-json.php %s --mapping=%s\n",
    escapeshellarg($inputPath),
    escapeshellarg($outputPath)
));

/**
 * @param array<int, \App\Models\Device> $existingDevices
 */
function resolveTargetDevice(string $input, array $existingDevices): ?\App\Models\Device
{
    $trimmed = trim($input);
    if ($trimmed === '') {
        return null;
    }

    if (ctype_digit($trimmed)) {
        $targetId = (int) $trimmed;
        foreach ($existingDevices as $device) {
            if ($device->id === $targetId) {
                return $device;
            }
        }
    }

    foreach ($existingDevices as $device) {
        if ($device->serialNumber === $trimmed) {
            return $device;
        }
    }

    return null;
}

function prompt(string $label, string $default = ''): string
{
    $suffix = $default !== '' ? " [{$default}]" : '';
    $prompt = "{$label}{$suffix}: ";

    if (function_exists('readline')) {
        $value = readline($prompt);
    } else {
        fwrite(STDOUT, $prompt);
        $value = fgets(STDIN);
        $value = $value === false ? '' : $value;
    }

    $trimmed = trim($value);

    return $trimmed !== '' ? $trimmed : $default;
}

function promptNullable(string $label, ?string $default = null): ?string
{
    $value = prompt($label, $default ?? '');
    $trimmed = trim($value);

    return $trimmed === '' ? null : $trimmed;
}

function promptYesNo(string $label, bool $default = false): bool
{
    $defaultLabel = $default ? 'Y/n' : 'y/N';
    $value = prompt($label . " ({$defaultLabel})", '');
    $trimmed = strtolower(trim($value));

    if ($trimmed === '') {
        return $default;
    }

    return in_array($trimmed, ['y', 'yes', '1', 'true'], true);
}

function sanitizeInterfaceName(string $input): string
{
    $sanitized = strtolower(trim($input));
    $sanitized = preg_replace('/[^a-z0-9._-]+/', '-', $sanitized) ?? '';
    $sanitized = trim($sanitized, '-');

    return $sanitized !== '' ? substr($sanitized, 0, 128) : 'legacy';
}

function nullableString(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $string = trim((string) $value);

    return $string === '' ? null : $string;
}

/**
 * @param array<int, \App\Models\InterfaceModel> $existingInterfaces
 */
function resolveTargetInterfaceName(string $input, array $existingInterfaces): string
{
    $trimmed = trim($input);
    if ($trimmed === '') {
        return '';
    }

    if (ctype_digit($trimmed)) {
        $index = (int) $trimmed - 1;
        if (isset($existingInterfaces[$index])) {
            return $existingInterfaces[$index]->name;
        }
    }

    foreach ($existingInterfaces as $interface) {
        if ($interface->name === $trimmed) {
            return $interface->name;
        }
    }

    return $trimmed;
}
