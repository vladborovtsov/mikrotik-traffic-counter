<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Configuration;
use App\Http\Request;
use App\Http\Response;
use App\Models\Device;
use App\Services\DeviceService;
use App\Services\GlobalSettingsService;
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
        private readonly GlobalSettingsService $globalSettingsService,
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
            'getGlobalSettings' => $this->getGlobalSettings(),
            'updateGlobalSettings' => $this->updateGlobalSettings(),
            'getDeviceOverview' => $this->getDeviceOverview(),
            'getStatsDrilldown' => $this->getStatsDrilldown(),
            'getDeviceSettings' => $this->getDeviceSettings(),
            'getDevice', 'getDeviceData' => $this->getDeviceData(),
            'listInterfaces' => $this->listInterfaces(),
            'moveDevice' => $this->moveDevice(),
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

    private function getGlobalSettings(): Response
    {
        return Response::json($this->globalSettingsService->getSettings());
    }

    private function updateGlobalSettings(): Response
    {
        if (!$this->request->hasQuery('theme_mode')) {
            return Response::json(['error' => 'Missing theme_mode'], 400);
        }

        $themeMode = strtolower(trim((string) $this->request->query('theme_mode')));
        if (!in_array($themeMode, ['light', 'dark', 'auto'], true)) {
            return Response::json(['error' => 'Invalid theme_mode'], 400);
        }

        $this->globalSettingsService->set('theme_mode', $themeMode);

        return Response::json($this->globalSettingsService->getSettings());
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

    private function getStatsDrilldown(): Response
    {
        $id = $this->request->intQuery('id');
        if ($id === null || $id < 1) {
            return Response::json(['error' => 'Invalid ID'], 400);
        }

        $device = $this->deviceService->getDeviceById($id);
        if ($device === null) {
            return Response::json(['error' => 'Device not found'], 404);
        }

        $statsView = strtolower(trim((string) $this->request->query('stats_view', '')));
        if (!in_array($statsView, ['daily', 'weekly', 'monthly', 'total'], true)) {
            return Response::json(['error' => 'Invalid stats_view'], 400);
        }

        $statsOffset = $this->request->intQuery('stats_offset') ?? 0;
        if ($statsOffset < 0 || $statsOffset > 3650) {
            return Response::json(['error' => 'Invalid stats_offset'], 400);
        }

        $interfaceId = $this->request->intQuery('interface_id');
        if ($interfaceId !== null) {
            $selectedInterface = $this->interfaceService->getById($interfaceId);
            if ($selectedInterface === null || $selectedInterface->deviceId !== $id) {
                return Response::json(['error' => 'Interface not found'], 404);
            }
        }

        $anchor = $this->resolveStatsAnchor();
        $interfaces = array_map(
            static fn ($interface) => $interface->toArray(),
            $this->interfaceService->listByDeviceId($id)
        );

        return Response::json(match ($statsView) {
            'daily' => $this->buildDailyDrilldown($device, $interfaces, $interfaceId, $anchor, $statsOffset),
            'weekly' => $this->buildWeeklyDrilldown($device, $interfaces, $interfaceId, $anchor, $statsOffset),
            'monthly' => $this->buildMonthlyDrilldown($device, $interfaces, $interfaceId, $anchor, $statsOffset),
            'total' => $this->buildTotalDrilldown($device, $interfaces, $interfaceId, $statsOffset),
        });
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

    private function moveDevice(): Response
    {
        $id = $this->request->intQuery('id');
        $direction = strtolower(trim((string) $this->request->query('direction', '')));

        if ($id === null || $id < 1 || !in_array($direction, ['up', 'down'], true)) {
            return Response::json(['error' => 'Invalid input'], 400);
        }

        $device = $this->deviceService->moveDevice($id, $direction, date('Y-m-d H:i:s'));
        if ($device === null) {
            return Response::json(['error' => 'Device not found'], 404);
        }

        return Response::json($this->presentDevice($device));
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

    private function resolveStatsAnchor(): DateTime
    {
        $rawAnchor = trim((string) $this->request->query('stats_anchor', ''));
        if ($rawAnchor === '') {
            return new DateTime();
        }

        try {
            return new DateTime($rawAnchor);
        } catch (\Exception) {
            throw new InvalidRequestException('Invalid stats_anchor');
        }
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
            'sort_index' => $device->sortIndex,
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

    /**
     * @param array<int, array<string, mixed>> $interfaces
     * @return array<string, mixed>
     */
    private function buildDailyDrilldown(Device $device, array $interfaces, ?int $interfaceId, DateTime $anchor, int $statsOffset): array
    {
        $selectedDay = clone $anchor;
        if ($statsOffset > 0) {
            $selectedDay->modify('-' . $statsOffset . ' days');
        }

        $dayStart = clone $selectedDay;
        $dayStart->setTime(0, 0, 0);
        $groups = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $hourStart = clone $dayStart;
            $hourStart->setTime($hour, 0, 0);
            $hourEnd = clone $hourStart;
            $hourEnd->setTime($hour, 59, 59);

            $groups[] = [
                'title' => $hourStart->format('H:00') . ' - ' . $hourStart->format('H:59'),
                'subtitle' => $hourStart->format('d/m/Y'),
                'timeline_mode' => 'time',
                'chart_data' => $this->trafficService->getChartDataForGranularity(
                    $device->id,
                    $hourStart->format('Y-m-d H:i:s'),
                    $hourEnd->format('Y-m-d H:i:s'),
                    '10min',
                    $interfaceId
                ),
                'totals' => $this->trafficService->getSumStats(
                    $device->id,
                    $hourStart->format('Y-m-d H:i:s'),
                    $hourEnd->format('Y-m-d H:i:s'),
                    $interfaceId
                ),
            ];
        }

        return [
            'device' => $this->presentDevice($device, [], null, $interfaceId, $interfaces),
            'interfaces' => $interfaces,
            'selected_interface_id' => $interfaceId,
            'stats_view' => 'daily',
            'stats_offset' => $statsOffset,
            'stats_anchor' => $anchor->format('Y-m-d H:i:s'),
            'page_title' => 'Daily breakdown',
            'page_subtitle' => '24 hourly charts for ' . $selectedDay->format('d/m/Y'),
            'can_go_newer' => $statsOffset > 0,
            'can_go_older' => true,
            'groups' => $groups,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $interfaces
     * @return array<string, mixed>
     */
    private function buildWeeklyDrilldown(Device $device, array $interfaces, ?int $interfaceId, DateTime $anchor, int $statsOffset): array
    {
        $selectedWeek = clone $anchor;
        if ($statsOffset > 0) {
            $selectedWeek->modify('-' . ($statsOffset * 4) . ' weeks');
        }
        $selectedWeek->modify('Monday this week');
        $selectedWeek->setTime(0, 0, 0);

        $groups = [];
        for ($index = 0; $index < 4; $index++) {
            $weekStart = clone $selectedWeek;
            if ($index > 0) {
                $weekStart->modify('-' . $index . ' weeks');
            }
            $weekEnd = clone $weekStart;
            $weekEnd->modify('Sunday this week');
            $weekEnd->setTime(23, 59, 59);

            $groups[] = [
                'title' => 'Week of ' . $weekStart->format('d/m/Y'),
                'subtitle' => $weekStart->format('d/m/Y') . ' to ' . $weekEnd->format('d/m/Y'),
                'timeline_mode' => 'day',
                'chart_data' => $this->trafficService->getChartDataForBucketMinutes(
                    $device->id,
                    $weekStart->format('Y-m-d H:i:s'),
                    $weekEnd->format('Y-m-d H:i:s'),
                    360,
                    $interfaceId
                ),
                'totals' => $this->trafficService->getSumStats(
                    $device->id,
                    $weekStart->format('Y-m-d H:i:s'),
                    $weekEnd->format('Y-m-d H:i:s'),
                    $interfaceId
                ),
            ];
        }

        return [
            'device' => $this->presentDevice($device, [], null, $interfaceId, $interfaces),
            'interfaces' => $interfaces,
            'selected_interface_id' => $interfaceId,
            'stats_view' => 'weekly',
            'stats_offset' => $statsOffset,
            'stats_anchor' => $anchor->format('Y-m-d H:i:s'),
            'page_title' => 'Weekly breakdown',
            'page_subtitle' => 'Selected week plus previous 3 weeks',
            'can_go_newer' => $statsOffset > 0,
            'can_go_older' => true,
            'groups' => $groups,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $interfaces
     * @return array<string, mixed>
     */
    private function buildMonthlyDrilldown(Device $device, array $interfaces, ?int $interfaceId, DateTime $anchor, int $statsOffset): array
    {
        $year = (int) $anchor->format('Y') - $statsOffset;
        $groups = [];

        for ($month = 1; $month <= 12; $month++) {
            $monthStart = new DateTime(sprintf('%04d-%02d-01 00:00:00', $year, $month));
            $monthEnd = clone $monthStart;
            $monthEnd->modify('last day of this month');
            $monthEnd->setTime(23, 59, 59);

            $groups[] = [
                'title' => $monthStart->format('F Y'),
                'subtitle' => $monthStart->format('d/m/Y') . ' to ' . $monthEnd->format('d/m/Y'),
                'timeline_mode' => 'day',
                'chart_data' => $this->trafficService->getChartDataForBucketMinutes(
                    $device->id,
                    $monthStart->format('Y-m-d H:i:s'),
                    $monthEnd->format('Y-m-d H:i:s'),
                    4320,
                    $interfaceId
                ),
                'totals' => $this->trafficService->getSumStats(
                    $device->id,
                    $monthStart->format('Y-m-d H:i:s'),
                    $monthEnd->format('Y-m-d H:i:s'),
                    $interfaceId
                ),
            ];
        }

        return [
            'device' => $this->presentDevice($device, [], null, $interfaceId, $interfaces),
            'interfaces' => $interfaces,
            'selected_interface_id' => $interfaceId,
            'stats_view' => 'monthly',
            'stats_offset' => $statsOffset,
            'stats_anchor' => $anchor->format('Y-m-d H:i:s'),
            'page_title' => 'Monthly breakdown',
            'page_subtitle' => '12 monthly charts for ' . (string) $year,
            'can_go_newer' => $statsOffset > 0,
            'can_go_older' => true,
            'groups' => $groups,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $interfaces
     * @return array<string, mixed>
     */
    private function buildTotalDrilldown(Device $device, array $interfaces, ?int $interfaceId, int $statsOffset): array
    {
        $yearBounds = $this->trafficService->getYearBounds($device->id, $interfaceId);
        $groups = [];
        $canGoOlder = false;

        if ($yearBounds !== null) {
            $latestYear = $yearBounds['max_year'] - ($statsOffset * 4);
            $oldestYearOnPage = max($yearBounds['min_year'], $latestYear - 3);
            $canGoOlder = $oldestYearOnPage > $yearBounds['min_year'];

            for ($year = $latestYear; $year >= $oldestYearOnPage; $year--) {
                $yearStart = new DateTime(sprintf('%04d-01-01 00:00:00', $year));
                $yearEnd = new DateTime(sprintf('%04d-12-31 23:59:59', $year));

                $groups[] = [
                    'title' => (string) $year,
                    'subtitle' => 'January to December ' . (string) $year,
                    'timeline_mode' => 'day',
                    'chart_data' => $this->trafficService->getChartDataForBucketMinutes(
                        $device->id,
                        $yearStart->format('Y-m-d H:i:s'),
                        $yearEnd->format('Y-m-d H:i:s'),
                        10080,
                        $interfaceId
                    ),
                    'totals' => $this->trafficService->getSumStats(
                        $device->id,
                        $yearStart->format('Y-m-d H:i:s'),
                        $yearEnd->format('Y-m-d H:i:s'),
                        $interfaceId
                    ),
                ];
            }
        }

        return [
            'device' => $this->presentDevice($device, [], null, $interfaceId, $interfaces),
            'interfaces' => $interfaces,
            'selected_interface_id' => $interfaceId,
            'stats_view' => 'total',
            'stats_offset' => $statsOffset,
            'page_title' => 'Yearly breakdown',
            'page_subtitle' => 'Up to 4 years per page',
            'can_go_newer' => $statsOffset > 0,
            'can_go_older' => $canGoOlder,
            'groups' => $groups,
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
