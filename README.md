# BareBones Shop

A dependency-free PHP e-commerce framework: server-side rendered storefront,
JSON API, and admin back-office. Runs on **SQLite** out of the box or **MySQL/
MariaDB** for production. Multi-language, multi-currency, with optional TecDoc
auto-parts catalog support.

No Composer dependencies, no build step — plain PHP 8.1+ and PDO.

---

## Requirements

- **PHP 8.1+** (CLI + a web SAPI) with `pdo`, `pdo_sqlite` (or `pdo_mysql`),
  `mbstring`, `json`, `dom`, `libxml`; `sodium` is required for signed updates
- A web server (Apache with the bundled `.htaccess`, nginx, or the PHP built-in
  server for development)
- Optional: MySQL/MariaDB 10.4+ when `db_driver = 'mysql'`

---

## Quick start (development)

```bash
# 1. Configuration — copy and edit
cp config.php config.local.php   # if you keep a local override, else edit config.php

# 2. Create runtime directories
mkdir -p db cache logs jobs

# 3. Build the schema (runs all migrations + regenerates admin forms/templates)
php cli/schema-build.php

# 4. (optional) Seed demo data
php cli/seed.php --count=200

# 5. Set the admin password
php cli/hash-password.php "your-strong-password"
#    → paste the hash into config.php → 'admin_password'

# 6. Serve
php -S localhost:8080
```

Open <http://localhost:8080> for the storefront and
<http://localhost:8080/admin.php> for the back-office.

### Configuration

All settings live in **`config.php`** (a single returned array). Key entries:

| Key                | Purpose                                                        |
| ------------------ | ------------------------------------------------------------- |
| `db_driver`        | `'sqlite'` (default) or `'mysql'`                             |
| `db_sqlite_path`   | Path to the SQLite file (default `db/shop.sqlite`)           |
| `db_*`             | MySQL host/port/name/user/password (when `db_driver=mysql`)  |
| `admin_user` / `admin_password` | Admin Basic-Auth user + **`password_hash()`** output |
| `adapters`         | Legacy single-default search / queue / payment / VAT / storage backends |
| `provider_defaults` | Default stable codes for payment, mail, tax, and optional ERP |
| `providers`        | Additive code→adapter registry for concurrent payment/shipping/mail/tax/ERP providers |
| `outbound_webhooks` | Config-only signed webhook endpoints, event filters, stores, and secrets |

Never commit real secrets (Stripe keys, admin password hash for production) —
keep them in an untracked local override or environment-specific config. The
machine-readable deployment contract is `config/config.schema.json`; fields marked
`x-secret` must be injected from deployment secret storage.

### Merchant setup and operations

```bash
php cli/setup.php apply --json       # idempotently creates private runtime paths
php cli/setup.php doctor --json      # versioned checklist; does not print secrets
php cli/setup.php doctor --json --strict
```

The doctor checks runtime/extensions, config schema, store and DB readiness, private
paths, payment/mail/webhook configuration, worker/cron, backup, and the shipping/ERP
integration boundaries. Warnings identify operator staging work; failures include a
concrete remediation. `apply` never edits merchant/legal data or credentials.

Load balancers may use `GET /healthz` for process liveness and `GET /readyz` for DB,
store-config, and writable runtime readiness. Both return bounded JSON with an
`X-Request-ID`; no secret or exception detail is exposed. Application and worker
operations write JSON Lines with correlation/store/channel context to
`structured_log_file`, recursively redacting secret-shaped fields.

The built-in `http-carrier` provider implements a versioned HTTPS rates → pickup
points → label → cancel → tracking lifecycle. The `http` ERP provider implements
product/customer/order export, status updates, stock pull, and health. Configure
them under `integrations.http_shipping` / `integrations.http_erp`; fixture contract
tests run without credentials, while a live sandbox smoke remains an explicit
operator step before production.

---

## External API v1 and event webhooks

The machine API is served below `/api/v1` and is separate from the browser-oriented
`api.php`. It uses store-bound bearer credentials and scopes, opaque cursor
pagination, stable DTOs, and a required `Idempotency-Key` on every mutation. The
complete contract is [`docs/openapi.yaml`](docs/openapi.yaml).

```bash
php cli/migrate.php
php cli/api-client.php create --name=ERP --store=default \
  --scopes=products:read,products:write,orders:read
php cli/api-client.php list
php cli/api-client.php revoke --key=<12-hex-key-id>
```

The create command prints the token once; only its SHA-256 hash is stored. Never
put a token in a URL, log, repository, database export, or webhook payload.
Commerce mutations write CloudEvents-compatible records to `event_outbox` in the
same database transaction. `cli/worker.php` signs each configured delivery with
`X-BBE-Event-ID`, `X-BBE-Timestamp`, and `X-BBE-Signature`, retries non-2xx
responses with exponential backoff, and moves exhausted events to `dead` after
eight attempts. Endpoint URLs and secrets live only in deployment configuration.

---

## Catalog pricing, promotions, and B2B

Migration `20260801190000_catalog_pricing_b2b` preserves each legacy
`products.category_id` as the primary assignment and adds brands, additional
categories, GTIN/MPN, integer-cent cost, variant media, dimensions, availability,
store/currency/group price lists, and time-bounded promotions. Price-list and
promotion choices are copied into each cart/order item's `_pricing` snapshot; the
existing financial snapshot remains the authority for totals and tax.

DB price lists take precedence over the legacy `pricing.json` group fallback.
Promotion priority is deterministic; `exclusive` stops after the first applied
rule and `stack` continues in priority order. Supported cart actions include fixed
or basis-point discounts, free shipping, buy-X-get-Y, and explicit gift
entitlements. Gift rules never silently decrement stock: fulfillment must turn the
entitlement into a reviewed order line.

B2B invoice purchase is fail-closed. The `invoice` method is offered only to a
logged-in customer linked to an active, approved company with invoice purchase
enabled, positive payment terms, and enough remaining integer-cent credit. Company,
group, price-list, and customer resolution is always bound to the active store;
company VAT validation remains an input to the canonical VAT snapshot rather than
a substitute for legal review.

## Inventory ledger and split fulfillment

Migration `20260801200000_inventory_ledger_fulfillment` adds store-scoped immutable
`stock_movements`, supplier/PO receipts, explicit `deny`/`backorder`/`preorder`
policies, bundle component snapshots, personalization requirements, and warehouse-
split `shipments` with `shipment_items`. Every count, feed sync, reservation,
release, receipt, shipment, and return records its delta and resulting on-hand and
reserved balances. Exact guards reject oversell, double release, duplicate receipt,
and duplicate shipment consumption.

`Inventory::createPurchaseOrder()` enforces supplier minimum and pack size;
`Inventory::receivePurchaseOrder()` requires an idempotency key. `Fulfillment::planOrder()`
creates one shipment per frozen warehouse allocation, while `Fulfillment::ship()`
can dispatch them independently. A final whole-order shipment fails closed while
backordered units remain. Prices and taxes remain on the unchanged order-item and
financial snapshots; bundles affect only the auditable physical allocation layer.

## Partial returns, exchanges and credit notes

Migration `20260801210000_returns_credit_notes` adds store-scoped return labels,
idempotent partial receipts, explicit `restock`/`discard`/`repair` decisions,
exchange reservations, item refund batches, and immutable credit-note lines. Multiple
RMAs may be open for one delivered order, but all non-rejected requests share an
exact ordered-quantity cap. Only received quantities can be exchanged or credited.

`InvoiceService::credit()` derives every integer-cent gross/net/VAT amount from the
original invoice financial snapshot by deterministic prefix allocation. Replays
return the original credit note, pending or completed refunds reserve their item
quantity, and the worker marks partial payment refunds without closing the whole
order. A restock follows the frozen shipment allocation and records a G7 ledger
movement; discard and repair never change on-hand stock.

## Admin identity, queues, and operations

Migration `20260801220000_admin_identity_queue_operations` adds database-backed
admin accounts, encrypted TOTP seeds, one-time recovery codes, store grants,
tracked devices, revocable server-side sessions, and a durable database queue.
Once any DB account exists, legacy config/JSON credentials no longer authenticate.
Generate the deployment-only encryption key and first owner from the CLI; password
input is accepted only through the process environment:

```bash
php cli/migrate.php
php cli/admin-user.php keygen
BBE_ADMIN_IDENTITY_KEY=<secret> BBE_ADMIN_PASSWORD=<secret> \
  php cli/admin-user.php create --username=ops --role=owner --store=default
```

Set `queue.backend` to `file`, `database`, or `redis`. File mode retains the
single-worker lock; database and Redis modes use opaque atomic claims and leases
so multiple workers may run concurrently. Jobs, mail, audit records, and outgoing
webhooks carry correlation IDs. The store-scoped admin dashboard shows bounded
failed-job, mail, payment, and webhook incidents without exposing secrets.

---

## Migrations

Schema changes are **forward-only**, file-based, and checksum-verified. There is
no `down()` — to revert, write a new migration.

```bash
php cli/migrate.php             # apply all pending migrations
php cli/migrate.php status      # show applied / pending / checksum state
php cli/migrate.php create add_foo_table   # generate a stub migration
php cli/migrate.php baseline    # mark current files as applied (no run)
```

By default a backup labelled `pre-migration` is taken before any pending
migration runs (configurable via the backup service). Migration files live in
`migrations/` and are named `YYYYMMDDHHMMSS_description.php`, each returning an
anonymous class with an `up(PDO $db, bool $isSqlite)` method that keeps
SQLite/MySQL parity.

---

## Production updates

Updates are distributed as versioned delta directories. Every changed path has
both its expected old hash and new hash, so a customer-modified core file is
reported as a conflict and is never overwritten. `config.php`, `.env`, runtime
data, uploads, databases, logs, and per-deployment version state cannot be part
of a package.

```bash
# Release side: one-time key generation; keep the secret key offline
php cli/build-update.php keygen --public=/secure/update-public.key \
  --secret=/secure/update-secret.key

# Compare two clean release trees and sign the manifest
php cli/build-update.php --from=/releases/1.0.0 --to=/releases/1.1.0 \
  --base-version=1.0.0 --version=1.1.0 --output=/releases/update-1.1.0 \
  --secret-key=/secure/update-secret.key

# Shop side: verify server capabilities before changing anything
php cli/update.php doctor

# One-time adoption for a pre-updater install (only after identifying it)
php cli/update.php baseline --version=1.0.0

# Verify the signed package against the identified installation
php cli/update.php doctor --package=/releases/update-1.1.0

# Inspect first, then apply (apply always creates a snapshot)
php cli/update.php check --package=/releases/update-1.1.0
php cli/update.php apply --package=/releases/update-1.1.0
php cli/update.php list
php cli/update.php rollback --snapshot=20260801120000-abcdef123456
```

Set `update_public_key` and `update_storage_dir` in `config.php`; installed
version metadata and rollback snapshots stay in that private directory. Existing
pre-updater shops require one explicit `baseline` after their deployed release
has been identified. Run `doctor` on each deployment before its first update; it
checks PHP/sodium, the public key, private storage and locking, database health,
a real database backup/restore probe, and (when supplied) the signed package's
compatibility with installed files. `doctor --json` is suitable for deployment
automation. Packages are Ed25519-verified and unsigned packages are refused
unless the operator supplies `--allow-unsigned` explicitly. Rollback refuses to
overwrite files changed after an update unless `--force` is supplied after
manual review.

Code-only packages support SQLite, MySQL, and MariaDB. Migration packages use a
verified SQLite snapshot or, on MySQL/MariaDB, a transactional dump that must
successfully restore into a guarded disposable `*_update_probe` database before
any files change. See the standalone [AI update runbook](docs/AI_UPDATE_RUNBOOK.md)
for the v1.8.0 bootstrap, production state machine, MySQL privileges, maintenance
window, evidence, and rollback procedure.

---

## Tests

A zero-dependency test runner (no PHPUnit) covering services, the JSON API,
migrations, and an optional HTTP smoke pass.

```bash
mkdir -p db          # the runner writes db/test.sqlite
php cli/test.php
```

- Exit code is **0** when all tests pass, **1** on any failure.
- The **HTTP smoke** block self-skips when no server is reachable at
  `SMOKE_URL` (default `http://localhost:8080`) — so the suite runs fully
  headless in CI. To include it locally, start `php -S localhost:8080` first.
- To test against MySQL, set `TEST_MYSQL_DSN` (and `TEST_MYSQL_USER` /
  `TEST_MYSQL_PASS`).

---

## Features

**Storefront** — catalog with categories, parametric/full-text search, product
detail with variants, cart, multi-step checkout, multi-language &
multi-currency, immutable quote→order→payment→invoice→OSS VAT snapshots, coupons, SEO pack (dynamic `sitemap.xml`,
`robots.txt`, JSON-LD: Organization / WebSite searchbox / BreadcrumbList /
Product / AggregateRating).

**Customer accounts** — registration, login, password reset, addresses, order
history, invoices, GDPR self-service (data export + erase), product reviews,
**wishlist**, and **returns/RMA** requests.

**Admin back-office** — products, orders & fulfilment, invoices, warehouses &
stock, shipping/payment pricing engines, feeds/import, CMS pages, GDPR tools,
review moderation, RMA processing, and **customer management** (search, status
toggle, per-customer orders/addresses/RMA). Role-based access control.

**JSON API** (`api.php`) — ~30 actions with rate limiting, CSRF on mutating
requests, and registry-routed payment/shipping webhook endpoints.

**Operations** — store-scoped provider registry for concurrent payment, shipping,
ERP, mail, and tax adapters; legacy single-adapter config remains supported. The
file queue freezes provider codes in order snapshots so retries use the original
provider. Merchant doctor/apply, config schema, liveness/readiness, structured JSON
logs, and complete generic HTTPS shipping/ERP contracts provide an AI-first
production path. Backups, migrations, and CLI tools live under `cli/`.

---

## Project layout

```
.
├── index.php              Storefront router
├── api.php                JSON API
├── admin.php              Admin back-office
├── account.php            Customer account portal
├── product.php · wishlist.php · page.php · sitemap.php · robots.php
├── config.php             Single-file configuration
├── src/                   ~30 classes (Db, Security, Products, Orders, Rma, …)
├── migrations/            Forward-only schema migrations
├── lang/                  UI translations (sk, en, cs, de, hu, pl, …)
├── cli/                   CLI tools (migrate, schema-build, seed, test, …)
└── .github/workflows/     CI (lint + test matrix on push/PR)
```

---

## Continuous integration

`.github/workflows/ci.yml` runs on every push and pull request: a `php -l`
syntax check across all sources plus the full `cli/test.php` suite, on a
PHP **8.1 / 8.2 / 8.3** matrix.
