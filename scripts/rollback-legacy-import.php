<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$arguments = $argv;
array_shift($arguments);

if (count($arguments) < 1) {
    fwrite(STDERR, "Usage: php scripts/rollback-legacy-import.php <rollback-manifest-path>\n");
    exit(1);
}

$manifestPath = $arguments[0];

if (!is_file($manifestPath)) {
    fwrite(STDERR, "Rollback manifest not found: {$manifestPath}\n");
    exit(1);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($manifest) || ($manifest['type'] ?? null) !== 'legacy-import-rollback') {
    fwrite(STDERR, "Unsupported rollback manifest.\n");
    exit(1);
}

$trafficSampleIds = array_values(array_filter(
    array_map(
        static fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null,
        is_array($manifest['traffic_sample_ids'] ?? null) ? $manifest['traffic_sample_ids'] : []
    ),
    static fn (?int $id): bool => $id !== null && $id > 0
));

if ($trafficSampleIds === []) {
    fwrite(STDOUT, "No traffic sample IDs found in rollback manifest.\n");
    exit(0);
}

/** @var array{config: \App\Config\Configuration, database: \App\Database\Database, pdo: \PDO} $runtime */
$runtime = require __DIR__ . '/../src/bootstrap.php';
$database = $runtime['database'];
$pdo = $runtime['pdo'];
$database->initializeSchema();

fwrite(STDOUT, "Starting rollback\n");
fwrite(STDOUT, sprintf("  manifest: %s\n", realpath($manifestPath) ?: $manifestPath));
fwrite(STDOUT, sprintf("  traffic sample IDs: %d\n", count($trafficSampleIds)));

$pdo->beginTransaction();

try {
    $deleted = 0;
    foreach (array_chunk($trafficSampleIds, 1000) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("DELETE FROM traffic_samples WHERE id IN ({$placeholders})");
        $stmt->execute($chunk);
        $deleted += $stmt->rowCount();
        fwrite(STDOUT, sprintf("Deleted traffic samples so far: %d\n", $deleted));
        fflush(STDOUT);
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, sprintf("Rollback completed, deleted %d traffic samples\n", $deleted));
