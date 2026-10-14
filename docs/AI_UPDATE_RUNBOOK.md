# BareBonesEshop update runbook for AI operators

This runbook is the production procedure for signed BareBonesEshop delta updates. It is written for an AI deployment operator, but every command is deterministic and can be audited by a human.

## Non-negotiable rules

- Use an immutable release package from the trusted release source.
- Never use `--allow-unsigned` in production.
- Never edit `manifest.json`, `manifest.sig`, or files under `payload/`.
- Never use `rollback --force` without reviewing every post-update file change with the operator.
- Keep HTTP traffic, cron jobs, queue workers, imports, and other database writers stopped from immediately before `apply` until verification succeeds.
- Stop on every non-zero exit code. Do not bypass a failed `doctor` or `check`.
- Keep `update_storage_dir` outside the web root and include it in protected infrastructure backups.

## Required configuration

Copy the release public key to a trusted path and configure the deployment's existing `config.php`:

```php
'update_storage_dir' => dirname(__DIR__) . '/bareboneseshop-private/updates',
'update_public_key' => '/secure/path/update-public.key',
```

PHP CLI must have Sodium enabled. Confirm with:

```bash
php -r "exit(extension_loaded('sodium') ? 0 : 1);"
```

## One-time bootstrap from v1.8.0

Version 1.8.0 predates the updater, so it cannot install its own updater. Perform this bootstrap once:

1. Confirm that the deployment is really v1.8.0. Do not infer the version from a directory name. Compare the deployed core against the immutable `v1.8.0` source and preserve all customer changes.
2. Put the shop and all writers into maintenance mode.
3. Make an independent filesystem and database backup using the deployment platform.
4. From the trusted `v1.9.0` source, copy only these bootstrap files into the v1.8.0 deployment:
   - `cli/cli-only.php`
   - `cli/update.php`
   - `src/UpdatePackage.php`
   - `src/Updater.php`
5. Copy the trusted `releases/update-public.key` outside the web root and add `update_storage_dir` and `update_public_key` to the deployment's existing `config.php`. Do not replace `config.php` with the example file.
6. Record the identified baseline:

```bash
php cli/update.php baseline --version=1.8.0
```

7. Verify the signed v1.8.0 to v1.9.0 package and the installed file hashes:

```bash
php cli/update.php doctor --package=/trusted/releases/update-1.9.0 --json
php cli/update.php check --package=/trusted/releases/update-1.9.0
```

8. If either command reports a conflict, stop. Preserve the customer file and resolve the difference deliberately; never overwrite it automatically.
9. Apply and verify:

```bash
php cli/update.php apply --package=/trusted/releases/update-1.9.0
php cli/update.php list
```

After this adoption, the deployment has private version state and future updates use the normal procedure below.

## Normal signed update procedure

Set the package path once in the deployment shell:

```bash
PACKAGE=/trusted/releases/update-X.Y.Z
```

Then execute this state machine:

1. **Observe** — record current application version, health, free disk space, active jobs, and database driver.
2. **Quiesce** — enable maintenance mode and stop workers, cron, imports, and all other writers.
3. **Prove readiness**:

```bash
php cli/update.php doctor --package="$PACKAGE" --json
```

The result must be `READY`. Archive the JSON with the deployment log.

4. **Inspect preflight**:

```bash
php cli/update.php check --package="$PACKAGE"
```

The result must say `Preflight passed`. Any locally modified package path is a hard stop.

`doctor --package`, `check` and `apply` also render checkout with the package's new core and the installed themes, `stores.json` and `blocks/stores/*` overrides (in a private copy under `update_storage_dir`, with an in-memory database). Every `[BLOCK] Theme checkout contract: <theme> / store <id> / storefront/checkout / <scenario> [<code>]` line is a hard stop: hand the named template and field to the theme owner, fix it in the installation (or in a new package), and rerun `check`. Never delete or rename a theme just to pass the gate unless no store uses it. The gate covers the checkout data/HTML contract only; keep the deployment-specific HTTP, browser and payment smoke tests in step 6.

5. **Apply**:

```bash
php cli/update.php apply --package="$PACKAGE"
```

`apply` repeats preflight under an exclusive updater lock, creates a private snapshot, installs only approved paths, runs migrations, and automatically restores the snapshot if migration fails.

6. **Verify while traffic remains stopped**:

```bash
php cli/update.php list
php cli/migrate.php status
```

Also run deployment-specific HTTP smoke tests, worker checks, payment-provider checks, and database health checks. Confirm the reported installed version and retain the rollback snapshot ID.

7. **Resume** — only after all checks pass, restart workers and traffic. Monitor logs and key transactions.

### First adoption of the theme gate

The installed updater runs the check, so an installation whose `src/Updater.php` predates `src/ThemeUpgradeGate.php` cannot run the gate for the package that introduces it. For that one hop, before `doctor`/`check`/`apply`, run the gate from the trusted, unpacked new release tree against the installation and the same signed package:

```bash
BAREBONES_UPDATE_PUBLIC_KEY=/secure/path/update-public.key \
  php /trusted/releases/X.Y.Z/cli/theme-check.php --installed=/path/to/shop --package="$PACKAGE"
```

It must print `GO` (exit 0). It reads the installation and writes only a temporary private copy, removed after the verdict. Later updates run the gate inside `cli/update.php` automatically.

## Provider registry rollout

Provider registry updates are code/config changes; they do not require a schema migration. Existing `adapters.payment` and `adapters.integration` entries remain valid defaults. To enable concurrent providers, add stable lowercase codes under `providers` and choose defaults under `provider_defaults`; never place credentials in these maps.

Before resuming traffic, verify each configured code resolves to the expected class and capability set, then smoke-test one checkout/webhook or equivalent operation per active provider. Inspect a newly created order and confirm `financial_snapshot.providers` and the payment/shipping snapshots contain the selected codes. Restart workers only after this check: queued retries intentionally resolve the provider frozen on the original order, so removing or renaming an in-use code is a breaking operational change. Roll back the code/config together if a frozen code can no longer resolve.

## Event outbox and external API v1 rollout

This release adds database tables, an `/api/v1` machine boundary, and signed outgoing webhooks. Keep all writers stopped while the migration runs. After apply, confirm `event_outbox`, `webhook_deliveries`, `api_clients`, and `api_idempotency` exist, then restart exactly one worker for the first delivery sweep.

Do not pre-seed plaintext credentials or webhook secrets in SQL. Create API credentials only after migration with `php cli/api-client.php create`; capture the returned token once in the target secret manager and retain only the non-secret key ID in deployment evidence. Grant the minimum scopes and revoke the credential immediately if the one-time token output is exposed.

Configure `outbound_webhooks` only in the deployment's protected config. Each active secret must contain at least 32 bytes. Start with one endpoint and one narrow event filter, submit a controlled commerce mutation, then verify the same event ID appears in `event_outbox` and a successful `webhook_deliveries` audit row. The receiver must verify the HMAC over the untouched raw body, reject timestamps outside 300 seconds, and persist event IDs in its own business transaction before returning 2xx. A non-2xx response is intentionally retried; do not manually replay the originating order/payment mutation.

Before resuming all traffic, inspect for `retry` or `dead` outbox rows and verify one read plus one idempotent API mutation against a non-production product. Rollback restores the pre-migration database snapshot and code together. If rollback happens after external deliveries were accepted, event IDs already consumed by receivers remain consumed; never delete receiver deduplication records to simulate rollback.

## Merchant setup, observability, shipping and ERP rollout

Run `php cli/setup.php apply --json`, then archive `php cli/setup.php doctor --json --strict`. Apply may create only configured private runtime directories and private setup state; it does not fill credentials, legal data, cron, or provider accounts. Resolve every failure and review every warning. Validate config changes against `config/config.schema.json`, and never copy an `x-secret` value into evidence.

Configure the scheduler entry declared by `operations.worker_cron`, start one worker, execute one controlled job, and correlate its JSON log with the job ID. Probe `/healthz` and `/readyz`; readiness must be `ready` before traffic resumes. Monitor `structured_log_file` permissions and rotation in the deployment platform.

For `http-carrier`, prove rates, pickup-point selection, label creation, retry returning the original AWB, cancellation, cancellation replay, and tracking against the carrier sandbox. For `http` ERP, prove order creation, external ID persistence, and a later status mutation against the same external ID. Fixture tests prove the repository contract only; they are not evidence that a live account works. Keep API keys solely in deployment secret configuration. On failure, disable the provider/default together without renaming a code frozen in existing order snapshots.

## Catalog pricing and B2B rollout

Migration `20260801190000_catalog_pricing_b2b` is additive. Keep writers stopped and, after apply, verify that every existing product has exactly one `product_categories.is_primary=1` row matching its unchanged `products.category_id`. Existing product prices, customer identities, orders, and financial snapshots must be byte-for-byte unchanged; the new price lists and promotions start empty. All legacy customers receive `store_id=default` and no group/company assignment.

Configure one non-production customer group, company, and price list before enabling broad rules. Prove guest isolation, the intended store and currency, quantity tiers, validity boundaries, and the exact `_pricing` item snapshot. Add promotions in disabled form, then activate one at a time and prove priority, `exclusive` versus `stack`, cent rounding, subtotal cap, free shipping, and buy-X-get-Y. A gift result is an entitlement only; do not claim fulfillment until a reviewed order-line workflow consumes it.

Before enabling invoice purchase, confirm company approval, VAT evidence, integer-cent credit limit, positive payment terms, and the current unpaid exposure. Verify the invoice method is absent for guests, unapproved companies, another store, and an amount above remaining credit. Enabling a price list or promotion changes new quotes immediately but never rewrites existing order/payment/invoice snapshots.

Rollback restores the pre-migration database and code together. If orders were accepted after enabling new prices, stop and reconcile those externally visible orders before rollback; never recreate their totals from the old catalog or delete them to make the schema appear reversible.

## Inventory ledger and fulfillment rollout

Migration `20260801200000_inventory_ledger_fulfillment` is additive but backfills one `opening_balance` movement per existing warehouse/SKU. Keep writers and workers stopped, apply the migration, then prove that each `warehouse_stock` row has exactly one matching opening key and unchanged `qty`/`reserved` values. Verify warehouses and stock remain owned by `default` for legacy data and that another store cannot read or reserve them.

Before resuming traffic, run one controlled count, reserve/release, two-warehouse order, partial shipment, cancellation, and idempotent PO receipt. Reconcile every operation against `stock_movements.quantity_delta`, `reserved_delta`, `on_hand_after`, and `reserved_after`; no resulting balance may be negative. Enable `backorder` or `preorder` only per reviewed SKU. A backordered order cannot use the whole-order shipped transition until physical allocation exists. Bundle definitions and personalization schemas affect new orders only; existing financial snapshots and totals must remain byte-for-byte unchanged.

Rollback must restore code and the pre-migration database snapshot together. If any receipt or shipment occurred after apply, stop and reconcile the physical warehouse and external carrier/ERP state before rollback; never replay a receipt key, shipment, or order mutation merely to recreate ledger rows.

## Returns and credit-note rollout

Migration `20260801210000_returns_credit_notes` is additive. Keep traffic, workers, refund jobs, and warehouse writers stopped while applying it. Existing RMA rows receive `store_id=default`; their status, requested quantities, orders, invoices, stock and payment records are not rewritten. Confirm `return_labels`, `return_receipts`, `return_exchanges`, `order_refunds`, and `credit_note_items` exist and that `invoices.credit_snapshot` is nullable.

Before resuming traffic, use a non-production delivered order to prove two simultaneous partial RMAs without exceeding ordered quantity. Create and replay one return label and receipt key, then verify only `restock` creates exactly one matching `stock_movements` delta; `discard` and `repair` must leave on-hand unchanged. Prove an exchange and a credit cannot reserve the same received quantity.

Issue one partial credit and reconcile its line gross/net/VAT to the original invoice financial snapshot, source fingerprint, negative credit-note totals, refund batch, and provider amount. Replay the same idempotency key and confirm no new document, refund or ledger movement appears. A partial provider success must leave the order lifecycle intact and set only `payment_status=partially_refunded`; manual refunds require an explicit audited completion reference.

Rollback restores the pre-migration database and code together. If a label, warehouse receipt, provider refund, exchange or credit note became externally visible after apply, stop and reconcile that external fact before rollback; never delete or replay it to make the restored schema appear current.

## Admin identity, queue, and operations rollout

Migration `20260801220000_admin_identity_queue_operations` is additive. Keep traffic and workers stopped, apply it, and confirm the six `admin_*`/`queue_jobs` tables plus correlation columns and indexes exist. Generate `admin_identity_key` with `php cli/admin-user.php keygen`, store it only in deployment secret storage, and back it up separately from database exports. Losing or rotating it without a controlled TOTP re-enrollment makes existing MFA seeds unusable.

Create the first owner with `BBE_ADMIN_PASSWORD` in the process environment and enroll TOTP before resuming admin traffic. Capture recovery codes once in the approved secret manager; only keyed hashes remain in the database. Grant the minimum store permissions, test an ungranted store fails closed, then remove or retain legacy config credentials according to the rollback plan. Once any DB account exists, the application intentionally refuses legacy admin authentication even if every DB account is disabled.

Start with `queue.backend=database` unless the deployment has an operated Redis service. Run two workers against controlled jobs and prove only one claim completes each job, a failed job reaches the dashboard, and its correlation ID matches structured logs. For Redis, verify TLS/network isolation and secret injection at the platform boundary; never put the password in evidence. File mode remains the zero-dependency rollback option but intentionally permits one worker only. Before traffic resumes, inspect failed mail, payments, webhooks, and jobs per store and verify no incident payload or log contains credentials, TOTP seeds, session tokens, or recovery codes.

Rollback restores code and database together. Stop workers first: for SQLite, rollback (and `cli/restore.php`) raises a `<db>.maintenance` gate that refuses new connections and refuses the whole rollback without touching code or data while any other process still holds the database. Do not switch queue backends while jobs remain pending unless jobs are explicitly drained or reconciled, because backends do not mirror each other. Preserve externally completed mail, payment, and webhook facts during reconciliation.

## Theme, content, feeds, i18n, and accessibility rollout

Migration `20260801230000_theme_content_marketing` is additive. Keep writers stopped, apply it, and confirm `content_menu_items`, `content_banners`, and `content_redirects` exist and that existing `pages` rows retain their body, status, translations, and `store_id=default`. New page SEO/type/template columns have neutral defaults; menus, banners, and redirects start empty, so the storefront keeps its legacy navigation and hero until content is deliberately published.

Set every store's reviewed `theme` code and verify its `theme.json` plus declared assets are present before traffic resumes. Manifests and block source must return HTTP 403 while declared `/themes/{theme}/assets/*` files remain available with long-lived caching. Configure an absolute trusted `app_url`, then fetch `/feeds/google.xml` and `/feeds/meta.csv`; reconcile one product ID, URL, availability, image, currency, and integer-cent-derived price against the active store catalog. Another store must not see those content rows or products.

Run `php cli/i18n-parity.php` and `php cli/quality-gates.php` and retain their output. The latter proves declared CSS/JS/block byte budgets and automated WCAG 2.2 AA invariants, but the operator must still keyboard-test focus order, skip navigation, dialogs and checkout, inspect 200%/400% zoom and reflow, and test representative pages with a screen reader. Confirm analytics/marketing hooks are absent with no consent and after reject-all, and are emitted only for the opted-in category. Publish a draft landing page, scheduled banner, menu item, and redirect in one non-production store; review sanitized output, canonical/hreflang, audit rows, redirect status, and legacy fallback before broader publication.

Rollback restores code and the pre-migration database snapshot together. If redirects or feed URLs were consumed externally, remove or update them in Google/Meta and caches before rollback; never preserve new content tables with old code or delete content solely to make rollback appear clean.

## Rollback procedure

Keep traffic and writers stopped. Review whether any package-managed file changed after the update, then run:

```bash
php cli/update.php rollback --snapshot=YYYYmmddHHMMSS-abcdef123456
```

Normal rollback refuses to overwrite post-update file changes. If it refuses, inspect and preserve those changes. `--force` is a last-resort operator decision, not an AI default.

After rollback, rerun health and migration-status checks before restoring traffic.

## MySQL and MariaDB migration updates

Code-only packages need no database dump adapter. A package containing migrations is accepted only when all requirements below are met.

### Configuration

Reserve a database name ending in `_update_probe`. It must not exist before the command; the updater creates it, restores the dump into it, compares it, and drops it. Configure:

```php
'update_mysql_probe_database' => 'barebone_shop_update_probe',
'update_mysql_dump_binary' => 'mysqldump', // or an absolute path / mariadb-dump
'update_mysql_client_binary' => 'mysql',   // or an absolute path / mariadb
```

The configured `db_user` needs its normal rights on the live database plus the rights required to create, drop, dump, and restore both the live and reserved probe schema. Scope grants to those two schema names; do not grant global administrator access merely for updates. Dumping all supported objects requires access to tables, views, triggers, routines, and events.

The updater refuses:

- a probe name without the `_update_probe` suffix;
- a probe schema that already exists;
- non-InnoDB base tables, because `--single-transaction` cannot guarantee their consistency;
- missing dump/client binaries or insufficient privileges;
- a dump whose disposable restore differs in tables, columns, indexes, constraints, triggers, routines, events, row counts, or extended table-content checksums.

Credentials are written only to a mode-`0600` temporary MySQL option file and are never passed as process arguments. The temporary file is deleted after each client invocation.

### What happens before files change

For a MySQL/MariaDB migration package, `apply` performs:

1. a transactional `mysqldump` including triggers, routines, events, and binary data;
2. SHA-256 recording of the private dump;
3. creation of the reserved disposable probe schema;
4. a real import into the probe schema;
5. schema/object inventory, exact row-count, and extended table-content checksum comparison with the live source;
6. removal of the probe schema;
7. only then, installation of files and execution of migrations.

On rollback, the stored dump checksum and disposable restore are proved again before the live database is dropped and recreated. The restored live inventory is checked again afterward.

### First adoption of MySQL migration support

An older updater blocks MySQL migration packages before it installs package files. Therefore the release that first introduces `UpdateDatabaseBackup.php` and `MysqlUpdateDatabaseBackup.php` must be deployed as a **code-only signed update**. Only a later package may rely on that adapter for database migrations. Do not combine first adoption of the adapter with a MySQL migration.

The financial-snapshot migration (`20260801141000_financial_snapshots`) belongs in that later migration-capable package. After apply, verify that both `orders.financial_snapshot` and `invoices.financial_snapshot` exist. Existing rows must remain `NULL`; only newly created orders receive versioned snapshot JSON, and each new invoice copies the same fingerprint. A rollout must not backfill historical snapshots from current rates.

### Additional operational requirement

The database dump is transactionally consistent, but the application must still be quiesced through apply and verification. Otherwise writes accepted after the dump could be lost if rollback becomes necessary.

## Evidence the AI operator must retain

For every deployment, retain:

- package version and immutable source reference;
- `doctor --json` output;
- `check` output;
- apply output and snapshot ID;
- migration status and smoke-test results;
- start/end of the maintenance window;
- rollback output, if rollback was used.

A successful command is not sufficient evidence by itself: production completion means health checks passed and traffic resumed on the intended version.
