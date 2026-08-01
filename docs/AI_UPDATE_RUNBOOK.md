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

## Provider registry rollout

Provider registry updates are code/config changes; they do not require a schema migration. Existing `adapters.payment` and `adapters.integration` entries remain valid defaults. To enable concurrent providers, add stable lowercase codes under `providers` and choose defaults under `provider_defaults`; never place credentials in these maps.

Before resuming traffic, verify each configured code resolves to the expected class and capability set, then smoke-test one checkout/webhook or equivalent operation per active provider. Inspect a newly created order and confirm `financial_snapshot.providers` and the payment/shipping snapshots contain the selected codes. Restart workers only after this check: queued retries intentionally resolve the provider frozen on the original order, so removing or renaming an in-use code is a breaking operational change. Roll back the code/config together if a frozen code can no longer resolve.

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
