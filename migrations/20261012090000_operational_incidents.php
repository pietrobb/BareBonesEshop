<?php
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $short = MigrationSql::short($isSqlite);
        $text = MigrationSql::text($isSqlite);
        $suffix = $isSqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $db->exec("CREATE TABLE IF NOT EXISTS operational_incidents (
            id {$short} PRIMARY KEY,
            store_id {$short} NOT NULL,
            code {$short} NOT NULL,
            phase {$short} NOT NULL,
            severity {$short} NOT NULL,
            correlation_id {$short} NOT NULL,
            occurred_at BIGINT NOT NULL,
            context_json {$text} NOT NULL
        ){$suffix}");
        MigrationSql::index($db, $isSqlite, 'operational_incidents', 'idx_incidents_store_time', 'store_id, occurred_at');
        $db->exec("CREATE TABLE IF NOT EXISTS operational_alert_state (
            fingerprint {$short} PRIMARY KEY,
            store_id {$short} NOT NULL,
            code {$short} NOT NULL,
            phase {$short} NOT NULL,
            severity {$short} NOT NULL,
            correlation_id {$short} NOT NULL,
            window_started_at BIGINT NOT NULL,
            occurrences INTEGER NOT NULL DEFAULT 0,
            notified_count INTEGER NOT NULL DEFAULT 0,
            last_queued_at BIGINT NOT NULL DEFAULT 0,
            last_notification_at BIGINT NOT NULL DEFAULT 0,
            notification_status {$short} NOT NULL,
            processing_until BIGINT NOT NULL DEFAULT 0,
            delivered_hashes {$text} NOT NULL
        ){$suffix}");
        MigrationSql::index($db, $isSqlite, 'operational_alert_state', 'idx_alerts_store', 'store_id');
        $db->exec("CREATE TABLE IF NOT EXISTS operational_monitor_state (
            store_id {$short} NOT NULL,
            probe {$short} NOT NULL,
            status {$short} NOT NULL,
            checked_at BIGINT NOT NULL,
            PRIMARY KEY (store_id, probe)
        ){$suffix}");
    }
};
