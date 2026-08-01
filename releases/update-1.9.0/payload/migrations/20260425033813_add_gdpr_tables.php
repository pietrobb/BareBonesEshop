<?php
/**
 * Migration: add_gdpr_tables
 * Generated: 2026-04-25T03:38:13+00:00
 *
 * Adds three GDPR-related tables:
 *   - gdpr_events:        append-only audit trail of all GDPR-relevant actions
 *                         (export, erase, consent_change, retention_apply, admin_anonymize, purge)
 *   - data_retention_runs: history of cli/gdpr-gc.php executions (counts, dry-run flag)
 *   - cookie_consent:     server-side persistence of visitor cookie consent choices
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $autoinc = $isSqlite ? '' : ' AUTO_INCREMENT';
        $now     = $isSqlite ? "datetime('now')" : "NOW()";

        // 1) gdpr_events — append-only audit log
        // action ∈ {export, erase, consent_change, retention_apply, admin_anonymize, purge}
        // payload_hash: sha256 of normalized payload (no raw PII stored)
        // email_hash:   salted sha256(email) using $config['gdpr_hash_salt']
        // ip_hash:      salted sha256(ip)    same salt
        $db->exec("CREATE TABLE gdpr_events (
            id INTEGER PRIMARY KEY{$autoinc},
            action TEXT NOT NULL,
            customer_id INTEGER NULL,
            email_hash TEXT NULL,
            ip_hash TEXT NULL,
            actor TEXT NULL,
            payload_hash TEXT NULL,
            note TEXT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE INDEX idx_gdpr_events_customer ON gdpr_events(customer_id)");
        $db->exec("CREATE INDEX idx_gdpr_events_action ON gdpr_events(action)");
        $db->exec("CREATE INDEX idx_gdpr_events_created ON gdpr_events(created_at)");
        $db->exec("CREATE INDEX idx_gdpr_events_email_hash ON gdpr_events(email_hash)");

        // 2) data_retention_runs — history of GC/retention executions
        $db->exec("CREATE TABLE data_retention_runs (
            id INTEGER PRIMARY KEY{$autoinc},
            run_at TEXT NOT NULL DEFAULT (datetime('now')),
            mode TEXT NOT NULL,
            policy_key TEXT NOT NULL,
            affected_rows INTEGER NOT NULL DEFAULT 0,
            details TEXT NULL,
            duration_ms INTEGER NULL
        )");
        $db->exec("CREATE INDEX idx_retention_runs_run_at ON data_retention_runs(run_at)");
        $db->exec("CREATE INDEX idx_retention_runs_policy ON data_retention_runs(policy_key)");

        // 3) cookie_consent — server-side log of cookie consent banner choices
        // categories_json: {"necessary":true,"analytics":false,"marketing":false}
        // method: "banner_accept_all" | "banner_reject_all" | "preferences" | "withdrawal"
        $db->exec("CREATE TABLE cookie_consent (
            id INTEGER PRIMARY KEY{$autoinc},
            session_id TEXT NULL,
            customer_id INTEGER NULL,
            ip_hash TEXT NULL,
            user_agent TEXT NULL,
            categories_json TEXT NOT NULL,
            method TEXT NOT NULL,
            consent_version INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE INDEX idx_cookie_consent_session ON cookie_consent(session_id)");
        $db->exec("CREATE INDEX idx_cookie_consent_customer ON cookie_consent(customer_id)");
        $db->exec("CREATE INDEX idx_cookie_consent_created ON cookie_consent(created_at)");
    }
};
