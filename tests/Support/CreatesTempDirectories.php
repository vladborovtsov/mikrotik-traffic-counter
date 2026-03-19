<?php

declare(strict_types=1);

namespace App\Tests\Support;

trait CreatesTempDirectories
{
    /** @var array<int, string> */
    private array $temporaryDirectories = [];

    protected function createTempDirectory(): string
    {
        $path = sys_get_temp_dir() . '/mikrotik-tests-' . bin2hex(random_bytes(6));

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Unable to create temp directory: %s', $path));
        }

        $this->temporaryDirectories[] = $path;

        return $path;
    }

    protected function removeTemporaryDirectories(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }

        $this->temporaryDirectories = [];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
