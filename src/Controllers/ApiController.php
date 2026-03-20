<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Configuration;
use App\Http\Request;
use App\Http\Response;
use App\Models\Device;
use App\Services\DeviceService;
use App\Services\InterfaceService;
use App\Services\RequestGuardService;
use App\Services\TrafficService;
use DateTime;

final class ApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly Configuration $config,
        private readonly DeviceService $deviceService,
        private readonly InterfaceService $interfaceService,
        private readonly TrafficService $trafficService,
        private readonly RequestGuardService $requestGuard
    ) {
    }

    public function handle(): Response
    {
        return match ($this->request->action()) {
            'collect' => $this->collect(),
            'listDevices', 'getDevices' => $this->listDevices(),
            'getDeviceOverview' => $this->getDeviceOverview(),
            'getDeviceSettings' => $this->getDeviceSettings(),
            'getDevice', 'getDeviceData' => $this->getDeviceData(),
            'listInterfaces' => $this->listInterfaces(),
            'renameDevice' => $this->renameDevice(),
            'updateDevice' => $this->updateDevice(),
            'deleteDevice' => $this->deleteDevice(),
            default => Response::json(['error' => 'Unknown action'], 400),
        };
    }

    private function collect(): Response
    {
        if (!$this->requestGuard->isSourceIpAllowed($this->request->remoteAddress())) {
            return $this->plainAwareError('Source IP not allowed', 'forbidden', 403);
        }

        if (!$this->requestGuard->isAuthTokenValid($this->request->queryParams())) {
            return $this->plainAwareError('Unauthorized', 'unauthorized', 401);
        }

        if (
            !$this->request->hasQuery('sn')
            || !$this->request->hasQuery('interface')
            || !$this->request->hasQuery('tx')
            || !$this->request->hasQuery('rx')
        ) {
            return $this->plainAwareError('Missing required parameters', 'fail', 400);
        }

        $deviceSerial = substr(trim((string) $this->request->query('sn')), 0, 12);
        $interfaceName = substr(trim((string) $this->request->query('interface')), 0, 128);
        $tx = $this->request->query('tx');
        $rx = $this->request->query('rx');

        if ($deviceSerial === '' || $interfaceName === '' || !is_numeric($tx) || !is_numeric($rx)) {
            return $this->plainAwareError('Invalid parameters', 'fail', 400);
        }

        $now = date('Y-m-d H:i:s');
        $device = $this->deviceService->createOrTouchDevice($deviceSerial, $now);
        $interface = $this->interfaceService->createOrTouchInterface($device->id, $interfaceName, $now);
        $sample = $this->trafficService->recordSample(
            $device->id,
            $interface->id,
            (int) $tx,
            (int) $rx,
            $now,
            $this->request->hasQuery('delta'),
            $this->request->remoteAddress()
        );

        if ($this->request->wantsPlainText()) {
            return Response::text('traffic data updated');
        }

        return Response::json([
            'status' => 'ok',
            'device_id' => $device->id,
            'interface_id' => $interface->id,
            'sample' => $sample->toArray(),
        ], 201);
    }

    private function listDevices(): Response
    {
        [$overviewStart, $overviewEnd, $overviewLabel, $bucketMinutes] = $this->getOverviewWindow();

        $devices = array_map(
            function (Device $device) use ($overviewStart, $overviewEnd, $bucketMinutes, $overviewLabel): array {
                $preferredInterfaceId = $device->homeScope === 'single' ? $device->homeInterfaceId : null;

                return $this->presentDevice(
                    $device,
                    $this->trafficService->getChartDataForBucketMinutes(
                        $device->id,
                        $overviewStart,
                        $overviewEnd,
                        $bucketMinutes,
                        $preferredInterfaceId
                    ),
                    $overviewLabel,
                    $preferredInterfaceId,
                    array_map(
                        static fn ($interface) => $interface->toArray(),
                        $this->interfaceService->listByDeviceId($device->id)
                    )
                );
            },
            $this->deviceService->listDevices()
        );

        return Response::json($devices);
    }

    private function getDeviceData(): Response
    {
        $id = $this->request->intQuery('id');
        if ($id === null || $id < 1) {
            return Response::json(['error' => 'Invalid ID'], 400);
        }

        $device = $this->deviceService->getDeviceById($id);
        if ($device === null) {
            return Response::json(['error' => 'Device not found'], 404);
        }

        $window = $this->getValidatedWindowHours();
        $offset = $this->getValidatedOffset();
        $interfaceId = $this->request->intQuery('interface_id');

        if ($interfaceId !== null) {
            $selectedInterface = $this->interfaceService->getById($interfaceId);
            if ($selectedInterface === null || $selectedInterface->deviceId !== $id) {
                return Response::json(['error' => 'Interface not found'], 404);
            }
        }

        $endDate = new DateTime();
        if ($offset > 0) {
            $endDate->modify('-' . ($offset * $window) . ' hours');
        }

        $startDate = clone $endDate;
        $startDate->modify('-' . $window . ' hours');

        $dailyFrom = $endDate->format('Y-m-d 00:00:00');
        $dailyTo = $endDate->format('Y-m-d 23:59:59');
        $weeklyRange = $this->getWeekRangeFor($endDate);
        $monthlyFrom = $endDate->format('Y-m-01 00:00:00');
        $monthlyTo = $endDate->format('Y-m-t 23:59:59');

        return Response::json([
            'device' => $this->presentDevice($device, [], null, $interfaceId),
            'interfaces' => array_map(
                static fn ($interface) => $interface->toArray(),
                $this->interfaceService->listByDeviceId($id)
            ),
            'selected_interface_id' => $interfaceId,
            'chartData' => $this->trafficService->getChartData(
                $id,
                $startDate->format('Y-m-d H:i:s'),
                $endDate->format('Y-m-d H:i:s'),
                $interfaceId
            ),
            'stats' => [
                'daily' => [
                    'data' => $this->trafficService->getSumStats($id, $dailyFrom, $dailyTo, $interfaceId),
                    'range' => ['from' => $dailyFrom, 'to' => $dailyTo],
                ],
                'weekly' => [
                    'data' => $this->trafficService->getSumStats($id, $weeklyRange['from'], $weeklyRange['to'], $interfaceId),
                    'range' => $weeklyRange,
                ],
                'monthly' => [
                    'data' => $this->trafficService->getSumStats($id, $monthlyFrom, $monthlyTo, $interfaceId),
                    'range' => ['from' => $monthlyFrom, 'to' => $monthlyTo],
                ],
                'total' => [
                    'data' => $this->trafficService->getTotalStats($id, $interfaceId),
                ],
            ],
            'window' => [
                'start' => $startDate->format('Y-m-d H:i:s'),
                'end' => $endDate->format('Y-m-d H:i:s'),
                'length' => $window,
                'offset' => $offset,
            ],
        ]);
    }

    private function getDeviceOverview(): Response
    {
        $id = $this->request->intQuery('id');
        if ($id === null || $id < 1) {
            return Response::json(['error' => 'Invalid ID'], 400);
        }

        $device = $this->deviceService->getDeviceById($id);
        if ($device === null) {
            return Response::json(['error' => 'Device not found'], 404);
        }

        [$overviewStart, $overviewEnd, $overviewLabel, $bucketMinutes] = $this->getOverviewWindow();
        $interfaceId = $this->request->intQuery('interface_id');
        if ($interfaceId === null && $device->homeScope === 'single' && $device->homeInterfaceId !== null) {
            $interfaceId = $device->homeInterfaceId;
        }

        if ($interfaceId !== null) {
            $selectedInterface = $this->interfaceService->getById($interfaceId);
            if ($selectedInterface === null || $selectedInterface->deviceId !== $id) {
                return Response::json(['error' => 'Interface not found'], 404);
            }
        }

        return Response::json([
            'id' => $device->id,
            'overview_chart_data' => $this->trafficService->getChartDataForBucketMinutes(
                $device->id,
                $overviewStart,
                $overviewEnd,
                $bucketMinutes,
                $interfaceId
            ),
            'overview_label' => $overviewLabel,
            'last_counters' => $this->trafficService->getDeviceLastCounters($device->id, $interfaceId),
        ]);
    }

    private function getDeviceSettings(): Response
    {
        $id = $this->request->intQuery('id');
        if ($id === null || $id < 1) {
            return Response::json(['error' => 'Invalid ID'], 400);
        }

        $device = $this->deviceService->getDeviceById($id);
        if ($device === null) {
            return Response::json(['error' => 'Device not found'], 404);
        }

        return Response::json([
            'device' => $device->toArray(),
            'interfaces' => array_map(
                static fn ($interface) => $interface->toArray(),
                $this->interfaceService->listByDeviceId($id)
            ),
        ]);
    }

    private function listInterfaces(): Response
    {
        $deviceId = $this->request->intQuery('device_id');
        if ($deviceId === null || $deviceId < 1) {
            return Response::json(['error' => 'Invalid device_id'], 400);
        }

        if ($this->deviceService->getDeviceById($deviceId) === null) {
            return Response::json(['error' => 'Device not found'], 404);
        }

        return Response::json(array_map(
            static fn ($interface) => $interface->toArray(),
            $this->interfaceService->listByDeviceId($deviceId)
        ));
    }

    private function renameDevice(): Response
    {
        $id = $this->request->intQuery('id');
        $name = trim((string) $this->request->query('name', ''));

        if ($id === null || $id < 1 || $name === '') {
            return Response::json(['error' => 'Invalid input'], 400);
        }

        $device = $this->deviceService->renameDevice($id, $name, date('Y-m-d H:i:s'));
        if ($device === null) {
            return Response::json(['error' => 'Device not found'], 404);
        }

        return Response::json($this->presentDevice($device));
    }

    private function updateDevice(): Response
    {
        $id = $this->request->intQuery('id');
        if ($id === null || $id < 1) {
            return Response::json(['error' => 'Invalid ID'], 400);
        }

        $fields = [];
        if ($this->request->hasQuery('name')) {
            $fields['name'] = trim((string) $this->request->query('name')) ?: null;
        }
        if ($this->request->hasQuery('comment')) {
            $fields['comment'] = trim((string) $this->request->query('comment')) ?: null;
        }
        if ($this->request->hasQuery('home_scope')) {
            $homeScope = strtolower(trim((string) $this->request->query('home_scope')));
            if (!in_array($homeScope, ['all', 'single'], true)) {
                return Response::json(['error' => 'Invalid home_scope'], 400);
            }
            $fields['home_scope'] = $homeScope;
        }
        if ($this->request->hasQuery('home_interface_id')) {
            $homeInterfaceId = $this->request->query('home_interface_id');
            if ($homeInterfaceId === '' || $homeInterfaceId === null) {
                $fields['home_interface_id'] = null;
            } elseif (!is_numeric($homeInterfaceId) || (int) $homeInterfaceId < 1) {
                return Response::json(['error' => 'Invalid home_interface_id'], 400);
            } else {
                $interface = $this->interfaceService->getById((int) $homeInterfaceId);
                if ($interface === null || $interface->deviceId !== $id) {
                    return Response::json(['error' => 'Interface not found'], 404);
                }
                $fields['home_interface_id'] = (int) $homeInterfaceId;
            }
        }

        if (($fields['home_scope'] ?? null) === 'all' && !array_key_exists('home_interface_id', $fields)) {
            $fields['home_interface_id'] = null;
        }

        $device = $this->deviceService->updateDevice($id, $fields, date('Y-m-d H:i:s'));
        if ($device === null) {
            return Response::json(['error' => 'Device not found'], 404);
        }

        return Response::json($this->presentDevice($device));
    }

    private function deleteDevice(): Response
    {
        $id = $this->request->intQuery('id');
        if ($id === null || $id < 1) {
            return Response::json(['error' => 'Invalid ID'], 400);
        }

        if (!$this->deviceService->deleteDevice($id)) {
            return Response::json(['error' => 'Device not found'], 404);
        }

        return Response::json(['status' => 'deleted']);
    }

    private function getValidatedWindowHours(): int
    {
        $window = $this->request->intQuery('window') ?? $this->config->getInt('DEFAULT_WINDOW_HOURS', 48);

        if ($window < 1 || $window > 24 * 180) {
            throw new InvalidRequestException('Invalid window');
        }

        return $window;
    }

    private function getValidatedOffset(): int
    {
        $offset = $this->request->intQuery('offset') ?? 0;

        if ($offset < 0 || $offset > 365) {
            throw new InvalidRequestException('Invalid offset');
        }

        return $offset;
    }

    /**
     * @return array{from: string, to: string}
     */
    private function getWeekRangeFor(DateTime $date): array
    {
        $monday = clone $date;
        $monday->modify('Monday this week');
        $sunday = clone $date;
        $sunday->modify('Sunday this week');

        return [
            'from' => $monday->format('Y-m-d 00:00:00'),
            'to' => $sunday->format('Y-m-d 23:59:59'),
        ];
    }

    /**
     * @return array<string, int|float|string|null>
     */
    private function presentDevice(
        Device $device,
        array $overviewChartData = [],
        ?string $overviewLabel = null,
        ?int $interfaceId = null,
        array $interfaces = []
    ): array
    {
        $effectiveInterfaceId = $interfaceId;
        if ($effectiveInterfaceId === null && $device->homeScope === 'single' && $device->homeInterfaceId !== null) {
            $effectiveInterfaceId = $device->homeInterfaceId;
        }

        $counters = $this->trafficService->getDeviceLastCounters($device->id, $effectiveInterfaceId);

        return [
            'id' => $device->id,
            'sn' => $device->serialNumber,
            'name' => $device->name,
            'comment' => $device->comment,
            'home_scope' => $device->homeScope,
            'home_interface_id' => $device->homeInterfaceId,
            'last_check' => $device->lastSeenAt,
            'last_tx' => $counters['last_tx'],
            'last_rx' => $counters['last_rx'],
            'overview_chart_data' => $overviewChartData,
            'overview_label' => $overviewLabel,
            'interfaces' => $interfaces,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: int}
     */
    private function getOverviewWindow(): array
    {
        $preset = strtolower(trim((string) $this->request->query('overview_window', '3')));
        $now = new DateTime();

        if ($preset === 'today') {
            $start = clone $now;
            $start->setTime(0, 0, 0);

            return [
                $start->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
                'today',
                10,
            ];
        }

        $hours = is_numeric($preset) ? (int) $preset : 3;
        if (!in_array($hours, [1, 3, 6, 12], true)) {
            $hours = 3;
        }

        $start = clone $now;
        $start->modify('-' . $hours . ' hours');

        return [
            $start->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s'),
            (string) $hours,
            10,
        ];
    }

    private function plainAwareError(string $jsonMessage, string $plainMessage, int $statusCode): Response
    {
        if ($this->request->wantsPlainText()) {
            return Response::text($plainMessage, $statusCode);
        }

        return Response::json(['error' => $jsonMessage], $statusCode);
    }
}
