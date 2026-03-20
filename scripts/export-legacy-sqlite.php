<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$arguments = $argv;
array_shift($arguments);

if (count($arguments) < 2) {
    fwrite(STDERR, "Usage: php scripts/export-legacy-sqlite.php <legacy-sqlite-path> <output-json-path> [interface-name]\n");
    exit(1);
}

[$legacyPath, $outputPath] = $arguments;
$interfaceName = $arguments[2] ?? 'legacy';

if (!is_file($legacyPath)) {
    fwrite(STDERR, "Legacy SQLite file not found: {$legacyPath}\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $legacyPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['devices', 'traffic'] as $requiredTable) {
    if (!in_array($requiredTable, $tables, true)) {
        fwrite(STDERR, "Legacy schema missing required table: {$requiredTable}\n");
        exit(1);
    }
}

$deviceMap = [];
$directory = dirname($outputPath);
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    fwrite(STDERR, "Unable to create output directory: {$directory}\n");
    exit(1);
}

$handle = fopen($outputPath, 'wb');
if ($handle === false) {
    fwrite(STDERR, "Unable to open output file for writing: {$outputPath}\n");
    exit(1);
}

fwrite($handle, "{\n");
writeJsonProperty($handle, 'metadata', [
    'schema' => 'legacy-sqlite-v1',
    'exported_at' => gmdate('Y-m-d H:i:s'),
    'source_path' => realpath($legacyPath) ?: $legacyPath,
    'interface_name' => $interfaceName,
], true);

$deviceStatement = $pdo->query('SELECT id, sn, comment, last_check, last_tx, last_rx FROM devices ORDER BY id ASC');
writeJsonArrayStart($handle, 'devices');
$first = true;

while ($device = $deviceStatement->fetch()) {
    $serialNumber = (string) ($device['sn'] ?? '');

    if ($serialNumber === '') {
        continue;
    }

    $deviceMap[(int) $device['id']] = $serialNumber;

    writeJsonArrayItem($handle, [
        'legacy_device_id' => (int) $device['id'],
        'serial_number' => $serialNumber,
        'name' => null,
        'comment' => $device['comment'] !== null ? (string) $device['comment'] : null,
        'last_seen_at' => $device['last_check'] !== null ? (string) $device['last_check'] : null,
        'last_tx' => $device['last_tx'] !== null ? (int) $device['last_tx'] : null,
        'last_rx' => $device['last_rx'] !== null ? (int) $device['last_rx'] : null,
    ], $first);
    $first = false;
}

writeJsonArrayEnd($handle, true);

$deviceStatement = $pdo->query('SELECT id, sn FROM devices ORDER BY id ASC');
writeJsonArrayStart($handle, 'interfaces');
$first = true;

while ($device = $deviceStatement->fetch()) {
    $serialNumber = (string) ($device['sn'] ?? '');

    if ($serialNumber === '') {
        continue;
    }

    writeJsonArrayItem($handle, [
        'legacy_device_id' => (int) $device['id'],
        'serial_number' => $serialNumber,
        'name' => $interfaceName,
        'display_name' => $interfaceName,
        'comment' => 'Imported from legacy SQLite export',
    ], $first);
    $first = false;
}

writeJsonArrayEnd($handle, true);

$trafficStatement = $pdo->query('SELECT id, device_id, timestamp, tx, rx FROM traffic ORDER BY id ASC');
writeJsonArrayStart($handle, 'traffic_samples');
$first = true;

while ($sample = $trafficStatement->fetch()) {
    $deviceId = (int) $sample['device_id'];
    $serialNumber = $deviceMap[$deviceId] ?? null;

    if ($serialNumber === null) {
        continue;
    }

    writeJsonArrayItem($handle, [
        'legacy_sample_id' => (int) $sample['id'],
        'legacy_device_id' => $deviceId,
        'serial_number' => $serialNumber,
        'interface_name' => $interfaceName,
        'recorded_at' => (string) $sample['timestamp'],
        'raw_tx' => null,
        'raw_rx' => null,
        'delta_tx' => (int) ($sample['tx'] ?? 0),
        'delta_rx' => (int) ($sample['rx'] ?? 0),
    ], $first);
    $first = false;
}

writeJsonArrayEnd($handle, false);
fwrite($handle, "}\n");
fclose($handle);

fwrite(STDOUT, "Export written to {$outputPath}\n");

function writeJsonProperty($handle, string $name, array $value, bool $withComma): void
{
    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException(sprintf('Unable to encode JSON property: %s', $name));
    }

    $indented = preg_replace('/^/m', '  ', $encoded);
    fwrite($handle, sprintf("  \"%s\": %s%s\n", $name, $indented, $withComma ? ',' : ''));
}

function writeJsonArrayStart($handle, string $name): void
{
    fwrite($handle, sprintf("  \"%s\": [\n", $name));
}

function writeJsonArrayItem($handle, array $value, bool $first): void
{
    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Unable to encode JSON array item.');
    }

    $prefix = $first ? '' : ",\n";
    $indented = preg_replace('/^/m', '    ', $encoded);
    fwrite($handle, $prefix . $indented);
}

function writeJsonArrayEnd($handle, bool $withComma): void
{
    fwrite($handle, sprintf("\n  ]%s\n", $withComma ? ',' : ''));
}
