<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$arguments = $argv;
array_shift($arguments);

if (count($arguments) < 1) {
    fwrite(STDERR, "Usage: php scripts/import-legacy-json.php <export-json-path>\n");
    exit(1);
}

$inputPath = $arguments[0];

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
$trafficService = new App\Services\TrafficService($pdo, $database);
$legacyImportService = new App\Services\LegacyImportService($pdo, $deviceService, $interfaceService, $trafficService);
$reader = new App\Support\LegacyExportReader();

$metadata = null;

try {
    $legacyImportService->beginImport();
    $reader->stream(
        $inputPath,
        static function (array $row) use (&$metadata): void {
            $metadata = $row;
        },
        static function (array $row) use ($legacyImportService): void {
            $legacyImportService->importDeviceRow($row);
        },
        static function (array $row) use ($legacyImportService): void {
            $legacyImportService->importInterfaceRow($row);
        },
        static function (array $row) use ($legacyImportService): void {
            $legacyImportService->importTrafficRow($row);
        }
    );

    if (!is_array($metadata) || ($metadata['schema'] ?? null) !== 'legacy-sqlite-v1') {
        throw new RuntimeException('Unsupported import schema.');
    }

    $result = $legacyImportService->finishImport();
} catch (Throwable $exception) {
    $legacyImportService->rollbackImport();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Imported %d devices, %d interfaces, %d traffic samples, %d synthetic snapshots\n",
    $result['devices'],
    $result['interfaces'],
    $result['traffic_samples'],
    $result['synthetic_snapshots']
));
