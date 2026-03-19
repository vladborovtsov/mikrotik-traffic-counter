<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\ConfigurationFactory;
use App\Database\DatabaseFactory;
use App\Services\DeviceService;
use App\Services\InterfaceService;
use App\Services\LegacyImportService;
use App\Services\TrafficService;
use App\Tests\Support\CreatesTempDirectories;
use PHPUnit\Framework\TestCase;

final class LegacyImportServiceTest extends TestCase
{
    use CreatesTempDirectories;

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();
    }

    public function testImportCreatesDevicesInterfacesTrafficAndSyntheticSnapshot(): void
    {
        $root = $this->createTempDirectory();
        $databasePath = $root . '/import.sqlite';

        $configuration = ConfigurationFactory::create($root, [
            'DB_DRIVER' => 'sqlite',
            'DB_SQLITE_PATH' => $databasePath,
        ]);
        $database = DatabaseFactory::create($configuration);
        $database->initializeSchema();
        $pdo = $database->pdo();

        $deviceService = new DeviceService($pdo);
        $interfaceService = new InterfaceService($pdo);
        $trafficService = new TrafficService($pdo, $database);
        $legacyImportService = new LegacyImportService($pdo, $deviceService, $interfaceService, $trafficService);

        $result = $legacyImportService->import([
            'metadata' => [
                'schema' => 'legacy-sqlite-v1',
                'interface_name' => 'legacy',
            ],
            'devices' => [[
                'legacy_device_id' => 1,
                'serial_number' => 'LEGACY123456',
                'name' => 'Imported Device',
                'comment' => 'Imported comment',
                'last_seen_at' => '2025-06-13 00:30:27',
                'last_tx' => 9999,
                'last_rx' => 8888,
            ]],
            'interfaces' => [[
                'legacy_device_id' => 1,
                'serial_number' => 'LEGACY123456',
                'name' => 'legacy',
                'display_name' => 'Legacy Interface',
                'comment' => 'Imported from test',
            ]],
            'traffic_samples' => [[
                'legacy_sample_id' => 1,
                'legacy_device_id' => 1,
                'serial_number' => 'LEGACY123456',
                'interface_name' => 'legacy',
                'recorded_at' => '2025-06-13 00:00:00',
                'raw_tx' => null,
                'raw_rx' => null,
                'delta_tx' => 100,
                'delta_rx' => 200,
            ], [
                'legacy_sample_id' => 2,
                'legacy_device_id' => 1,
                'serial_number' => 'LEGACY123456',
                'interface_name' => 'legacy',
                'recorded_at' => '2025-06-13 01:00:00',
                'raw_tx' => null,
                'raw_rx' => null,
                'delta_tx' => 300,
                'delta_rx' => 400,
            ]],
        ]);

        self::assertSame([
            'devices' => 1,
            'interfaces' => 1,
            'traffic_samples' => 2,
            'synthetic_snapshots' => 1,
        ], $result);

        $device = $deviceService->getDeviceBySerial('LEGACY123456');
        self::assertNotNull($device);
        self::assertSame('Imported Device', $device->name);

        $interfaces = $interfaceService->listByDeviceId($device->id);
        self::assertCount(1, $interfaces);
        self::assertSame('Legacy Interface', $interfaces[0]->displayName);

        $totals = $trafficService->getTotalStats($device->id, $interfaces[0]->id);
        self::assertSame(400.0, $totals['sumtx']);
        self::assertSame(600.0, $totals['sumrx']);

        $lastCounters = $trafficService->getDeviceLastCounters($device->id);
        self::assertSame(9999.0, $lastCounters['last_tx']);
        self::assertSame(8888.0, $lastCounters['last_rx']);
    }
}
