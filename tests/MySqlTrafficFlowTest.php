<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\DeviceService;
use App\Services\InterfaceService;
use App\Services\TrafficService;
use App\Tests\Support\UsesMySqlTestDatabase;
use PHPUnit\Framework\TestCase;

final class MySqlTrafficFlowTest extends TestCase
{
    use UsesMySqlTestDatabase;

    public function testDeviceInterfaceAndTrafficServicesProduceInterfaceAwareStatsOnMysql(): void
    {
        $database = $this->createMySqlDatabaseOrSkip();
        $this->resetMySqlSchema($database);
        $database->initializeSchema();

        try {
            $pdo = $database->pdo();
            $deviceService = new DeviceService($pdo);
            $interfaceService = new InterfaceService($pdo);
            $trafficService = new TrafficService($pdo, $database);

            $timestampA = '2026-03-19 10:00:00';
            $timestampB = '2026-03-19 11:00:00';
            $device = $deviceService->createOrTouchDevice('MYSQL1234567', $timestampA);
            $interface = $interfaceService->createOrTouchInterface($device->id, 'ether1', $timestampA);

            $sampleA = $trafficService->recordSample($device->id, $interface->id, 1000, 2000, $timestampA, true, '127.0.0.1');
            $sampleB = $trafficService->recordSample($device->id, $interface->id, 1600, 2600, $timestampB, true, '127.0.0.1');

            self::assertSame(1000, $sampleA->deltaTx);
            self::assertSame(600, $sampleB->deltaTx);
            self::assertSame(600, $sampleB->deltaRx);

            $lastCounters = $trafficService->getDeviceLastCounters($device->id);
            self::assertSame(1600.0, $lastCounters['last_tx']);
            self::assertSame(2600.0, $lastCounters['last_rx']);

            $chartData = $trafficService->getChartData($device->id, '2026-03-19 00:00:00', '2026-03-19 23:59:59', $interface->id);
            self::assertCount(2, $chartData);

            $totals = $trafficService->getTotalStats($device->id, $interface->id);
            self::assertSame(1600.0, $totals['sumtx']);
            self::assertSame(2600.0, $totals['sumrx']);
        } finally {
            $this->resetMySqlSchema($database);
        }
    }
}
