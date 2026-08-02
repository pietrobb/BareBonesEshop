<?php
/** Builds a bounded SLO snapshot from existing health, queue, incident and backup signals. */
class OperationalMetrics
{
    public static function snapshot(
        ?array $config = null,
        ?string $root = null,
        ?PDO $db = null,
        ?string $storeId = null,
        ?array $backupRows = null,
        ?int $now = null
    ): array {
        $root ??= dirname(__DIR__);
        $config ??= require $root . '/config.php';
        $db ??= Db::get();
        $storeId ??= StoreContext::current()->id();
        $now ??= time();
        $thresholds = array_replace([
            'backup_rpo_seconds' => 86400,
            'queue_pending_warning' => 100,
            'failed_jobs_warning' => 1,
            'failed_mail_warning' => 1,
            'payment_incidents_critical' => 1,
            'webhook_incidents_warning' => 1,
        ], (array)($config['monitoring'] ?? []));

        $health = Health::readiness($config, $root, $db);
        $operations = OperationalDashboard::summary($storeId);
        $backupAvailable = true;
        if ($backupRows === null) {
            try {
                BackupService::init($db, null, $root);
                $backupRows = BackupService::list();
            } catch (Throwable $e) {
                $backupRows = [];
                $backupAvailable = false;
            }
        }
        $backupAge = self::latestBackupAge($backupRows, $now);
        $queue = (array)($operations['queue'] ?? []);
        $counts = (array)($operations['counts'] ?? []);
        $metrics = [
            'service_ready' => ($health['status'] ?? '') === 'ready' ? 1 : 0,
            'queue_pending' => (int)($queue['pending'] ?? 0),
            'queue_failed' => (int)($queue['failed'] ?? 0),
            'failed_jobs' => (int)($counts['failed_jobs'] ?? 0),
            'failed_mail' => (int)($counts['failed_mail'] ?? 0),
            'payment_incidents' => (int)($counts['payment_incidents'] ?? 0),
            'webhook_incidents' => (int)($counts['webhook_incidents'] ?? 0),
            'backup_age_seconds' => $backupAge,
        ];

        $alerts = [];
        if ($metrics['service_ready'] === 0) self::alert($alerts, 'readiness_failed', 'critical', 0, 1);
        if (!$backupAvailable) {
            self::alert($alerts, 'backup_probe_failed', 'critical', null, null);
        } elseif ($backupAge === null) {
            self::alert($alerts, 'backup_missing', 'critical', null, (int)$thresholds['backup_rpo_seconds']);
        } elseif ($backupAge > (int)$thresholds['backup_rpo_seconds']) {
            self::alert($alerts, 'backup_rpo_breached', 'critical', $backupAge, (int)$thresholds['backup_rpo_seconds']);
        }
        self::thresholdAlert($alerts, 'queue_pending_high', 'warning', $metrics['queue_pending'], (int)$thresholds['queue_pending_warning']);
        self::thresholdAlert($alerts, 'failed_jobs_present', 'warning', $metrics['failed_jobs'], (int)$thresholds['failed_jobs_warning']);
        self::thresholdAlert($alerts, 'failed_mail_present', 'warning', $metrics['failed_mail'], (int)$thresholds['failed_mail_warning']);
        self::thresholdAlert($alerts, 'payment_incident_present', 'critical', $metrics['payment_incidents'], (int)$thresholds['payment_incidents_critical']);
        self::thresholdAlert($alerts, 'webhook_incident_present', 'warning', $metrics['webhook_incidents'], (int)$thresholds['webhook_incidents_warning']);

        $status = 'ok';
        foreach ($alerts as $alert) {
            if ($alert['severity'] === 'critical') { $status = 'critical'; break; }
            $status = 'warning';
        }
        return [
            'status' => $status,
            'generated_at' => gmdate('c', $now),
            'store_id' => $storeId,
            'correlation_id' => Logger::correlationId(),
            'metrics' => $metrics,
            'alerts' => $alerts,
            'health_checks' => $health['checks'] ?? [],
        ];
    }

    public static function report(?MetricAlertAdapter $adapter = null, ?array $config = null): array
    {
        $snapshot = self::snapshot($config);
        $adapter ??= Adapters::metrics();
        if ($adapter !== null) {
            $adapter->publish($snapshot['metrics'], $snapshot['alerts'], [
                'status' => $snapshot['status'],
                'store_id' => $snapshot['store_id'],
                'correlation_id' => $snapshot['correlation_id'],
            ]);
        }
        return $snapshot;
    }

    private static function latestBackupAge(array $rows, int $now): ?int
    {
        $latest = null;
        foreach ($rows as $row) {
            $timestamp = strtotime((string)($row['meta']['timestamp'] ?? ''));
            if ($timestamp === false) $timestamp = (int)($row['mtime'] ?? 0);
            if ($timestamp > 0 && ($latest === null || $timestamp > $latest)) $latest = $timestamp;
        }
        return $latest === null ? null : max(0, $now - $latest);
    }

    private static function thresholdAlert(array &$alerts, string $code, string $severity, int $value, int $threshold): void
    {
        if ($threshold > 0 && $value >= $threshold) self::alert($alerts, $code, $severity, $value, $threshold);
    }

    private static function alert(array &$alerts, string $code, string $severity, ?int $value, ?int $threshold): void
    {
        $alerts[] = compact('code', 'severity', 'value', 'threshold');
    }
}
