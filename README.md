# BareBones Shop

A dependency-free PHP e-commerce framework: server-side rendered storefront,
JSON API, and admin back-office. Runs on **SQLite** out of the box or **MySQL/
MariaDB** for production. Multi-language, multi-currency, with optional TecDoc
auto-parts catalog support.

No Composer dependencies, no build step — plain PHP 8.1+ and PDO.

---

## Requirements

- **PHP 8.1+** (CLI + a web SAPI) with `pdo`, `pdo_sqlite` (or `pdo_mysql`),
  `mbstring`, `json`, `dom`, `libxml`
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
| `adapters`         | Pluggable search / queue / payment / VAT / storage backends   |

Never commit real secrets (Stripe keys, admin password hash for production) —
keep them in an untracked local override or environment-specific config.

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
multi-currency, VAT (incl. OSS), coupons, SEO pack (dynamic `sitemap.xml`,
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
requests, and a Stripe webhook endpoint.

**Operations** — pluggable adapters (search/queue/payment/VAT/storage), file
queue + worker, backups, migrations, and a suite of CLI tools under `cli/`.

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
