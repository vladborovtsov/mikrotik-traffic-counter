<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class LegacyImportRollbackManifest
{
    /** @var array<int, int> */
    private array $trafficSampleIds = [];

    public function __construct(
        private readonly string $path,
        private readonly string $sourcePath,
        private readonly ?string $mappingPath,
        private readonly string $targetDriver
    ) {
    }

    public function recordTrafficSampleId(int $id): void
    {
        $this->trafficSampleIds[] = $id;
    }

    public function write(): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create rollback manifest directory: %s', $directory));
        }

        $payload = [
            'type' => 'legacy-import-rollback',
            'created_at' => date('Y-m-d H:i:s'),
            'source_path' => $this->sourcePath,
            'mapping_path' => $this->mappingPath,
            'target_driver' => $this->targetDriver,
            'traffic_sample_ids' => $this->trafficSampleIds,
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode rollback manifest.');
        }

        file_put_contents($this->path, $encoded . PHP_EOL);
    }

    public function path(): string
    {
        return $this->path;
    }
}
