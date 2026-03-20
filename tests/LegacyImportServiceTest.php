<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\ConfigurationFactory;
use App\Database\DatabaseFactory;
use App\Services\DeviceService;
use App\Services\InterfaceService;
use App\Services\LegacyImportService;
use App\Services\LegacyImportMappingService;
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

    public function testImportMappingCanMergeLegacyDevicesIntoOneTargetDeviceWithDifferentInterfaces(): void
    {
        $root = $this->createTempDirectory();
        $databasePath = $root . '/import-mapped.sqlite';

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
        $mappingService = new LegacyImportMappingService([
            'legacy_devices' => [
                'LEGACY_WIFI_5G' => [
                    'target_serial_number' => 'REALDEVICE123',
                    'target_device_name' => 'Merged Router',
                    'target_device_comment' => 'Imported merged device',
                    'target_interface_name' => 'wifi5g',
                    'target_interface_display_name' => 'WiFi 5G',
                ],
                'LEGACY_WIFI_24' => [
                    'target_serial_number' => 'REALDEVICE123',
                    'target_device_name' => 'Merged Router',
                    'target_device_comment' => 'Imported merged device',
                    'target_interface_name' => 'wifi24',
                    'target_interface_display_name' => 'WiFi 2.4G',
                ],
            ],
        ]);
        $legacyImportService = new LegacyImportService($pdo, $deviceService, $interfaceService, $trafficService, $mappingService);

        $result = $legacyImportService->import([
            'metadata' => [
                'schema' => 'legacy-sqlite-v1',
                'interface_name' => 'legacy',
            ],
            'devices' => [[
                'legacy_device_id' => 1,
                'serial_number' => 'LEGACY_WIFI_5G',
                'name' => null,
                'comment' => null,
                'last_seen_at' => '2025-06-13 00:30:27',
                'last_tx' => 1000,
                'last_rx' => 2000,
            ], [
                'legacy_device_id' => 2,
                'serial_number' => 'LEGACY_WIFI_24',
                'name' => null,
                'comment' => null,
                'last_seen_at' => '2025-06-13 00:30:27',
                'last_tx' => 3000,
                'last_rx' => 4000,
            ]],
            'interfaces' => [[
                'legacy_device_id' => 1,
                'serial_number' => 'LEGACY_WIFI_5G',
                'name' => 'legacy',
                'display_name' => 'Legacy 5G',
                'comment' => null,
            ], [
                'legacy_device_id' => 2,
                'serial_number' => 'LEGACY_WIFI_24',
                'name' => 'legacy',
                'display_name' => 'Legacy 2.4G',
                'comment' => null,
            ]],
            'traffic_samples' => [[
                'legacy_sample_id' => 1,
                'legacy_device_id' => 1,
                'serial_number' => 'LEGACY_WIFI_5G',
                'interface_name' => 'legacy',
                'recorded_at' => '2025-06-13 00:00:00',
                'raw_tx' => null,
                'raw_rx' => null,
                'delta_tx' => 100,
                'delta_rx' => 200,
            ], [
                'legacy_sample_id' => 2,
                'legacy_device_id' => 2,
                'serial_number' => 'LEGACY_WIFI_24',
                'interface_name' => 'legacy',
                'recorded_at' => '2025-06-13 00:00:00',
                'raw_tx' => null,
                'raw_rx' => null,
                'delta_tx' => 300,
                'delta_rx' => 400,
            ]],
        ]);

        self::assertSame(2, $result['devices']);
        self::assertSame(2, $result['interfaces']);
        self::assertSame(2, $result['traffic_samples']);

        $device = $deviceService->getDeviceBySerial('REALDEVICE123');
        self::assertNotNull($device);
        self::assertSame('Merged Router', $device->name);

        $interfaces = $interfaceService->listByDeviceId($device->id);
        self::assertCount(2, $interfaces);
        self::assertSame(['wifi24', 'wifi5g'], array_map(static fn ($interface) => $interface->name, $interfaces));

        $totals = $trafficService->getTotalStats($device->id);
        self::assertSame(400.0, $totals['sumtx']);
        self::assertSame(600.0, $totals['sumrx']);

        $interfacesByName = [];
        foreach ($interfaces as $interface) {
            $interfacesByName[$interface->name] = $interface;
        }

        $wifi5Latest = $trafficService->getLatestSampleForInterface($interfacesByName['wifi5g']->id);
        $wifi24Latest = $trafficService->getLatestSampleForInterface($interfacesByName['wifi24']->id);

        self::assertNotNull($wifi5Latest);
        self::assertNotNull($wifi24Latest);
        self::assertSame(1000, $wifi5Latest->rawTx);
        self::assertSame(2000, $wifi5Latest->rawRx);
        self::assertSame(3000, $wifi24Latest->rawTx);
        self::assertSame(4000, $wifi24Latest->rawRx);
        self::assertSame(0, $wifi5Latest->deltaTx);
        self::assertSame(0, $wifi24Latest->deltaTx);
    }

    public function testImportMappingCanSkipLegacyDevicesEntirely(): void
    {
        $root = $this->createTempDirectory();
        $databasePath = $root . '/import-skip.sqlite';

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
        $mappingService = new LegacyImportMappingService([
            'legacy_devices' => [
                'SKIPME123' => [
                    'skip' => true,
                ],
            ],
        ]);
        $legacyImportService = new LegacyImportService($pdo, $deviceService, $interfaceService, $trafficService, $mappingService);

        $result = $legacyImportService->import([
            'metadata' => [
                'schema' => 'legacy-sqlite-v1',
                'interface_name' => 'legacy',
            ],
            'devices' => [[
                'legacy_device_id' => 1,
                'serial_number' => 'SKIPME123',
                'name' => 'Old Device',
                'comment' => null,
                'last_seen_at' => '2020-01-01 00:00:00',
                'last_tx' => 1,
                'last_rx' => 2,
            ]],
            'interfaces' => [[
                'legacy_device_id' => 1,
                'serial_number' => 'SKIPME123',
                'name' => 'legacy',
                'display_name' => 'Legacy',
                'comment' => null,
            ]],
            'traffic_samples' => [[
                'legacy_sample_id' => 1,
                'legacy_device_id' => 1,
                'serial_number' => 'SKIPME123',
                'interface_name' => 'legacy',
                'recorded_at' => '2020-01-01 00:00:00',
                'raw_tx' => null,
                'raw_rx' => null,
                'delta_tx' => 100,
                'delta_rx' => 200,
            ]],
        ]);

        self::assertSame([
            'devices' => 0,
            'interfaces' => 0,
            'traffic_samples' => 0,
            'synthetic_snapshots' => 0,
        ], $result);
        self::assertNull($deviceService->getDeviceBySerial('SKIPME123'));
    }

    public function testImportTreatsCounterDecreaseAsResetWithoutTrafficSpike(): void
    {
        $root = $this->createTempDirectory();
        $databasePath = $root . '/import-reset.sqlite';

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

        $legacyImportService->import([
            'metadata' => [
                'schema' => 'legacy-sqlite-v1',
                'interface_name' => 'legacy',
            ],
            'devices' => [[
                'legacy_device_id' => 1,
                'serial_number' => 'RESET123',
                'last_seen_at' => '2025-06-13 00:30:27',
                'last_tx' => 150,
                'last_rx' => 250,
            ]],
            'interfaces' => [[
                'legacy_device_id' => 1,
                'serial_number' => 'RESET123',
                'name' => 'legacy',
                'display_name' => 'Legacy',
            ]],
            'traffic_samples' => [[
                'legacy_sample_id' => 1,
                'legacy_device_id' => 1,
                'serial_number' => 'RESET123',
                'interface_name' => 'legacy',
                'recorded_at' => '2025-06-13 00:00:00',
                'raw_tx' => 1000,
                'raw_rx' => 2000,
                'delta_tx' => 1000,
                'delta_rx' => 2000,
            ], [
                'legacy_sample_id' => 2,
                'legacy_device_id' => 1,
                'serial_number' => 'RESET123',
                'interface_name' => 'legacy',
                'recorded_at' => '2025-06-13 00:10:00',
                'raw_tx' => 150,
                'raw_rx' => 250,
                'delta_tx' => 150,
                'delta_rx' => 250,
            ]],
        ]);

        $device = $deviceService->getDeviceBySerial('RESET123');
        self::assertNotNull($device);
        $interfaces = $interfaceService->listByDeviceId($device->id);
        self::assertCount(1, $interfaces);

        $rows = $pdo->query(sprintf(
            'SELECT raw_tx, raw_rx, delta_tx, delta_rx FROM traffic_samples WHERE interface_id = %d ORDER BY recorded_at ASC, id ASC',
            $interfaces[0]->id
        ))->fetchAll();

        self::assertCount(3, $rows);
        self::assertSame(1000, (int) $rows[0]['delta_tx']);
        self::assertSame(2000, (int) $rows[0]['delta_rx']);
        self::assertSame(0, (int) $rows[1]['delta_tx']);
        self::assertSame(0, (int) $rows[1]['delta_rx']);
        self::assertSame(150, (int) $rows[1]['raw_tx']);
        self::assertSame(250, (int) $rows[1]['raw_rx']);
    }
}
