# Production operations runbook

This runbook defines the minimum operated-production contract for BareBonesEshop. It does not claim that repository tests validate live DNS, TLS, mail, payment, carrier, ERP, PIM, WMS, mount durability, or operator paging.

## Objectives and signals

| Objective | Target | Signal | Alert |
|---|---:|---|---|
| Store availability | 99.9% successful readiness probes over a rolling 30 days | external `GET /readyz` every minute and `service_ready` | critical when readiness fails |
| Backup RPO | no more than 24 hours of data loss | `backup_age_seconds` | critical when no backup exists, storage cannot be listed, or age exceeds 86,400 seconds |
| Restore RTO | restore service within 60 minutes of the restore decision | incident timeline plus disposable restore drill duration | critical when the operator cannot complete the restore procedure within 60 minutes |
| Queue health | fewer than 100 pending jobs and no unresolved failed jobs | `queue_pending`, `failed_jobs` | warning at configured thresholds |
| Commerce integrations | no unresolved payment, mail, or webhook incidents | `payment_incidents`, `failed_mail`, `webhook_incidents` | payment is critical; mail and webhook failures are warnings |

The availability SLO is calculated by the external probe system; the application emits the current bounded signal and never invents historical availability. Tune thresholds in `monitoring` only after measuring normal traffic. A threshold change is an operational policy change and must be reviewed.

## Enable monitoring

Monitoring is optional and does not affect storefront, checkout, stock, tax, or order correctness.

1. Keep `adapters.metrics` as `null` to print a snapshot only, or set it to `JsonlMetricAlert` to publish JSON lines.
2. Set `metrics_log_file` to an absolute private path outside the application/document root.
3. Run `php cli/monitor.php` from the application root every five minutes. Exit `0` means no critical alert; exit `2` means a critical signal or collection failure.
4. Configure the host log shipper or scheduler to page on critical JSON records or exit `2`. The repository deliberately does not require an observability vendor.
5. Restrict the log to the application user and the monitoring shipper. Rotate it with the host's normal log policy.

Example deployment configuration:

```php
'adapters' => [
    // existing adapters...
    'metrics' => 'JsonlMetricAlert',
],
'metrics_log_file' => '/srv/private/bareboneseshop/logs/metrics.jsonl',
'monitoring' => [
    'backup_rpo_seconds' => 86400,
    'queue_pending_warning' => 100,
    'failed_jobs_warning' => 1,
    'failed_mail_warning' => 1,
    'payment_incidents_critical' => 1,
    'webhook_incidents_warning' => 1,
],
```

Do not expose the JSONL file over HTTP. Public `/healthz` and `/readyz` remain deliberately non-sensitive.

## Backup and restore

### Daily backup

1. Provision an off-site SFTP/NFS/SMB/encrypted object-storage mount outside the application root.
2. Create `.bbe-offsite-backup-target` on the mounted target, owned by the backup operator.
3. Configure `backup_storage_adapter` as `MountedBackupStorage` and `backup_storage_dir` as the mount path. Credentials remain in the host mount, never in application config or Git.
4. Schedule the existing backup command at least daily and monitor `backup_age_seconds`.
5. Treat `backup_probe_failed`, `backup_missing`, and `backup_rpo_breached` as critical. `MountedBackupStorage` fails closed if the sentinel disappears, preventing silent local fallback.

### Monthly disposable restore drill

Never run the drill against a production database.

1. Record the newest off-site backup identity, checksum metadata, start time, code SHA, and operator.
2. Create a disposable host/runtime with the same PHP and database family. Stop all disposable workers.
3. Download and restore through `BackupService`; do not manually bypass metadata, SHA-256, size, or SQLite integrity checks.
4. Run migrations only if the restore output reports an older schema, then run `php cli/test.php` and the critical browser checkout smoke against the disposable service.
5. Verify order/customer counts appropriate to the selected snapshot, invoice readability, stock-ledger consistency, and that no outbound provider credentials are enabled.
6. Record finish time, measured RTO, integrity result, pending migrations, test result, and destroy the disposable environment through the platform's normal approved lifecycle.
7. Escalate if the drill exceeds 60 minutes or any integrity/business invariant fails.

## Incident response

1. Acknowledge critical alerts within 15 minutes. Record UTC start time, alert code, store, release SHA, correlation ID, and incident commander.
2. Freeze deploys. Do not delete queue rows, logs, backups, uploads, invoices, or database files.
3. Check `/healthz`, `/readyz`, `php cli/monitor.php`, the admin operational dashboard, structured logs, mount sentinel, disk space, database connectivity, queue worker status, and the current provider status pages.
4. For a payment incident, stop automated fulfillment for affected orders but do not mark orders paid manually without provider evidence. For queue/webhook failures, preserve payloads and retry only through idempotent application mechanisms.
5. If data integrity is uncertain, stop writers and take a fresh backup before repair. Choose restore only when the recovery point is understood and approved.
6. Communicate impact, affected interval, mitigation, and next update time. Use correlation IDs rather than customer secrets in tickets or chat.
7. After recovery, verify readiness, monitoring status, checkout smoke, queue drain, payment reconciliation, stock ledger, tax snapshot, and invoice totals. Record a post-incident timeline and corrective action.

## Restore and rollback decision

- **Configuration rollback:** restore the last reviewed deployment configuration through the deployment system; never copy secrets into Git.
- **Code rollback:** deploy the last known-good immutable release SHA using a normal fast-forward/release process. Do not use force-push or destructive Git cleanup on the server.
- **Database migration rollback:** follow the migration's explicit compatible path. If no safe down migration exists, deploy forward-compatible code or restore the approved pre-migration backup in a stopped-writer window.
- **Data restore:** requires incident commander approval, confirmed recovery point, stopped writers/workers, checksum/integrity verification, and a documented reconciliation plan for transactions after the recovery point.

Rollback is complete only when readiness is green, the critical browser smoke passes, queues are controlled, provider callbacks reconcile, and metrics contain no unexplained critical alert.

## Release evidence checklist

### Automatically reproducible in repository/CI

- PHP lint and `php cli/static-analysis.php`.
- `php cli/test.php`, including backup integrity, storage-neutral off-site restore drill, metric thresholds, adapter path safety, VAT snapshots, invoice and stock invariants.
- `php cli/quality-gates.php` and Playwright customer/admin/updater smoke with axe and Lighthouse.
- SQLite, MySQL 8.0, MariaDB 11.4, PHP 8.1-8.3 CI jobs.
- Reproducible benchmark/load/failure profiles and clean Git diff checks.

### Required live operator checks

- DNS/TLS, CDN/proxy headers, production filesystem permissions and disk alerts.
- Real off-site mount durability, retention, encryption, access review, and a timed restore from the deployed target.
- External readiness SLO calculation and paging delivery to the on-call destination.
- Payment authorization/capture/refund and signed webhook with live-provider test facilities.
- Mail delivery, bounce handling, carrier label/tracking, and configured ERP/PIM/WMS contracts.
- Manual keyboard-only browser pass at 200% zoom, visible focus, landmarks, form errors, checkout, account, invoice download, and admin critical flows.
- Merchant legal identity, production tax registrations/rates, invoice numbering, rounding, reverse-charge/OSS/export wording, and accounting reconciliation.

Record the release SHA, CI run URL, all six job results, browser/WCAG evidence, benchmark profile, restore-drill evidence, operator, timestamp, remote SHA, and clean `main`. A repository-green result is not a substitute for the live-provider checks above.
