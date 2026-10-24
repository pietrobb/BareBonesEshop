<?php
/** Technical incidents only. Validation must never call this notifier. */
class IncidentMonitor {
    private static ?array $config = null;

    public static function report(string $code, string $phase, string $severity = 'error', array $context = []): void {
        try {
            if (!preg_match('/^[a-z][a-z0-9_.-]{1,79}$/D', $code) || !preg_match('/^[a-z][a-z0-9_.-]{1,39}$/D', $phase)) return;
            if (!in_array($severity, ['warning', 'error', 'critical'], true)) return;
            $safe = [];
            foreach (['order_id', 'attempts', 'http_status', 'count'] as $key) {
                if (isset($context[$key]) && is_int($context[$key])) $safe[$key] = $context[$key];
            }
            if (($context['exception'] ?? null) instanceof Throwable) $safe['exception_type'] = get_debug_type($context['exception']);
            Logger::safeWrite($severity, $code, ['phase' => $phase] + $safe);
            $db = Db::get();
            $store = StoreContext::current()->id();
            $now = time();
            $stmt = $db->prepare('INSERT INTO operational_incidents (id, store_id, code, phase, severity, correlation_id, occurred_at, context_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([bin2hex(random_bytes(16)), $store, $code, $phase, $severity, Logger::correlationId(), $now, json_encode($safe, JSON_THROW_ON_ERROR)]);
            $cfg = self::settings();
            if (!$cfg['enabled']) return;
            $fingerprint = hash('sha256', $store . '|' . $code . '|' . $phase);
            $db->prepare(Db::insertIgnore() . " INTO operational_alert_state (fingerprint, store_id, code, phase, severity, correlation_id, window_started_at, notification_status, delivered_hashes) VALUES (?, ?, ?, ?, ?, ?, ?, 'idle', '[]')")
                ->execute([$fingerprint, $store, $code, $phase, $severity, Logger::correlationId(), $now]);
            // Set the window start LAST: MySQL evaluates SET assignments left-to-right.
            $db->prepare("UPDATE operational_alert_state SET
                occurrences = CASE WHEN window_started_at <= :since_count THEN 1 ELSE occurrences + 1 END,
                notified_count = CASE WHEN window_started_at <= :since_notified THEN 0 ELSE notified_count END,
                severity = CASE WHEN severity = 'critical' THEN severity ELSE :severity END,
                correlation_id = :correlation,
                window_started_at = CASE WHEN window_started_at <= :since_window THEN :now ELSE window_started_at END
                WHERE fingerprint = :fingerprint")
                ->execute([':since_count' => $now - $cfg['window_seconds'], ':since_notified' => $now - $cfg['window_seconds'], ':since_window' => $now - $cfg['window_seconds'], ':now' => $now, ':severity' => $severity, ':correlation' => Logger::correlationId(), ':fingerprint' => $fingerprint]);
            self::sweep();
        } catch (Throwable $e) {
            // Missing migrations, database outages and broken queues cannot fail a purchase.
            Logger::safeWrite('error', 'monitoring.persistence_failed', ['phase' => 'monitoring']);
        }
    }

    /**
     * True when $code was already recorded for $orderId in this store within $seconds. A sweep that keeps failing
     * on the same order (every cron run) reports it once a day instead of a critical incident per run.
     */
    public static function reportedForOrder(string $code, int $orderId, int $seconds = 86400): bool {
        try {
            // report() writes the safe context with order_id as its first key.
            $stmt = Db::get()->prepare('SELECT 1 FROM operational_incidents WHERE store_id = ? AND code = ? AND occurred_at > ?
                AND (context_json = ? OR context_json LIKE ?) LIMIT 1');
            $stmt->execute([StoreContext::current()->id(), $code, time() - $seconds, '{"order_id":' . $orderId . '}', '{"order_id":' . $orderId . ',%']);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function sweep(): int {
        try {
            $cfg = self::settings();
            if (!$cfg['enabled']) return 0;
            $db = Db::get();
            $stmt = $db->prepare('SELECT * FROM operational_alert_state WHERE store_id = ? ORDER BY last_queued_at LIMIT 100');
            $stmt->execute([StoreContext::current()->id()]);
            $queued = 0;
            $now = time();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pending = (int)$row['occurrences'] - (int)$row['notified_count'];
                $critical = $row['severity'] === 'critical';
                $digest = $row['code'] === 'checkout.failed' && (int)$row['occurrences'] >= $cfg['checkout_failures'];
                if (!$critical && !$digest) continue;
                if ($pending <= 0 || (int)$row['last_queued_at'] > $now - $cfg['cooldown_seconds']) continue;
                if (in_array($row['notification_status'], ['queued', 'processing'], true) && (int)$row['processing_until'] > $now) continue;
                $claim = $db->prepare("UPDATE operational_alert_state SET notification_status = 'queued', last_queued_at = :now, processing_until = :lease, delivered_hashes = '[]'
                    WHERE fingerprint = :id AND last_queued_at = :previous AND notification_status = :status");
                $claim->execute([':now' => $now, ':lease' => $now + $cfg['lease_seconds'], ':id' => $row['fingerprint'], ':previous' => $row['last_queued_at'], ':status' => $row['notification_status']]);
                if ($claim->rowCount() !== 1) continue;
                try {
                    JobQueue::push('incident_notify', ['fingerprint' => $row['fingerprint'], '_store_id' => $row['store_id']], 2);
                    $queued++;
                } catch (Throwable $e) {
                    $db->prepare("UPDATE operational_alert_state SET notification_status = 'failed', processing_until = 0 WHERE fingerprint = ? AND notification_status = 'queued'")->execute([$row['fingerprint']]);
                    Logger::safeWrite('error', 'monitoring.notification_enqueue_failed', ['phase' => 'notification']);
                }
            }
            return $queued;
        } catch (Throwable $e) {
            Logger::safeWrite('error', 'monitoring.sweep_failed', ['phase' => 'monitoring']);
            return 0;
        }
    }

    public static function notify(string $fingerprint): int {
        if (!preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) throw new RuntimeException('Invalid incident fingerprint');
        $cfg = self::settings();
        if (!$cfg['enabled']) return 0;
        $db = Db::get();
        $now = time();
        $claim = $db->prepare("UPDATE operational_alert_state SET notification_status = 'processing', processing_until = :lease
            WHERE fingerprint = :id AND store_id = :store AND (notification_status IN ('queued', 'failed') OR (notification_status = 'processing' AND processing_until <= :now))");
        $claim->execute([':lease' => $now + $cfg['lease_seconds'], ':id' => $fingerprint, ':store' => StoreContext::current()->id(), ':now' => $now]);
        if ($claim->rowCount() !== 1) return 0;
        $stmt = $db->prepare('SELECT * FROM operational_alert_state WHERE fingerprint = ? AND store_id = ?');
        $stmt->execute([$fingerprint, StoreContext::current()->id()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $delivered = json_decode($row['delivered_hashes'], true, 512, JSON_THROW_ON_ERROR);
        $sent = 0;
        try {
            $recipients = MerchantNotifier::recipients(self::config(), $cfg['recipients']);
            if (!$recipients) throw new RuntimeException('Incident notification has no recipient');
            $simulated = MailService::adapter()->adapterId() === 'log';
            foreach ($recipients as $recipient) {
                $recipientHash = hash('sha256', strtolower($recipient));
                if (in_array($recipientHash, $delivered, true)) continue;
                $result = MailService::send('merchant_incident', $recipient, $cfg['locale'], [
                    'code' => $row['code'], 'phase' => $row['phase'], 'severity' => $row['severity'],
                    'store_id' => $row['store_id'], 'correlation_id' => $row['correlation_id'],
                    'count' => max(1, (int)$row['occurrences'] - (int)$row['notified_count']),
                    'occurred_at' => gmdate('c', (int)$row['window_started_at']),
                ]);
                if (($result['status'] ?? '') !== 'sent') throw new RuntimeException('Incident notification adapter failed');
                $delivered[] = $recipientHash;
                $db->prepare('UPDATE operational_alert_state SET delivered_hashes = ? WHERE fingerprint = ?')->execute([json_encode($delivered, JSON_THROW_ON_ERROR), $fingerprint]);
                $sent++;
            }
            $db->prepare('UPDATE operational_alert_state SET notification_status = ?, notified_count = ?, last_notification_at = ?, processing_until = 0 WHERE fingerprint = ?')
                ->execute([$simulated ? 'simulated' : 'accepted', (int)$row['occurrences'], $simulated ? 0 : $now, $fingerprint]);
            self::heartbeat('mail', $simulated ? 'simulated' : 'adapter_accepted');
            return $sent;
        } catch (Throwable $e) {
            $db->prepare("UPDATE operational_alert_state SET notification_status = 'failed', processing_until = 0 WHERE fingerprint = ?")->execute([$fingerprint]);
            self::heartbeat('mail', 'failed');
            Logger::safeWrite('error', 'monitoring.notification_failed', ['phase' => 'notification']);
            // Stable retry error only; no recipient, exception message or recursive incident.
            throw new RuntimeException('Incident notification failed', 0, $e);
        }
    }

    public static function heartbeat(string $probe, string $status = 'ok'): void {
        try {
            $db = Db::get();
            $db->prepare(Db::insertIgnore() . ' INTO operational_monitor_state (store_id, probe, status, checked_at) VALUES (?, ?, ?, ?)')->execute([StoreContext::current()->id(), $probe, $status, time()]);
            $db->prepare('UPDATE operational_monitor_state SET status = ?, checked_at = ? WHERE store_id = ? AND probe = ?')->execute([$status, time(), StoreContext::current()->id(), $probe]);
        } catch (Throwable $e) {
            // The probe reports missing evidence, never interrupts the worker or checkout.
        }
    }

    public static function probeLog(): bool {
        $written = Logger::safeWrite('info', 'monitoring.log_probe', ['phase' => 'monitoring']);
        self::heartbeat('log', $written ? 'written' : 'failed');
        return $written;
    }

    public static function snapshot(?string $storeId = null): array {
        $storeId ??= StoreContext::current()->id();
        $cfg = self::settings();
        $result = ['available' => false, 'enabled' => $cfg['enabled'], 'incidents' => [], 'notifications' => [], 'probes' => [], 'worker_cron' => (string)(self::config()['operations']['worker_cron'] ?? ''), 'monitor_cron' => (string)(self::config()['operations']['monitor_cron'] ?? '')];
        try {
            $db = Db::get();
            foreach (['incidents' => 'SELECT * FROM operational_incidents WHERE store_id = ? ORDER BY occurred_at DESC LIMIT 20', 'notifications' => 'SELECT code, phase, occurrences, correlation_id, notification_status, last_notification_at FROM operational_alert_state WHERE store_id = ? ORDER BY last_queued_at DESC LIMIT 20', 'probes' => 'SELECT probe, status, checked_at FROM operational_monitor_state WHERE store_id = ?'] as $key => $sql) {
                $stmt = $db->prepare($sql);
                $stmt->execute([$storeId]);
                $result[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($result['probes'] as &$probe) {
                $probe['age_seconds'] = max(0, time() - (int)$probe['checked_at']);
                $probe['stale'] = $probe['age_seconds'] > $cfg['heartbeat_max_age_seconds'];
            }
            unset($probe);
            $result['available'] = true;
        } catch (Throwable $e) {
            $result['available'] = false;
        }
        return $result;
    }

    private static function config(): array {
        return self::$config ??= require __DIR__ . '/../config.php';
    }

    private static function settings(): array {
        $cfg = array_replace(['enabled' => false, 'recipients' => [], 'locale' => '', 'window_seconds' => 900, 'checkout_failures' => 3, 'cooldown_seconds' => 900, 'lease_seconds' => 300, 'heartbeat_max_age_seconds' => 300], (array)(self::config()['incident_alerts'] ?? []), (array)(StoreContext::current()->config()['incident_alerts'] ?? []));
        foreach (['window_seconds', 'checkout_failures', 'cooldown_seconds', 'lease_seconds', 'heartbeat_max_age_seconds'] as $key) $cfg[$key] = max(1, (int)$cfg[$key]);
        return $cfg;
    }
}
