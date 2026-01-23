<?php
require(__DIR__ . "/init.php");
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

// Helper for stats
function getSumStats($db, $id, $start, $end) {
    $stmt = $db->prepare('SELECT sum(tx) as sumtx, sum(rx) as sumrx FROM traffic WHERE device_id = ? AND timestamp >= ? AND timestamp <= ?');
    $stmt->bindValue(1, $id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $start, SQLITE3_TEXT);
    $stmt->bindValue(3, $end, SQLITE3_TEXT);
    $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return [
        'sumtx' => floatval($res['sumtx'] ?? 0),
        'sumrx' => floatval($res['sumrx'] ?? 0)
    ];
}

switch ($action) {
    case 'getDevices':
        $result = $db->query('SELECT * FROM devices ORDER BY last_check DESC');
        $devices = [];
        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $row['last_tx'] = floatval($row['last_tx'] ?? 0);
                $row['last_rx'] = floatval($row['last_rx'] ?? 0);
                $devices[] = $row;
            }
        }
        echo json_encode($devices);
        break;

    case 'getDeviceData':
        $id = $_GET['id'] ?? null;
        if (!$id || !is_numeric($id)) {
            echo json_encode(['error' => 'Invalid ID']);
            exit;
        }

        // Get device info
        $stmt = $db->prepare('SELECT * FROM devices WHERE id = ?');
        $stmt->bindValue(1, $id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $device = $result->fetchArray(SQLITE3_ASSOC);
        if (!$device) {
            echo json_encode(['error' => 'Device not found']);
            exit;
        }
        $device['last_tx'] = floatval($device['last_tx'] ?? 0);
        $device['last_rx'] = floatval($device['last_rx'] ?? 0);

        // Window and offset logic
        $window = isset($_GET['window']) ? intval($_GET['window']) : 48;
        $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

        // Chart data range
        $end_date = new DateTime();
        if ($offset > 0) {
            $end_date->modify("-" . ($offset * $window) . " hours");
        }
        $start_date = clone $end_date;
        $start_date->modify("-" . $window . " hours");

        // Traffic for chart - hourly grouped as in original version
        $trafficStmt = $db->prepare("SELECT strftime('%Y-%m-%d %H:00:00', timestamp) AS hour,
                                            SUM(tx) as tx,
                                            SUM(rx) as rx
                                     FROM traffic
                                     WHERE device_id = ? AND timestamp >= ? AND timestamp <= ?
                                     GROUP BY hour
                                     ORDER by hour ASC");
        $trafficStmt->bindValue(1, $id, SQLITE3_INTEGER);
        $trafficStmt->bindValue(2, $start_date->format("Y-m-d H:i:s"), SQLITE3_TEXT);
        $trafficStmt->bindValue(3, $end_date->format("Y-m-d H:i:s"), SQLITE3_TEXT);
        $trafficResult = $trafficStmt->execute();
        
        $chartData = [];
        while ($row = $trafficResult->fetchArray(SQLITE3_ASSOC)) {
            $chartData[] = [
                'hour' => $row['hour'],
                'tx' => floatval($row['tx'] ?? 0),
                'rx' => floatval($row['rx'] ?? 0)
            ];
        }

        // Stats queries - ensuring we handle empty results
        // Daily
        $daily_from = date('Y-m-d 00:00:00');
        $daily_to = date('Y-m-d 23:59:59');
        $daily = getSumStats($db, $id, $daily_from, $daily_to);
        $daily_range = ['from' => $daily_from, 'to' => $daily_to];

        // Weekly
        $today = new DateTime();
        $monday = clone $today;
        $monday->modify('Monday this week');
        $sunday = clone $today;
        $sunday->modify('Sunday this week');
        $weekly_from = $monday->format('Y-m-d 00:00:00');
        $weekly_to = $sunday->format('Y-m-d 23:59:59');
        $weekly = getSumStats($db, $id, $weekly_from, $weekly_to);
        $weekly_range = ['from' => $weekly_from, 'to' => $weekly_to];

        // Monthly
        $monthly_from = date('Y-m-01 00:00:00');
        $monthly_to = date('Y-m-t 23:59:59');
        $monthly = getSumStats($db, $id, $monthly_from, $monthly_to);
        $monthly_range = ['from' => $monthly_from, 'to' => $monthly_to];

        // Totals
        $totalsStmt = $db->prepare('SELECT sum(tx) as sumtx, sum(rx) as sumrx FROM traffic WHERE device_id = ?');
        $totalsStmt->bindValue(1, $id, SQLITE3_INTEGER);
        $totalsRes = $totalsStmt->execute()->fetchArray(SQLITE3_ASSOC);
        $totals = [
            'sumtx' => floatval($totalsRes['sumtx'] ?? 0),
            'sumrx' => floatval($totalsRes['sumrx'] ?? 0)
        ];

        echo json_encode([
            'device' => $device,
            'chartData' => $chartData,
            'stats' => [
                'daily' => ['data' => $daily, 'range' => $daily_range],
                'weekly' => ['data' => $weekly, 'range' => $weekly_range],
                'monthly' => ['data' => $monthly, 'range' => $monthly_range],
                'total' => ['data' => $totals]
            ],
            'window' => [
                'start' => $start_date->format("Y-m-d H:i:s"),
                'end' => $end_date->format("Y-m-d H:i:s"),
                'length' => $window,
                'offset' => $offset
            ]
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        break;
}
