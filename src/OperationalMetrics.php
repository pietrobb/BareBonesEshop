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
            'payment_funnel_window_seconds' => 86400,
            'payment_funnel_grace_seconds' => 1800,
            'payment_funnel_min_started' => 3,
            'csp_window_seconds' => 86400,
            'csp_form_action_critical' => 2,
            'csp_violations_warning' => 20,
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
        $funnel = self::paymentFunnel($db, $storeId, $now - (int)$thresholds['payment_funnel_window_seconds'], $now - (int)$thresholds['payment_funnel_grace_seconds']);
        $csp = CspReports::counts($db, $storeId, $now - (int)$thresholds['csp_window_seconds']);
        $metrics = [
            'service_ready' => ($health['status'] ?? '') === 'ready' ? 1 : 0,
            'queue_pending' => (int)($queue['pending'] ?? 0),
            'queue_failed' => (int)($queue['failed'] ?? 0),
            'failed_jobs' => (int)($counts['failed_jobs'] ?? 0),
            'failed_mail' => (int)($counts['failed_mail'] ?? 0),
            'payment_incidents' => (int)($counts['payment_incidents'] ?? 0),
            'webhook_incidents' => (int)($counts['webhook_incidents'] ?? 0),
            'backup_age_seconds' => $backupAge,
            'payment_online_started' => $funnel['started'],
            'payment_online_redirected' => $funnel['redirected'],
            'payment_online_paid' => $funnel['paid'],
            'csp_violations' => $csp['total'],
            'csp_form_action_violations' => $csp['form_action'],
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
        // Online checkouts that never reach the gateway (no session) or never come back paid:
        // the 1.9.0 CSP regression produced pending card orders and zero payments for five days.
        $minStarted = (int)$thresholds['payment_funnel_min_started'];
        if ($minStarted > 0 && $funnel['started'] >= $minStarted) {
            if ($funnel['redirected'] === 0) {
                self::alert($alerts, 'payment_session_failing', 'critical', $funnel['started'], $minStarted);
            } elseif ($funnel['paid'] === 0) {
                self::alert($alerts, 'payment_funnel_stalled', 'critical', $funnel['started'], $minStarted);
            }
        }
        self::thresholdAlert($alerts, 'csp_form_action_violation', 'critical', $metrics['csp_form_action_violations'], (int)$thresholds['csp_form_action_critical']);
        self::thresholdAlert($alerts, 'csp_violations_high', 'warning', $metrics['csp_violations'], (int)$thresholds['csp_violations_warning']);

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
            'incident_monitor' => $operations['incident_monitor'] ?? [],
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

    /**
     * Real (non-synthetic) online-payment orders created in [$since, $until].
     * @return array{started:int, redirected:int, paid:int}
     */
    private static function paymentFunnel(PDO $db, string $storeId, int $since, int $until): array
    {
        $stmt = $db->prepare("SELECT payment_method_snapshot, payment_id, payment_status FROM orders
            WHERE store_id = :store AND is_synthetic = 0 AND created_at >= :since AND created_at <= :until
            ORDER BY id DESC LIMIT 1000");
        $stmt->execute([':store' => $storeId, ':since' => gmdate('Y-m-d H:i:s', $since), ':until' => gmdate('Y-m-d H:i:s', $until)]);
        $funnel = ['started' => 0, 'redirected' => 0, 'paid' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
            $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? ''), true);
            if (!is_array($snapshot) || ($snapshot['requires_online'] ?? false) !== true) continue;
            $funnel['started']++;
            if (trim((string)($order['payment_id'] ?? '')) !== '') $funnel['redirected']++;
            if (in_array((string)($order['payment_status'] ?? ''), ['authorized', 'paid', 'partially_refunded', 'refunded'], true)) $funnel['paid']++;
        }
        return $funnel;
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
