<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class LegacyExportReader
{
    /**
     * @param callable(array<string, mixed>): void $onMetadata
     * @param callable(array<string, mixed>): void $onDevice
     * @param callable(array<string, mixed>): void $onInterface
     * @param callable(array<string, mixed>): void $onTrafficSample
     */
    public function stream(
        string $path,
        callable $onMetadata,
        callable $onDevice,
        callable $onInterface,
        callable $onTrafficSample
    ): void {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open export file: %s', $path));
        }

        $currentArraySection = null;
        $collectingObject = false;
        $buffer = '';
        $depth = 0;

        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if ($collectingObject) {
                $buffer .= $line;
                $depth += substr_count($line, '{');
                $depth -= substr_count($line, '}');

                if ($depth <= 0) {
                    $decoded = json_decode(rtrim(rtrim($buffer), ",\n"), true);
                    if (!is_array($decoded)) {
                        throw new RuntimeException('Unable to decode streamed JSON object.');
                    }

                    match ($currentArraySection) {
                        'devices' => $onDevice($decoded),
                        'interfaces' => $onInterface($decoded),
                        'traffic_samples' => $onTrafficSample($decoded),
                        default => throw new RuntimeException('Unknown JSON array section.'),
                    };

                    $collectingObject = false;
                    $buffer = '';
                    $depth = 0;
                }

                continue;
            }

            if (preg_match('/^"metadata":\s*\{$/', $trimmed) === 1 || preg_match('/^"metadata":\s*\{\s*$/', $trimmed) === 1) {
                $metadataBuffer = "{\n";
                $metadataDepth = 1;

                while (($metadataLine = fgets($handle)) !== false) {
                    $metadataBuffer .= $metadataLine;
                    $metadataDepth += substr_count($metadataLine, '{');
                    $metadataDepth -= substr_count($metadataLine, '}');

                    if ($metadataDepth <= 0) {
                        break;
                    }
                }

                $metadata = json_decode(rtrim(rtrim($metadataBuffer), ",\n"), true);
                if (!is_array($metadata)) {
                    throw new RuntimeException('Unable to decode metadata block.');
                }

                $onMetadata($metadata);
                continue;
            }

            if (($trimmed === '{' || $trimmed === '}') && $currentArraySection === null) {
                continue;
            }

            if (preg_match('/^"(devices|interfaces|traffic_samples)":\s*\[$/', $trimmed, $matches) === 1) {
                $currentArraySection = $matches[1];
                continue;
            }

            if ($trimmed === '],' || $trimmed === ']') {
                $currentArraySection = null;
                continue;
            }

            if ($currentArraySection !== null && str_starts_with($trimmed, '{')) {
                $collectingObject = true;
                $buffer = $line;
                $depth = substr_count($line, '{') - substr_count($line, '}');

                if ($depth <= 0) {
                    $decoded = json_decode(rtrim(rtrim($buffer), ",\n"), true);
                    if (!is_array($decoded)) {
                        throw new RuntimeException('Unable to decode streamed JSON object.');
                    }

                    match ($currentArraySection) {
                        'devices' => $onDevice($decoded),
                        'interfaces' => $onInterface($decoded),
                        'traffic_samples' => $onTrafficSample($decoded),
                        default => throw new RuntimeException('Unknown JSON array section.'),
                    };

                    $collectingObject = false;
                    $buffer = '';
                    $depth = 0;
                }
            }
        }

        fclose($handle);
    }
}
