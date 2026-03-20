<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$arguments = $argv;
array_shift($arguments);

if (count($arguments) < 1) {
    fwrite(STDERR, "Usage: php scripts/import-legacy-json.php <export-json-path> [--mapping=/path/to/mapping.json]\n");
    exit(1);
}

$inputPath = null;
$mappingPath = null;

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--mapping=')) {
        $mappingPath = substr($argument, strlen('--mapping='));
        continue;
    }

    if ($inputPath === null) {
        $inputPath = $argument;
    }
}

if ($inputPath === null) {
    fwrite(STDERR, "Export JSON file path is required.\n");
    exit(1);
}

if (!is_file($inputPath)) {
    fwrite(STDERR, "Export JSON file not found: {$inputPath}\n");
    exit(1);
}

$startedAt = microtime(true);
$mapping = [];
if ($mappingPath !== null) {
    if (!is_file($mappingPath)) {
        fwrite(STDERR, "Mapping JSON file not found: {$mappingPath}\n");
        exit(1);
    }

    $decodedMapping = json_decode((string) file_get_contents($mappingPath), true);
    if (!is_array($decodedMapping)) {
        fwrite(STDERR, "Unable to decode mapping JSON: {$mappingPath}\n");
        exit(1);
    }

    $mapping = $decodedMapping;
}

/** @var array{config: \App\Config\Configuration, database: \App\Database\Database, pdo: \PDO} $runtime */
$runtime = require __DIR__ . '/../src/bootstrap.php';
$config = $runtime['config'];
$database = $runtime['database'];
$pdo = $runtime['pdo'];
$database->initializeSchema();

$deviceService = new App\Services\DeviceService($pdo);
$interfaceService = new App\Services\InterfaceService($pdo);
$trafficService = new App\Services\TrafficService($pdo, $database);
$mappingService = new App\Services\LegacyImportMappingService($mapping);
$rollbackManifestPath = sprintf(
    'var/legacy-import-rollback-%s.json',
    date('Ymd-His')
);
$rollbackManifest = new App\Support\LegacyImportRollbackManifest(
    $rollbackManifestPath,
    realpath($inputPath) ?: $inputPath,
    $mappingPath !== null ? (realpath($mappingPath) ?: $mappingPath) : null,
    $database->driver()
);
$legacyImportService = new App\Services\LegacyImportService(
    $pdo,
    $deviceService,
    $interfaceService,
    $trafficService,
    $mappingService,
    $rollbackManifest
);
$reader = new App\Support\LegacyExportReader();

$metadata = null;
$processed = [
    'devices' => 0,
    'interfaces' => 0,
    'traffic_samples' => 0,
];
$totals = [
    'devices' => 0,
    'interfaces' => 0,
    'traffic_samples' => 0,
];

fwrite(STDOUT, "Starting legacy import\n");
fwrite(STDOUT, sprintf("  source: %s\n", realpath($inputPath) ?: $inputPath));
fwrite(STDOUT, sprintf("  source size: %d bytes\n", filesize($inputPath) ?: 0));
fwrite(STDOUT, sprintf("  target driver: %s\n", $database->driver()));
if ($database->driver() === 'mysql') {
    fwrite(STDOUT, sprintf(
        "  target mysql: %s:%d/%s\n",
        $config->requireString('DB_MYSQL_HOST'),
        $config->getInt('DB_MYSQL_PORT', 3306),
        $config->requireString('DB_MYSQL_DATABASE')
    ));
} else {
    fwrite(STDOUT, sprintf(
        "  target sqlite: %s\n",
        $config->requireString('DB_SQLITE_PATH')
    ));
}
fwrite(STDOUT, sprintf(
    "  mapping: %s\n",
    $mappingPath !== null ? (realpath($mappingPath) ?: $mappingPath) : 'none'
));
fwrite(STDOUT, sprintf("  rollback manifest: %s\n", $rollbackManifestPath));
fwrite(STDOUT, "Scanning export for totals...\n");
fflush(STDOUT);

$reader->stream(
    $inputPath,
    static function (array $row): void {
    },
    static function (array $row) use (&$totals, $mappingService): void {
        $mapped = $mappingService->mapDeviceRow($row);
        if ($mapped !== [] && trim((string) ($mapped['serial_number'] ?? '')) !== '') {
            $totals['devices']++;
        }
    },
    static function (array $row) use (&$totals, $mappingService): void {
        $mapped = $mappingService->mapInterfaceRow($row);
        if (
            $mapped !== []
            && trim((string) ($mapped['serial_number'] ?? '')) !== ''
            && trim((string) ($mapped['name'] ?? '')) !== ''
        ) {
            $totals['interfaces']++;
        }
    },
    static function (array $row) use (&$totals, $mappingService): void {
        $mapped = $mappingService->mapTrafficRow($row);
        if (
            $mapped !== []
            && trim((string) ($mapped['serial_number'] ?? '')) !== ''
            && trim((string) ($mapped['interface_name'] ?? '')) !== ''
        ) {
            $totals['traffic_samples']++;
        }
    }
);

fwrite(STDOUT, sprintf(
    "  totals after mapping: %d devices, %d interfaces, %d traffic samples\n",
    $totals['devices'],
    $totals['interfaces'],
    $totals['traffic_samples']
));
fflush(STDOUT);

try {
    $legacyImportService->beginImport();
    $reader->stream(
        $inputPath,
        static function (array $row) use (&$metadata): void {
            $metadata = $row;
            fwrite(STDOUT, "Metadata\n");
            fwrite(STDOUT, sprintf("  schema: %s\n", (string) ($row['schema'] ?? '')));
            fwrite(STDOUT, sprintf("  exported_at: %s\n", (string) ($row['exported_at'] ?? '')));
            fwrite(STDOUT, sprintf("  source_path: %s\n", (string) ($row['source_path'] ?? '')));
            fwrite(STDOUT, sprintf("  interface_name: %s\n", (string) ($row['interface_name'] ?? '')));
            fflush(STDOUT);
        },
        static function (array $row) use ($legacyImportService, &$processed): void {
            $processed['devices']++;
            $legacyImportService->importDeviceRow($row);
            fwrite(STDOUT, sprintf(
                "Processed legacy device %d: %s\n",
                $processed['devices'],
                (string) ($row['serial_number'] ?? '')
            ));
            fflush(STDOUT);
        },
        static function (array $row) use ($legacyImportService, &$processed): void {
            $processed['interfaces']++;
            $legacyImportService->importInterfaceRow($row);
            fwrite(STDOUT, sprintf(
                "Processed legacy interface %d: %s / %s\n",
                $processed['interfaces'],
                (string) ($row['serial_number'] ?? ''),
                (string) ($row['name'] ?? '')
            ));
            fflush(STDOUT);
        },
        static function (array $row) use ($legacyImportService, &$processed, $totals): void {
            $processed['traffic_samples']++;
            $legacyImportService->importTrafficRow($row);
            if ($processed['traffic_samples'] === 1 || $processed['traffic_samples'] % 10 === 0) {
                $percent = $totals['traffic_samples'] > 0
                    ? ($processed['traffic_samples'] / $totals['traffic_samples']) * 100
                    : 0.0;
                fwrite(STDOUT, sprintf(
                    "Processed legacy traffic samples: %d / %d (%.2f%%, latest %s / %s)\n",
                    $processed['traffic_samples'],
                    $totals['traffic_samples'],
                    $percent,
                    (string) ($row['serial_number'] ?? ''),
                    (string) ($row['recorded_at'] ?? '')
                ));
                fflush(STDOUT);
            }
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

fwrite(STDOUT, "Import completed\n");
fwrite(STDOUT, sprintf("  processed devices: %d\n", $processed['devices']));
fwrite(STDOUT, sprintf("  processed interfaces: %d\n", $processed['interfaces']));
fwrite(STDOUT, sprintf("  processed traffic samples: %d\n", $processed['traffic_samples']));
fwrite(STDOUT, sprintf(
    "  imported devices: %d\n  imported interfaces: %d\n  imported traffic samples: %d\n  synthetic snapshots: %d\n",
    $result['devices'],
    $result['interfaces'],
    $result['traffic_samples'],
    $result['synthetic_snapshots']
));
fwrite(STDOUT, sprintf("  elapsed: %.2f seconds\n", microtime(true) - $startedAt));
fwrite(STDOUT, sprintf("  rollback manifest written: %s\n", $rollbackManifestPath));
fflush(STDOUT);
