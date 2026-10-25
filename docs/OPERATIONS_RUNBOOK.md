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
| Payment funnel | customers who choose online payment reach the gateway and pay | `payment_online_started`, `payment_online_redirected`, `payment_online_paid` (real orders older than 30 minutes, last 24 hours; synthetic smoke orders excluded) | critical `payment_session_failing` when ≥ 3 online orders got no gateway session; critical `payment_funnel_stalled` when ≥ 3 got a session and none was paid |
| Browser policy | the browser blocks nothing the shop needs | `csp_violations`, `csp_form_action_violations` from `report-uri`/`report-to` (`/api/v1/csp-report`, last 24 hours) | critical `csp_form_action_violation` on a blocked form target (a dropped gateway redirect) reported by ≥ 2 distinct clients (`csp_form_action_critical`) or in ≥ 5 reports in total (`csp_form_action_reports_critical`; behind a proxy/CDN without `trusted_proxies` all reports share one client IP); warning `csp_violations_high` at 20 |

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
    'payment_funnel_window_seconds' => 86400,
    'payment_funnel_grace_seconds' => 1800,
    'payment_funnel_min_started' => 3,
    'csp_window_seconds' => 86400,
    'csp_form_action_critical' => 2,
    'csp_form_action_reports_critical' => 5,
    'csp_violations_warning' => 20,
    'synthetic_token' => '<at least 32 random characters from the deployment secret store>',
],
```

Do not expose the JSONL file over HTTP. Public `/healthz` and `/readyz` remain deliberately non-sensitive.

CSP reports need a valid absolute `app_url`; the endpoint stores only directive, blocked origin (no path or query), document path and time, keeps 7 days, and is rate limited. A violation repeated within one report batch is stored once, and at most 100 reports per reporting IP and 2000 per store are stored an hour, so a report flood cannot grow the table without bound. Browser-extension noise is dropped. Each report also keeps a salted hash of the reporting IP: `csp_form_action_violations` counts distinct reporters (`csp_form_action_critical`, default 2), so that signal cannot be raised by one client posting forged reports. The endpoint is unauthenticated, though, and a second signal exists for shops behind a proxy/CDN that is not listed in `trusted_proxies` (every report then carries the proxy IP and counts as one reporter): while `trusted_proxies` is empty, `csp_form_action_violation` also fires when the raw number of blocked form-action reports reaches `csp_form_action_reports_critical` (default 5) and they arrived in at least two separate requests (`csp_form_action_report_requests`). **One client can raise that fallback** by posting a few forged reports, so treat the alert as "verify checkout now", not proof of a broken CSP. Configure `trusted_proxies` when a proxy sits in front of the shop: the raw-count fallback is then disabled and only distinct reporters alarm; setting `csp_form_action_reports_critical` to `0` disables the fallback too. The daily `cleanup` worker job also purges reports older than 7 days, so retention holds on sites that stop receiving reports.

## Post-deploy checkout smoke

Run after **every** deploy and update, before announcing the release, and after any rollback:

```sh
BAREBONES_SYNTHETIC_TOKEN=... php cli/smoke-checkout.php --base-url=https://shop.example --product=<in-stock product id> \
    --checkout-path=/pokladna --expect-gateway=https://checkout.stripe.com
```

The script adds the product to a cart, opens the checkout page, submits the HTML form with an online payment method and requires a `302` to the gateway origin **allowed by the checkout page's own CSP `form-action`** — the check a browser applies silently. It also requires `/?page=checkout-cancel` and `/checkout/cancel` to exist and opens the gateway URL (`--no-fetch-gateway` skips that). Exit `0` = pass, `1` = a check failed (deploy is not done: roll back or fix forward), `2` = usage/configuration error.

The request carries `X-BareBones-Synthetic` with `monitoring.synthetic_token`. The shop then flags the order `is_synthetic`, sends no customer or merchant mail, emits no ERP/webhook events, refuses offline payment methods, and cancels the order with its stock reservation released as soon as the gateway session exists. Without a configured token (≥ 32 characters) every checkout is a normal order, so never run the smoke against production without it.


## Backup and restore

### Daily backup

1. Provision an off-site SFTP/NFS/SMB/encrypted object-storage mount outside the application root.
2. Create `.bbe-offsite-backup-target` on the mounted target, owned by the backup operator.
3. Configure `backup_storage_adapter` as `MountedBackupStorage` and `backup_storage_dir` as the mount path. Credentials remain in the host mount, never in application config or Git.
4. Schedule the existing backup command at least daily and monitor `backup_age_seconds`.
5. Treat `backup_probe_failed`, `backup_missing`, and `backup_rpo_breached` as critical. `MountedBackupStorage` fails closed if the sentinel disappears, preventing silent local fallback.

Each backup is a database copy (`.db` VACUUM INTO for SQLite, `.sql` single-transaction dump for MySQL/MariaDB) plus a companion `<backup>.files.tar` with issued invoice PDFs, uploads and the GDPR salt file. The files come from `invoice_storage_dir`, `uploads_dir` (default `<shop>/uploads`) and `gdpr_salt_file` in `config.php`; set `"include_files": false` in `config/backup.json` to back up the database only (default `true`; a restore then has no files archive to apply). **The files archive is sensitive:** the GDPR salt is the secret behind every pseudonymised GDPR record, so anyone holding it can re-link erased customers to their hashes. Store backups only on encrypted, access-reviewed targets and never attach them to tickets or chat.

### Retention

`php cli/backup.php --gc` (and `auto_gc_on_backup`) rotates backups by `retention` in `config/backup.json`: `daily`/`weekly`/`monthly` (newest per day/week/month), `minimum_kept` newest always, the newest `pre_migration` (default 5) `pre-migration` backups, and `manual` backups forever unless `manual_max_age_days` is set. `cli/update.php apply` keeps the newest `update_snapshots_keep` (default 3) `pre-update` snapshots in `update_storage_dir` plus the installed release's. **Personal data erased or scrubbed for GDPR stays in older backups and update snapshots until they rotate out**; state that retention period in the records of processing, and do not restore a backup to resurrect erased data (re-run the erasure after a restore).

### Restoring with `cli/restore.php`

```bash
php cli/restore.php --list                                            # restore points, newest first (files=yes marks a files archive)
php cli/restore.php 2026/10/16/eshop-20261016-020000.db               # SQLite database
php cli/restore.php 2026/10/16/eshop-20261016-020000.sql --i-stopped-writers   # MySQL/MariaDB database
php cli/restore.php <backup> --files                                  # database, then the files archive
php cli/restore.php <backup> --files-only                             # only invoices/uploads/GDPR salt
php cli/restore.php <backup> --files-only --force                     # also overwrite differing live files
```

Every restore first downloads the backup and verifies its metadata identity, size and SHA-256, and takes a `pre-restore-snapshot` backup of the current database (`--skip-snapshot` skips it for SQLite only; a MySQL/MariaDB restore refuses it). `--latest` picks the newest uncompressed backup, never its `.gz` copy.

**Run `cli/restore.php` as the application user** (the user PHP-FPM/workers run as), e.g. `sudo -u www-data php cli/restore.php ...`. Restored files keep the mode, owner and group of the file they replace, or take them from the directory they land in (new files without execute bits); owner/group can only be set when running as root, so a restore as any other user leaves new files owned by that user.

- **SQLite:** `PRAGMA integrity_check` on the copy, then an atomic swap under the exclusive `<db>.maintenance` gate, which refuses while any other process holds the database.
- **MySQL/MariaDB:** MySQL has no lock that proves nobody else is writing, so the restore is refused unless you stop web traffic (maintenance page), workers and cron and pass `--i-stopped-writers`. The dump is imported into the scratch database `backup_mysql_restore_scratch_database` (default `<db_name>_restore_scratch`; it must not exist) and each table must hold exactly the rows the dump carries. Only then is `db_name` dropped, recreated with the dumped character set/collation, re-imported and proven identical (schema, row counts, extended content checksums) to the scratch copy; if that fails, the dump of the current database taken just before is re-imported automatically and verified (same schema, exactly the rows that dump carries). Once `db_name` has been dropped, a failed restore keeps that dump (mode 0600) in the private recovery directory `backup_recovery_dir` (default `<private_runtime_dir>/restore-recovery`) and prints its path; if the automatic re-import failed too, recreate `db_name` with its character set and import that file with the `mysql`/`mariadb` client, then delete it. Dumps containing a top-level `USE`, `CREATE`/`ALTER`/`DROP DATABASE`, or a mysql client command (`SOURCE`, `SYSTEM`, `\!`, ...) are refused before anything is imported. Sessions still connected to `db_name` are refused when the server shows them (other users' sessions are visible only with the `PROCESS` privilege). The scratch database is dropped afterwards. A database cannot be renamed and `RENAME TABLE` cannot move triggers, views or routines across schemas, so the live database is replaced, not swapped.
  Required grants for `db_user`, scoped to the two schema names: `ALL PRIVILEGES` on `db_name` and on the scratch database (create/drop the schema, create tables, triggers, routines, events, insert). For example: `GRANT ALL PRIVILEGES ON shop.* TO 'shop'@'%'; GRANT ALL PRIVILEGES ON shop_restore_scratch.* TO 'shop'@'%';`. With binary logging on, importing triggers/routines as a non-SUPER user also needs `log_bin_trust_function_creators=1`.
- **Files (`--files`, `--files-only`):** the archive's SHA-256, size and entry count are verified, then it is extracted into a private staging directory by a strict reader that accepts only regular files and directories with relative paths (absolute paths, `..`, links and devices refuse the whole archive). Each file goes to the directory configured *now* (`invoice_storage_dir`, `uploads_dir`, `gdpr_salt_file`); a symlinked or non-directory path component below that directory, a non-regular target or an unwritable directory refuses the restore before anything is written (if a write still fails, the error lists the files already written). Identical files are skipped; if any live file differs from the backup nothing is written unless `--force`. Files that are not in the backup are kept. Replacing the GDPR salt with an older one changes every pseudonym computed after that backup, so restore it only together with the matching database.

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
- SQLite, MySQL 8.4, MariaDB 11.4, PHP 8.1-8.5 CI jobs.
- Checkout critical path: cross-origin browser checkout in Chromium, WebKit (iPhone) and Firefox; for releases touching the critical-path files listed in `docs/PRODUCTION_QUALIFICATION.md`, also the *Payment gateway (Stripe test mode)* workflow.
- Reproducible benchmark/load/failure profiles and clean Git diff checks.

### Required live operator checks

- `php cli/smoke-checkout.php` against the deployed shop passes, and the first `php cli/monitor.php` after deploy shows no `payment_*` or `csp_*` critical alert.
- DNS/TLS, CDN/proxy headers, production filesystem permissions and disk alerts.
- Real off-site mount durability, retention, encryption, access review, and a timed restore from the deployed target.
- External readiness SLO calculation and paging delivery to the on-call destination.
- Payment authorization/capture/refund and signed webhook with live-provider test facilities.
- Mail delivery, bounce handling, carrier label/tracking, and configured ERP/PIM/WMS contracts.
- Manual keyboard-only browser pass at 200% zoom, visible focus, landmarks, form errors, checkout, account, invoice download, and admin critical flows.
- Merchant legal identity, production tax registrations/rates, invoice numbering, rounding, reverse-charge/OSS/export wording, and accounting reconciliation.

Record the release SHA, CI run URL, all six job results, browser/WCAG evidence, benchmark profile, restore-drill evidence, operator, timestamp, remote SHA, and clean `main`. A repository-green result is not a substitute for the live-provider checks above.
