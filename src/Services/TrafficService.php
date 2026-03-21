<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Models\TrafficSample;
use PDO;

final class TrafficService
{
    public function __construct(
        private readonly PDO $db,
        private readonly Database $database
    ) {
    }

    public function getLatestSampleForInterface(int $interfaceId): ?TrafficSample
    {
        $stmt = $this->db->prepare('SELECT * FROM traffic_samples WHERE interface_id = :interface_id ORDER BY id DESC LIMIT 1');
        $stmt->execute([
            ':interface_id' => $interfaceId,
        ]);
        $row = $stmt->fetch();

        return $row ? TrafficSample::fromRow($row) : null;
    }

    public function recordSample(
        int $deviceId,
        int $interfaceId,
        int $rawTx,
        int $rawRx,
        string $recordedAt,
        bool $useDelta,
        ?string $sourceIp = null
    ): TrafficSample {
        // Intentional behavior: the first-ever sample for an interface counts as traffic.
        // It represents accumulated usage before monitoring started, so delta begins at raw_*.
        $deltaTx = $rawTx;
        $deltaRx = $rawRx;
        $lastSample = $this->getLatestSampleForInterface($interfaceId);

        if ($useDelta && $lastSample !== null) {
            $deltaTx = $rawTx >= $lastSample->rawTx ? $rawTx - $lastSample->rawTx : 0;
            $deltaRx = $rawRx >= $lastSample->rawRx ? $rawRx - $lastSample->rawRx : 0;
        }

        $stmt = $this->db->prepare('INSERT INTO traffic_samples (
                device_id, interface_id, recorded_at, raw_tx, raw_rx, delta_tx, delta_rx, source_ip, created_at
            ) VALUES (
                :device_id, :interface_id, :recorded_at, :raw_tx, :raw_rx, :delta_tx, :delta_rx, :source_ip, :created_at
            )');
        $stmt->execute([
            ':device_id' => $deviceId,
            ':interface_id' => $interfaceId,
            ':recorded_at' => $recordedAt,
            ':raw_tx' => $rawTx,
            ':raw_rx' => $rawRx,
            ':delta_tx' => $deltaTx,
            ':delta_rx' => $deltaRx,
            ':source_ip' => $sourceIp,
            ':created_at' => $recordedAt,
        ]);

        return TrafficSample::fromRow([
            'id' => (int) $this->db->lastInsertId(),
            'device_id' => $deviceId,
            'interface_id' => $interfaceId,
            'recorded_at' => $recordedAt,
            'raw_tx' => $rawTx,
            'raw_rx' => $rawRx,
            'delta_tx' => $deltaTx,
            'delta_rx' => $deltaRx,
            'source_ip' => $sourceIp,
            'created_at' => $recordedAt,
        ]);
    }

    public function importSample(
        int $deviceId,
        int $interfaceId,
        int $rawTx,
        int $rawRx,
        int $deltaTx,
        int $deltaRx,
        string $recordedAt,
        ?string $sourceIp = null
    ): TrafficSample {
        $stmt = $this->db->prepare('INSERT INTO traffic_samples (
                device_id, interface_id, recorded_at, raw_tx, raw_rx, delta_tx, delta_rx, source_ip, created_at
            ) VALUES (
                :device_id, :interface_id, :recorded_at, :raw_tx, :raw_rx, :delta_tx, :delta_rx, :source_ip, :created_at
            )');
        $stmt->execute([
            ':device_id' => $deviceId,
            ':interface_id' => $interfaceId,
            ':recorded_at' => $recordedAt,
            ':raw_tx' => $rawTx,
            ':raw_rx' => $rawRx,
            ':delta_tx' => $deltaTx,
            ':delta_rx' => $deltaRx,
            ':source_ip' => $sourceIp,
            ':created_at' => $recordedAt,
        ]);

        return TrafficSample::fromRow([
            'id' => (int) $this->db->lastInsertId(),
            'device_id' => $deviceId,
            'interface_id' => $interfaceId,
            'recorded_at' => $recordedAt,
            'raw_tx' => $rawTx,
            'raw_rx' => $rawRx,
            'delta_tx' => $deltaTx,
            'delta_rx' => $deltaRx,
            'source_ip' => $sourceIp,
            'created_at' => $recordedAt,
        ]);
    }

    /**
     * @return array{last_tx: float, last_rx: float}
     */
    public function getDeviceLastCounters(int $deviceId, ?int $interfaceId = null): array
    {
        if ($interfaceId !== null) {
            $sample = $this->getLatestSampleForInterface($interfaceId);

            return [
                'last_tx' => $sample?->rawTx ?? 0.0,
                'last_rx' => $sample?->rawRx ?? 0.0,
            ];
        }

        $stmt = $this->db->prepare('SELECT
                COALESCE(SUM(samples.raw_tx), 0) AS last_tx,
                COALESCE(SUM(samples.raw_rx), 0) AS last_rx
            FROM interfaces i
            LEFT JOIN (
                SELECT ts.interface_id, ts.raw_tx, ts.raw_rx
                FROM traffic_samples ts
                INNER JOIN (
                    SELECT interface_id, MAX(id) AS latest_id
                    FROM traffic_samples
                    GROUP BY interface_id
                ) latest ON latest.latest_id = ts.id
            ) samples ON samples.interface_id = i.id
            WHERE i.device_id = :device_id');
        $stmt->execute([
            ':device_id' => $deviceId,
        ]);
        $row = $stmt->fetch();

        return [
            'last_tx' => floatval($row['last_tx'] ?? 0),
            'last_rx' => floatval($row['last_rx'] ?? 0),
        ];
    }

    /**
     * @return array<int, array{hour: string, tx: float, rx: float}>
     */
    public function getChartData(int $deviceId, string $start, string $end, ?int $interfaceId = null): array
    {
        $sql = 'SELECT recorded_at AS hour, delta_tx AS tx, delta_rx AS rx
            FROM traffic_samples
            WHERE device_id = :device_id AND recorded_at >= :start AND recorded_at <= :end';
        $params = [
            ':device_id' => $deviceId,
            ':start' => $start,
            ':end' => $end,
        ];

        if ($interfaceId !== null) {
            $sql .= ' AND interface_id = :interface_id';
            $params[':interface_id'] = $interfaceId;
        }

        $sql .= ' ORDER BY recorded_at ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $chartData = [];
        while ($row = $stmt->fetch()) {
            $chartData[] = [
                'hour' => (string) $row['hour'],
                'tx' => floatval($row['tx'] ?? 0),
                'rx' => floatval($row['rx'] ?? 0),
            ];
        }

        return $chartData;
    }

    /**
     * @return array<int, array{hour: string, tx: float, rx: float}>
     */
    public function getChartDataForBucketMinutes(
        int $deviceId,
        string $start,
        string $end,
        int $bucketMinutes,
        ?int $interfaceId = null
    ): array {
        $bucketExpression = $bucketMinutes === 60
            ? $this->database->hourlyBucketExpression('recorded_at')
            : $this->database->bucketExpression('recorded_at', $bucketMinutes);

        return $this->getChartDataByExpression($bucketExpression, $deviceId, $start, $end, $interfaceId);
    }

    /**
     * @return array<int, array{hour: string, tx: float, rx: float}>
     */
    public function getChartDataForGranularity(
        int $deviceId,
        string $start,
        string $end,
        string $granularity,
        ?int $interfaceId = null
    ): array {
        return match ($granularity) {
            '10min' => $this->getChartDataForBucketMinutes($deviceId, $start, $end, 10, $interfaceId),
            'hour' => $this->getChartData($deviceId, $start, $end, $interfaceId),
            'day' => $this->getChartDataByExpression(
                $this->database->dailyBucketExpression('recorded_at'),
                $deviceId,
                $start,
                $end,
                $interfaceId
            ),
            'month' => $this->getChartDataByExpression(
                $this->database->monthlyBucketExpression('recorded_at'),
                $deviceId,
                $start,
                $end,
                $interfaceId
            ),
            default => $this->getChartData($deviceId, $start, $end, $interfaceId),
        };
    }

    /**
     * @return array{sumtx: float, sumrx: float}
     */
    public function getSumStats(int $deviceId, string $start, string $end, ?int $interfaceId = null): array
    {
        $sql = 'SELECT SUM(delta_tx) AS sumtx, SUM(delta_rx) AS sumrx
            FROM traffic_samples
            WHERE device_id = :device_id AND recorded_at >= :start AND recorded_at <= :end';
        $params = [
            ':device_id' => $deviceId,
            ':start' => $start,
            ':end' => $end,
        ];

        if ($interfaceId !== null) {
            $sql .= ' AND interface_id = :interface_id';
            $params[':interface_id'] = $interfaceId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [
            'sumtx' => floatval($row['sumtx'] ?? 0),
            'sumrx' => floatval($row['sumrx'] ?? 0),
        ];
    }

    /**
     * @return array{sumtx: float, sumrx: float}
     */
    public function getTotalStats(int $deviceId, ?int $interfaceId = null): array
    {
        $sql = 'SELECT SUM(delta_tx) AS sumtx, SUM(delta_rx) AS sumrx FROM traffic_samples WHERE device_id = :device_id';
        $params = [
            ':device_id' => $deviceId,
        ];

        if ($interfaceId !== null) {
            $sql .= ' AND interface_id = :interface_id';
            $params[':interface_id'] = $interfaceId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [
            'sumtx' => floatval($row['sumtx'] ?? 0),
            'sumrx' => floatval($row['sumrx'] ?? 0),
        ];
    }

    /**
     * @return array{min_year: int, max_year: int}|null
     */
    public function getYearBounds(int $deviceId, ?int $interfaceId = null): ?array
    {
        $sql = 'SELECT MIN(recorded_at) AS min_recorded_at, MAX(recorded_at) AS max_recorded_at
            FROM traffic_samples WHERE device_id = :device_id';
        $params = [
            ':device_id' => $deviceId,
        ];

        if ($interfaceId !== null) {
            $sql .= ' AND interface_id = :interface_id';
            $params[':interface_id'] = $interfaceId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        $minRecordedAt = $row['min_recorded_at'] ?? null;
        $maxRecordedAt = $row['max_recorded_at'] ?? null;

        if (!$minRecordedAt || !$maxRecordedAt) {
            return null;
        }

        return [
            'min_year' => (int) date('Y', strtotime((string) $minRecordedAt)),
            'max_year' => (int) date('Y', strtotime((string) $maxRecordedAt)),
        ];
    }

    /**
     * @return array<int, array{hour: string, tx: float, rx: float}>
     */
    private function getChartDataByExpression(
        string $bucketExpression,
        int $deviceId,
        string $start,
        string $end,
        ?int $interfaceId = null
    ): array {
        $sql = "SELECT {$bucketExpression} AS hour, SUM(delta_tx) AS tx, SUM(delta_rx) AS rx
            FROM traffic_samples
            WHERE device_id = :device_id AND recorded_at >= :start AND recorded_at <= :end";
        $params = [
            ':device_id' => $deviceId,
            ':start' => $start,
            ':end' => $end,
        ];

        if ($interfaceId !== null) {
            $sql .= ' AND interface_id = :interface_id';
            $params[':interface_id'] = $interfaceId;
        }

        $sql .= ' GROUP BY hour ORDER BY hour ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $chartData = [];
        while ($row = $stmt->fetch()) {
            $chartData[] = [
                'hour' => (string) $row['hour'],
                'tx' => floatval($row['tx'] ?? 0),
                'rx' => floatval($row['rx'] ?? 0),
            ];
        }

        return $chartData;
    }
}
