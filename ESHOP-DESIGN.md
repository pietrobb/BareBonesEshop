# BareBones Shop — Design Document

> Core B2C commerce without Magento-level complexity.
> Covers 80% of real e-shop needs. The remaining 20% is deliberately left out.

---

## What this IS and what it IS NOT

**IT IS:** A lightweight commerce engine for small to mid-sized B2C shops. JSON-driven configuration,
a pre-computed catalog, SSR, multiple languages, swappable adapters in 5 places.

**IT IS NOT:** A Magento replacement for enterprises with dozens of integrations, hundreds of admin roles
and custom business logic. It is not "Magento, only smaller". It is a different approach to a different set of problems.

### Explicit trade-offs and boundaries

| Decision | Benefit | Cost |
|---|---|---|
| No framework | Readability, speed, no third-party CVEs | You own all of auth, validation and escaping yourself. Less code ≠ less responsibility. |
| JSON configuration | Generator pattern, no GUI for the schema | A schema change = a versioned build + backfill steps. They are migrations under another name — we admit it. |
| MySQL-first, SQLite for dev/small | Broad compatibility | Two backends = two performance profiles, two test matrices. It is not "one switch". |
| Filesystem job queue | Zero dependencies, simplicity | Single-node only. Business-critical operations (order processing) run synchronously in the request, not through the queue. |
| Pre-computed cache | Lightning-fast TTFB for reads | Cache invalidation across several store×language×pricing group combinations can be expensive. You need to know exactly what to precompute. |
| No Elasticsearch | No extra attack surface | FTS5/FULLTEXT has no synonyms, typo tolerance or merchandising rules. Enough for shops without advanced search UX. SearchAdapter allows a replacement. |
| 83,668 PHP LoC baseline | Small runtime without a framework | The reproducible G14 baseline includes 299 versioned-candidate PHP files including migrations and the extensive test suite; the historical estimate of ~6,000 LoC was false. |

### Performance claims

Historical numbers in the design examples (e.g. <5 ms, <20 ms or 1M) are **unverified targets, not results**. The authoritative measured state is only in the section "Measured performance and failure baseline"; the 100k measurement disproved several old targets and the 1M profile remains unqualified.

---

## Principles

1. **JSON-driven generator pattern** — catalog.json defines product types, attributes and filters. SQL tables, admin forms and import templates are generated from it. A schema change = a versioned build with migration steps.
2. **Pre-computed where it makes sense** — category listings and product details are precomputed into flat JSON. Dynamic operations (cart, checkout, search) go through PHP+DB. Not "precompute everything".
3. **Multiple languages in the core** — BBF has 32 languages. So does the shop. Translations are native, not a plugin.
4. **Async for non-critical operations** — Cache rebuild, e-mails, image resize, import. Business-critical operations (order+payment) are synchronous in the request. A filesystem queue for single-node, a DbQueue adapter for multi-node.
5. **MySQL/MariaDB primary, SQLite for dev/small** — MySQL is the production default. SQLite for dev, demos and small shops. They are not identical — different FTS, different locking, different failure modes. Both are tested, but MySQL takes precedence in decisions.
6. **Security = our own responsibility** — Prepared statements, CSRF, rate limiting, CSP. No framework does not mean less security work — it means more. We own the whole auth and validation stack.
7. **No magic, no illusions** — Readable code. Clear boundaries. Explicit trade-offs.

---

## V1 scope

Multiple languages and multiple stores are in the core from day one. Not as a "V2 feature" — as the foundation.
BBF has 32 languages out of the box. The shop will too. Bolting languages and stores onto existing
single-language code is always harder than designing it right from the start.

`StoreContext` is resolved once per request from an explicit key, the domain or the longest URL prefix; the domain takes priority. `id` owns orders, cache, payment transactions, shipping labels and invoices. Optional `catalog_scope` and `provider_scope` let several markets safely share a catalog and payment/shipping configuration; without overrides a new store is isolated. The migration assigns all legacy data to the literal `default` store and the job queue carries store/channel into workers. An updater rollback restores the pre-migration database snapshot.

`Adapters::registry()` maps stable provider codes to concurrent payment, shipping, ERP, mail and tax adapters, their capabilities and the active `provider_scope`. Checkout freezes the selected codes in a fingerprinted financial snapshot; the payment session, webhook, refund, expiry, mail and ERP worker then resolve the original provider, not the current global default. Legacy `adapters.payment` and `adapters.integration` remain a fallback for existing installations.

`Outbox::record()` writes versioned order/payment/stock/customer/product events only inside an open domain transaction. The worker delivers them through config-only endpoints as a CloudEvents-compatible envelope, with an HMAC signature bound to the timestamp, event ID and the exact raw body; endpoints that succeeded are not repeated on retry and exhausted events end in an auditable dead-letter state. The separate `/api/v1` uses neither a browser session nor CSRF: the bearer credential is store-bound, the DB holds only its hash, scopes are explicit and mutations require a per-client idempotency key. `docs/openapi.yaml` is the public additive-first contract for DTOs, cursor pagination and webhook delivery.

`Setup::doctor()` returns a versioned machine-readable onboarding checklist and `apply` idempotently creates only safe private runtime paths; the deployment config has a JSON Schema with secret annotations. `/healthz` is side-effect-free liveness, `/readyz` checks DB/store/runtime dependencies without leaking details and `Logger` writes redacted JSON Lines with correlation/store/channel context. The generic `HttpShipping` uses a retry-safe rates/pickup/label/cancel/tracking contract and `HttpErpAdapter` really performs order create/status update, products, customers, stock and health over HTTPS; fixture tests prove the contract without pretending to have live credentials.

The catalog keeps `products.category_id` as the backward-compatible primary category and `product_categories` adds further assignments. Brand/manufacturer, GTIN, MPN, integer-cent cost, dimensions, variant media and time-bound availability are normalized fields. The DB price list is resolved by store, currency, customer group, variant, quantity, validity and priority; the selected price list and promotion codes are frozen in the `_pricing` order-item snapshot before the canonical financial snapshot. Promotions are prioritized `exclusive` or `stack` rules and support catalog price, free shipping, buy-X-get-Y and an explicit gift entitlement without a silent stock movement. B2B `companies` and `customer_groups` are store-scoped; invoice purchase is available only to an approved active company within its credit limit and the quote freezes the payment terms and the company ID.

`stock_movements` is an append-only audit of every store-scoped physical and reservation change with the delta and the resulting balance. Products and variants have an explicit `deny`/`backorder`/`preorder` policy; a bundle order line freezes its components, their warehouses and personalization data without changing the financial line. Supplier products enforce minimum/pack size, PO receipt is idempotent and `shipments` + `shipment_items` allow partial warehouse fulfillments. Exact reserve/release/ship guards and shipment-aware cancellation prevent overselling, a double restore and the release of goods that have already shipped.

Return operations are a separate auditable layer on top of the order: several open partial RMAs share a quantity cap, the return label and every receipt have an idempotency identity and each received piece gets an explicit `restock`/`discard`/`repair` outcome. An exchange reserves only the received, not yet credited quantity. `InvoiceService::credit()` does not recompute historical prices; it segments gross/net/VAT from the original fingerprinted financial snapshot in integer cents, creates an immutable credit note + item refund batch, and for an amount the payment worker changes only the payment lifecycle, not the whole order lifecycle.

### V1 — complete commerce engine

**Catalog + storefront:**
- Products, categories, variants, custom product types (catalog.json)
- Multi-store, multi-language, multi-currency (stores.json) — from day 1
- Listing: pagination, sort, faceted filters (FTS-based)
- Search: FTS5/FULLTEXT
- Product detail: SSR, JSON-LD, images (WebP)
- Template engine: blocks, sections, layouts.json
- Flat-file cache per locale (Level 0 + Level 1)

**Cart + checkout:**
- Session + localStorage dual persistence
- Checkout: BBF form, Stripe payments
- Orders: creation, atomic stock, e-mail confirmation
- Order workflow: state machine, audit log

**Admin:**
- Products CRUD (BBF-driven forms per product type)
- Orders: view + status change
- CSV import/export
- Role-based auth, audit log

**Infrastructure:**
- MySQL/MariaDB primary, SQLite for dev
- Job queue (file-based) for cache rebuild, e-mails, image resize
- Stable capability contracts for search, queue, payment, shipping, mail, tax, storage, backup, ERP, PIM and WMS
- Security: CSRF, CSP, rate limiting, idempotency

### V2 — extensions

- Pricing rules (catalog + cart), coupons, B2B tier pricing
- Shipping zones + tax rules from JSON
- ERP adapter (Money, Pohoda)
- 2FA for admin
- Customer accounts
- Webhook endpoint management UI on top of the existing outbox
- DbQueue for multi-node

---


## Architecture

```
┌─────────────────────────────────────────────────────────┐
│  JSON definitions (the heart of the system)             │
│  catalog.json — product types, attributes, filters      │
│  stores.json  — multi-store, languages, currencies      │
│  pricing.json — price rules, discounts, B2B             │
│  shipping.json — methods, zones, rates                  │
│  tax.json     — tax rules per country                   │
└──────────────────┬──────────────────────────────────────┘
                   │ schema:build (regenerates SQL + cache)
                   ▼
┌─────────────────────────────────────────────────────────┐
│  Pre-computed flat cache                                │
│  cache/store/{locale}/cat/{slug}/page-{n}.json          │
│  cache/store/{locale}/product/{slug}.json               │
│  cache/store/{locale}/categories.json                   │
│  cache/filters/{category}.json                          │
│  cache/prices/{customer_group}.json                     │
└──────────────────┬──────────────────────────────────────┘
                   │ served directly (.htaccess / nginx)
                   ▼
┌─────────────────────────────────────────────────────────┐
│  Browser (vanilla JS)                                   │
│  - Catalog, filters, search                             │
│  - Cart (localStorage + session sync)                   │
│  - Checkout → BareBonesForms                            │
└──────────────────┬──────────────────────────────────────┘
                   │ dynamic operations (cart, checkout, search)
                   ▼
┌─────────────────────────────────────────────────────────┐
│  PHP API layer (api.php)                                │
│  - Cache-first: flat file → DB fallback                 │
│  - CSRF, rate limiting, input sanitization              │
└──────────────────┬──────────────────────────────────────┘
                   │
          ┌────────┴────────┐
          ▼                 ▼
┌─────────────────┐  ┌──────────────┐
│  MySQL/MariaDB  │  │  SQLite      │
│  + FULLTEXT     │  │  + FTS5      │
│  (production)   │  │  (dev/small) │
└─────────────────┘  └──────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────┐
│  Job Queue (jobs/ directory)                            │
│  - cache rebuild, e-mails, image resize, import         │
│  - cron or long-running worker.php                      │
│  - no RabbitMQ, just flat files                         │
└─────────────────────────────────────────────────────────┘
```

### Entry points and extension boundaries (G14 baseline)

The public `admin.php`, `index.php`, `api.php` and the CLI `cli/test.php` are only 6/6/6/5-line bootstraps. The HTTP implementations live under the web-denied `src/Entrypoints`; the JSON API additionally separates the 88-line request lifecycle/router from `src/Http/ApiHandlers.php`. `cli/TestSuite.php` holds the existing sequential fixture suite, while `cli/test.php` stays the stable command. Source regressions assemble the whole bundle, so moving code does not weaken CSP, checkout or permission guards.

The reproducible baseline command `git ls-files --cached --others --exclude-standard "*.php"` reports 299 PHP files and 83,668 lines in this state, including migrations and tests; the historical test suite alone has 7,058 lines. The numbers are not a marketing estimate and must be updated on the next split.

`Adapters::registry()` is an additive-only provider boundary. `IntegrationAdapter` keeps legacy ERP compatibility but composes the smaller `ErpAdapter`, `PimAdapter` and `WmsAdapter`; standalone PIM/WMS providers are optional and stay off without configuration. Payment, shipping, mail and tax use the existing narrow interfaces. Public transactional events are named in `DomainEvents` and delivered only through `Outbox`, not through a general plugin lifecycle. Shop-specific code that must not touch core files lives in `extensions/<code>/` (see `docs/EXTENSIONS.md`).

The dev-only `php cli/static-analysis.php` parses PHP sources with the native parser of the active PHP version, forbids `eval`, enforces entrypoint budgets and verifies module/event/adapter contracts. CI runs it on PHP 8.1, 8.2 and 8.3; the production runtime therefore gains no Composer, framework or new service.

---

## JSON-driven catalog schema — the heart of the system

The same principle as BBF: you define JSON → the engine generates everything from it.
In BBF: form JSON → HTML form + validation + submit pipeline.
In Shop: catalog JSON → SQL tables + admin forms + filters + import templates.

### catalog.json — product type definitions

```json
{
    "$schema": "catalog.schema.json",

    "product_types": {
        "simple": {
            "name": {"en": "Simple Product", "sk": "Jednoduchý produkt"},
            "has_variants": false,
            "attributes": {}
        },

        "clothing": {
            "name": {"en": "Clothing", "sk": "Oblečenie"},
            "has_variants": true,
            "variant_dimensions": ["size", "color"],
            "attributes": {
                "size": {
                    "type": "select",
                    "label": {"en": "Size", "sk": "Veľkosť", "de": "Größe", "cs": "Velikost"},
                    "options": ["XS", "S", "M", "L", "XL", "XXL"],
                    "filterable": true,
                    "required": true
                },
                "color": {
                    "type": "select",
                    "label": {"en": "Color", "sk": "Farba", "de": "Farbe", "cs": "Barva"},
                    "options_translatable": true,
                    "options": {
                        "black":  {"en": "Black", "sk": "Čierna", "de": "Schwarz"},
                        "white":  {"en": "White", "sk": "Biela", "de": "Weiß"},
                        "red":    {"en": "Red", "sk": "Červená", "de": "Rot"}
                    },
                    "filterable": true,
                    "required": true
                },
                "material": {
                    "type": "text",
                    "label": {"en": "Material", "sk": "Materiál"},
                    "translatable": true,
                    "filterable": true
                },
                "care_instructions": {
                    "type": "textarea",
                    "label": {"en": "Care Instructions", "sk": "Údržba"},
                    "translatable": true,
                    "filterable": false
                }
            }
        },

        "electronics": {
            "name": {"en": "Electronics", "sk": "Elektronika"},
            "has_variants": true,
            "variant_dimensions": ["storage", "color"],
            "attributes": {
                "brand": {
                    "type": "select",
                    "label": {"en": "Brand", "sk": "Značka"},
                    "options": ["Apple", "Samsung", "Sony", "Xiaomi"],
                    "filterable": true
                },
                "warranty_months": {
                    "type": "number",
                    "label": {"en": "Warranty (months)", "sk": "Záruka (mesiace)"},
                    "filterable": true,
                    "range_filter": true
                },
                "storage": {
                    "type": "select",
                    "label": {"en": "Storage", "sk": "Úložisko"},
                    "options": ["64GB", "128GB", "256GB", "512GB", "1TB"],
                    "filterable": true
                },
                "weight_g": {
                    "type": "number",
                    "label": {"en": "Weight (g)", "sk": "Hmotnosť (g)"},
                    "filterable": true,
                    "range_filter": true
                }
            }
        },

        "downloadable": {
            "name": {"en": "Digital Download", "sk": "Digitálny produkt"},
            "has_variants": false,
            "physical": false,
            "attributes": {
                "file_format": {
                    "type": "select",
                    "label": {"en": "Format", "sk": "Formát"},
                    "options": ["PDF", "EPUB", "MP3", "ZIP"],
                    "filterable": true
                },
                "file_size_mb": {
                    "type": "number",
                    "label": {"en": "File Size (MB)", "sk": "Veľkosť súboru (MB)"}
                },
                "download_url": {
                    "type": "text",
                    "admin_only": true
                }
            }
        }
    },

    "global_attributes": {
        "brand": {
            "type": "select",
            "label": {"en": "Brand", "sk": "Značka"},
            "filterable": true,
            "options_from": "brands"
        }
    }
}
```

### What gets generated from it (schema:build)

When you run `php cli/schema-build.php`:

1. **SQL tables** — created through `Migrator` (forward-only migrations in `migrations/`).
   Schema-build internally calls `Migrator::apply()` — no other ad-hoc ALTER.
2. **Admin forms** — a BBF JSON edit form is generated for every product type
3. **Import templates** — CSV headers for every product type
4. **Filter configuration** — `cache/filters/{category}.json` — which filters to show
5. **Validation rules** — required, type checking, options whitelist

### Migrations runtime

- **Development / fresh DB:** `php cli/schema-build.php` applies all pending migrations
  from the baseline to HEAD. Identical to `php cli/migrate.php`.
- **Existing production (v1.4.0):** run `php cli/migrate.php baseline` once —
  it marks the baseline migration as applied **without running it** (the DB already has all tables).
  Subsequent `migrate` runs apply only new migrations.
- **New migration:** `php cli/migrate.php create add_xyz_column` → creates
  `migrations/<TS>_add_xyz_column.php` with a stub `up(PDO, bool isSqlite)`.
- **Forward-only.** No `down()`. Rollback = a new migration.
- **Safety:** flock on `db/.migrations.lock` (10 s timeout), per-migration
  `BEGIN/ROLLBACK`, hard fail on a checksum mismatch (file edited after apply).

### Backup & Restore (Roadmap 1.6)

`BackupService` (online SQLite backup) + `cli/backup.php` + `cli/restore.php`.

- **Mechanism:** `PRAGMA wal_checkpoint(TRUNCATE)` → `VACUUM INTO 'storage/backups/YYYY/MM/DD/eshop-YYYYMMDD-HHMMSS.db'`. Atomic, defragmented, WAL-safe, no external tools.
- **Layout:** `storage/backups/YYYY/MM/DD/eshop-*.db` + sidecar `*.meta.json` (timestamp, size, sha256, schema_version, label, trigger, compressed). Audit log: `storage/backup.log` (10 MB × 5 rotations).
- **Configuration:** `config/backup.json` — `enabled`, `compression`, `retention {daily, weekly, monthly, minimum_kept}`, `auto_pre_migration`, `auto_gc_on_backup`, `wal_checkpoint`. Storage adapter: `LocalBackupStorage` (default); the `BackupStorage` interface allows S3 / off-site later.
- **Concurrency:** flock on `storage/backups/.backup.lock` (10 s timeout), the same API as Migrator.
- **Idempotency:** sub-second filename collisions → `-1`, `-2`, … suffix counter.
- **Compression:** OFF by default (`VACUUM INTO` already defragments). Opt in with the `--gz` flag or `compression: true` → `*.db.gz` sidecar.
- **GC retention:** grandfather-father-son (7 daily / 4 weekly / 6 monthly); `minimum_kept: 3` protects against GC emptying everything during the initial ramp-up.
- **Migrator pre-hook:** with `auto_pre_migration: true`, `cli/migrate.php` calls `BackupService::create(['label' => 'pre-migration'])` before applying pending migrations. Override: `--skip-backup`. If the hook throws → the migration does not run (table marker NOT inserted).
- **Manual:** `php cli/backup.php [--gc] [--gz] [--label=<text>] [--quiet]`. Cron friendly. The default label is `scheduled` (subject to GC retention); GC never deletes the labels `manual`, `pre-migration` and `pre-restore-snapshot` — use `--label=manual` only for backups that must be kept forever.
- **Permissions:** backups contain personal data — `LocalBackupStorage` writes `.db`/`.meta.json` with `0600` and directories with `0700` (independent of umask; on Windows POSIX bits do not apply and the test skips them with an explicit SKIP). The update SQLite snapshot is also `0600`.
- **GDPR GC:** `php cli/gdpr-gc.php --apply` first initializes `BackupService` and stores a `pre-retention-gc` snapshot; if the snapshot is not possible (backups disabled, MySQL), it refuses with exit 3. After an external backup (e.g. mysqldump) use `--apply --skip-backup`.
- **Restore:** `php cli/restore.php <remote_path> [--force] [--no-snapshot]`.
  - **sha256 verify** against the meta JSON (tamper detection — hard fail).
  - `PRAGMA integrity_check` before the swap — hard fail on a corrupt backup.
  - **Pre-restore snapshot** (`label=pre-restore-snapshot`) — safety net (max 5 retained); exactly the same code path as a manual backup, but it runs outside the restore lock (no re-entrant deadlock).
  - **Atomic swap** — copy → temp → `rename()` over the live file. Windows: `Db::reset()` + 3 × 100 ms retry on a lock; after 3 retries abort with a clear error message.
  - **schema_migrations mismatch** after restore → warning (NOT a hard fail). `count(applied) < count(files)` → "⚠️ Restored DB has older schema. Run: php cli/migrate.php (N pending)". The reverse situation (the DB has migrations that are not in the code) → "⚠️ Restored DB has migrations not in current code."
- **Backup commands typical flow:**
  ```bash
  php cli/backup.php --label=before-major-import   # manual snapshot
  php cli/backup.php --gc                          # cron: daily backup + GC
  php cli/migrate.php                              # auto-backup before apply
  php cli/restore.php 2026/04/25/eshop-20260425-143012.db
  ```
- **Gotcha:** replacing a live SQLite file under an open connection on POSIX silently loses that process's later commits (it writes to an unlinked inode). `BackupService::restore()` and `Updater::rollback()` (SQLite snapshot) therefore run through `SqliteMaintenance::exclusive()`: it creates `<db>.maintenance` (while it exists, `Db::get()` throws `DatabaseMaintenanceException`), closes its own connections to that file and, by switching WAL→DELETE, proves that no other process holds the DB. If the DB is still open after 15 s (worker, web request), restore/rollback is **refused without changing** files or code — **long-running workers must be stopped** and the command repeated. Limitation: a connection opened outside `Db` in DELETE mode cannot be detected.

```
catalog.json
    │
    ├──→ db/generated-schema.sql     (CREATE TABLE, CREATE INDEX)
    ├──→ import/clothing-template.csv
    ├──→ import/electronics-template.csv
    └──→ cache/filter-config.json    (frontend filter definitions)
```

**Change catalog.json → run the build → done** for most changes.
But we admit it: a schema change (a new attribute, a type change) = a migration under another name. The build tool must handle it: a diff between the old and new schema, ALTER/backfill steps, a rollback scenario.

### Comparison with Magento EAV

```
Magento:
  Admin clicks "Add Attribute" → stored in eav_attribute
  → 6 EAV tables are JOINed on EVERY query
  → the flat indexer regenerates the flat table (async, fails)
  → Elasticsearch reindexes (async, fails)
  → Varnish cache invalidation (async, fails)
  = 4 async steps, each can fail

BareBones:
  You edit catalog.json → run schema:build
  → The build diffs the old vs new schema
  → Generates ALTER + backfill SQL (a migration)
  → Runs the migration + cache rebuild
  = Still simpler than Magento, but not "no migrations"
  = Production needs versioning and a rollback plan
```

---

## Multiple languages — native, not a plugin

BBF has 32 languages. So will the shop.

### stores.json — multi-store + multi-language configuration

```json
{
    "default_locale": "sk",
    "default_currency": "eur",

    "stores": {
        "sk": {
            "name": "Slovenský obchod",
            "locale": "sk",
            "currency": "eur",
            "url_prefix": "",
            "languages": ["sk", "cs", "en"]
        },
        "de": {
            "name": "Deutscher Shop",
            "locale": "de",
            "currency": "eur",
            "url_prefix": "/de",
            "languages": ["de", "en"]
        },
        "us": {
            "name": "US Store",
            "locale": "en",
            "currency": "usd",
            "url_prefix": "/en",
            "languages": ["en", "es"]
        }
    },

    "currencies": {
        "eur": {"symbol": "€", "decimals": 2, "position": "after"},
        "usd": {"symbol": "$", "decimals": 2, "position": "before"},
        "czk": {"symbol": "Kč", "decimals": 0, "position": "after"}
    }
}
```

### How translations are stored

**Not EAV. Not an extra table per type.** One column, one JSON:

```sql
-- Products — base language + translations in one row
CREATE TABLE products (
    id          INTEGER PRIMARY KEY,
    sku         TEXT UNIQUE NOT NULL,
    slug        TEXT NOT NULL,                -- base slug
    name        TEXT NOT NULL,                -- default language (e.g. "sk")
    summary     TEXT DEFAULT '',
    description TEXT DEFAULT '',
    -- ... other columns ...

    -- Translations: {"en": {"name": "...", "summary": "..."}, "de": {...}}
    translations TEXT DEFAULT '{}'
);

-- Categories — the same principle
CREATE TABLE categories (
    id   INTEGER PRIMARY KEY,
    slug TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL,                       -- default language
    translations TEXT DEFAULT '{}'            -- {"en": {"name": "..."}, "de": {...}}
);
```

### Usage in code

```php
class I18n {
    private static string $locale = 'sk';

    public static function setLocale(string $locale): void {
        self::$locale = $locale;
    }

    // Translates a product — falls back to the default language
    public static function product(array $row): array {
        if (self::$locale === $GLOBALS['config']['default_locale']) {
            return $row; // base language, no translation
        }

        $tr = json_decode($row['translations'] ?? '{}', true);
        $localeData = $tr[self::$locale] ?? [];

        // Translates only what exists; the rest stays in the base language
        foreach (['name', 'summary', 'description'] as $field) {
            if (!empty($localeData[$field])) {
                $row[$field] = $localeData[$field];
            }
        }

        // Slug per language (for SEO)
        if (!empty($localeData['slug'])) {
            $row['slug'] = $localeData['slug'];
        }

        return $row;
    }

    // Translates an attribute from the catalog.json definition
    public static function attr(string $attrName, string $value): string {
        // Lookup in the catalog.json option translations
        static $catalog = null;
        if (!$catalog) $catalog = json_decode(file_get_contents(__DIR__ . '/../catalog.json'), true);

        // Search product_types → attributes → options
        // Fall back to the value itself
        // ... (cached on the first call)
        return $value; // fallback
    }

    // UI translations (buttons, labels) — the BBF lang system
    public static function t(string $key): string {
        static $lang = null;
        if (!$lang) {
            $file = __DIR__ . '/../lang/' . self::$locale . '.php';
            $lang = file_exists($file) ? require $file : require __DIR__ . '/../lang/en.php';
        }
        return $lang[$key] ?? $key;
    }
}
```

### Pre-computed cache per locale

```
cache/
├── sk/                          # Slovak store
│   ├── categories.json
│   ├── cat/oblecenie/tricka/
│   │   ├── page-1.json          # products with SK names/descriptions
│   │   ├── page-2.json
│   │   └── filters.json         # filters with SK labels
│   └── product/
│       └── cierne-tricko.json   # SK slug, SK content
├── en/
│   ├── categories.json
│   ├── cat/clothing/t-shirts/
│   │   ├── page-1.json          # products with EN names
│   │   └── filters.json         # filters with EN labels
│   └── product/
│       └── black-t-shirt.json   # EN slug, EN content
└── de/
    └── ...
```

**Result:** a complete flat cache exists for every language. No runtime translation, no JOIN. Apache serves the right JSON by URL prefix.

### URL structure

```
example.com/product/cierne-tricko           → SK (default, no prefix)
example.com/en/product/black-t-shirt        → EN
example.com/de/product/schwarzes-t-shirt    → DE

example.com/category/oblecenie/tricka       → SK
example.com/en/category/clothing/t-shirts   → EN
```

### Slug uniqueness per locale

Two products must not have the same slug in the same locale. Because slugs live in the translations JSON,
validation must happen on save.

```sql
-- Helper table for slug uniqueness (generated on build/import)
CREATE TABLE slug_registry (
    slug    TEXT NOT NULL,
    locale  TEXT NOT NULL,
    entity  TEXT NOT NULL,       -- 'product', 'category', 'cms'
    entity_id INTEGER NOT NULL,
    PRIMARY KEY (slug, locale, entity)
);

-- On saving a product/translation: check for a collision
-- INSERT OR FAIL → if slug+locale+entity exists for another entity_id → error
```

```php
// In Import.php / admin save:
public static function validateSlug(string $slug, string $locale, string $entity, int $entityId): bool {
    $stmt = Db::get()->prepare("
        SELECT entity_id FROM slug_registry
        WHERE slug = :slug AND locale = :locale AND entity = :entity AND entity_id != :eid
    ");
    $stmt->execute([':slug' => $slug, ':locale' => $locale, ':entity' => $entity, ':eid' => $entityId]);
    return $stmt->fetch() === false; // true = free, false = collision
}
```

### Known limitations of the JSON translations column

- **Validation:** needs extra parsing on save (not just `json_encode` and done)
- **Admin diffs:** comparing translation versions is not trivial (solution: the audit log records the whole JSON)
- **Selective translations:** "Translate everything into DE" = a query over a JSON column, slower than a normalized table
- **Migration on field renames:** if the translations structure changes, a backfill script is needed

For V1 (1 language) this is not a problem. For V1.1 (multi-language) these edge cases must be solved.

---

## Template engine + layouts — blocks, sections, juggling

### Philosophy

Magento has layout XML → blocks → .phtml templates. It is powerful but unreadable.
Twig/Blade are full engines — an extra dependency and a learning curve.

**The BareBones approach:**
- **JSON defines the layout** — what goes where, in which order, which block
- **HTML templates with `{{}}` syntax** — plain HTML, no PHP, no special syntax
- **A deliberately limited engine** — partials, conditions, loops, blocks. It does not cover Magento edge cases (block references, layout merging). It is enough for 80% of e-shop layouts.
- **Blocks are reusable** — the same block on the homepage, the product page and a CMS page

### Syntax — simple, powerful

```html
<!-- Variables -->
{{name}}
{{product.price_formatted}}

<!-- Escaped (default) vs raw HTML -->
{{description}}              ← escaped (safe)
{{{description_html}}}       ← raw HTML (for rich text)

<!-- Conditions -->
{{#if compare_price}}
    <span class="old-price">{{compare_price_formatted}}</span>
{{/if}}

{{#if stock > 0}}
    <span class="in-stock">{{t.in_stock}}</span>
{{else}}
    <span class="out-of-stock">{{t.out_of_stock}}</span>
{{/if}}

<!-- Loops -->
{{#each variants}}
    <option value="{{sku}}" data-price="{{price_delta}}">{{name}}</option>
{{/each}}

{{#each images}}
    <img src="/uploads/medium/{{this}}" loading="lazy" alt="{{../name}}">
{{/each}}

<!-- Partials (nested templates) -->
{{> product-card}}
{{> breadcrumb}}
{{> pagination}}

<!-- Translations (i18n) -->
{{t.add_to_cart}}
{{t.out_of_stock}}
{{t.showing_results count=total}}

<!-- Blocks (named slots — for layout overrides) -->
{{#block "sidebar"}}
    <!-- default content, can be replaced in a child layout -->
    {{> category-filters}}
{{/block}}
```

### layouts.json — page layout definitions

```json
{
    "layouts": {
        "one-column":  {"slots": ["full"]},
        "two-column":  {"slots": ["main", "sidebar"], "ratios": ["3fr", "1fr"]},
        "two-column-left": {"slots": ["sidebar", "main"], "ratios": ["1fr", "3fr"]},
        "three-column": {"slots": ["left", "main", "right"], "ratios": ["1fr", "3fr", "1fr"]}
    },

    "pages": {
        "homepage": {
            "layout": "one-column",
            "sections": [
                {"block": "hero-banner", "data": "banners"},
                {"block": "featured-categories", "data": "categories.featured"},
                {"block": "product-grid", "data": "products.new", "title": {"sk": "Novinky", "en": "New Arrivals"}},
                {"block": "product-grid", "data": "products.sale", "title": {"sk": "Výpredaj", "en": "Sale"}},
                {"block": "newsletter-signup"}
            ]
        },

        "category": {
            "layout": "two-column",
            "sections": [
                {"block": "breadcrumb", "slot": "full"},
                {"block": "category-header", "slot": "full"},
                {"block": "category-filters", "slot": "sidebar"},
                {"block": "active-filters", "slot": "main"},
                {"block": "sort-bar", "slot": "main"},
                {"block": "product-grid", "slot": "main"},
                {"block": "pagination", "slot": "main"}
            ]
        },

        "product": {
            "layout": "one-column",
            "sections": [
                {"block": "breadcrumb"},
                {"block": "product-detail", "layout": "two-column", "children": [
                    {"block": "product-gallery", "slot": "main"},
                    {"block": "product-info", "slot": "sidebar"}
                ]},
                {"block": "product-tabs", "tabs": [
                    {"id": "desc", "label": {"sk": "Popis", "en": "Description"}, "block": "product-description"},
                    {"id": "specs", "label": {"sk": "Parametre", "en": "Specs"}, "block": "product-attributes"},
                    {"id": "reviews", "label": {"sk": "Hodnotenia", "en": "Reviews"}, "block": "product-reviews"}
                ]},
                {"block": "related-products"},
                {"block": "recently-viewed"}
            ]
        },

        "cart": {
            "layout": "two-column",
            "sections": [
                {"block": "breadcrumb", "slot": "full"},
                {"block": "cart-items", "slot": "main"},
                {"block": "cart-summary", "slot": "sidebar"},
                {"block": "cross-sell", "slot": "full"}
            ]
        },

        "checkout": {
            "layout": "two-column",
            "sections": [
                {"block": "checkout-form", "slot": "main"},
                {"block": "order-summary", "slot": "sidebar"}
            ]
        },

        "cms": {
            "layout": "one-column",
            "sections": [
                {"block": "breadcrumb"},
                {"block": "cms-content"}
            ]
        }
    }
}
```

### Blocks — HTML templates in the blocks/ directory

```
blocks/
├── layout/
│   ├── one-column.html
│   ├── two-column.html
│   └── three-column.html
│
├── global/
│   ├── header.html            # logo, nav, cart badge, lang switcher
│   ├── footer.html            # links, copyright
│   ├── breadcrumb.html
│   ├── pagination.html
│   └── newsletter-signup.html
│
├── product/
│   ├── product-card.html      # card in the grid (listing)
│   ├── product-gallery.html   # images + zoom + thumbnails
│   ├── product-info.html      # name, price, variants, add-to-cart
│   ├── product-description.html
│   ├── product-attributes.html # parameter table
│   ├── product-reviews.html
│   ├── product-tabs.html      # tab wrapper
│   ├── related-products.html
│   └── recently-viewed.html
│
├── category/
│   ├── category-header.html   # name, description, image
│   ├── category-filters.html  # sidebar filters (auto from catalog.json)
│   ├── active-filters.html    # selected filters with "x" to remove
│   ├── sort-bar.html          # sort dropdown + view toggle
│   └── product-grid.html      # grid/list wrapper
│
├── cart/
│   ├── cart-items.html        # item list + qty editing
│   ├── cart-summary.html      # subtotal, shipping, tax, total
│   └── cross-sell.html        # "you might also like"
│
├── checkout/
│   ├── checkout-form.html     # wrapper for the BBF form
│   └── order-summary.html     # order recap
│
├── homepage/
│   ├── hero-banner.html       # slideshow / hero image
│   └── featured-categories.html
│
└── cms/
    └── cms-content.html       # markdown / HTML content
```

### Block example — product-card.html

```html
<article class="product-card" data-id="{{id}}">
    <a href="{{url}}" class="product-card__link">
        <div class="product-card__image">
            <img src="/uploads/medium/{{image}}" alt="{{name}}" loading="lazy">
            {{#if compare_price}}
                <span class="product-card__badge">-{{discount_percent}}%</span>
            {{/if}}
            {{#if stock == 0}}
                <span class="product-card__badge product-card__badge--out">{{t.out_of_stock}}</span>
            {{/if}}
        </div>
        <div class="product-card__body">
            <h3 class="product-card__name">{{name}}</h3>
            {{#if brand}}
                <span class="product-card__brand">{{brand}}</span>
            {{/if}}
            <div class="product-card__price">
                {{#if compare_price}}
                    <span class="price--old">{{compare_price_formatted}}</span>
                {{/if}}
                <span class="price--current">{{price_formatted}}</span>
            </div>
        </div>
    </a>
    {{#if stock > 0}}
        <button class="product-card__cart" data-action="cart-add" data-product="{{id}}">
            {{t.add_to_cart}}
        </button>
    {{/if}}
</article>
```

### Block example — product-gallery.html

```html
<div class="gallery" data-gallery>
    <div class="gallery__main">
        <img src="/uploads/full/{{images.0}}" alt="{{name}}" class="gallery__hero" data-zoom>
    </div>
    {{#if images.length > 1}}
        <div class="gallery__thumbs">
            {{#each images}}
                <button class="gallery__thumb {{#if @first}}is-active{{/if}}"
                        data-image="/uploads/full/{{this}}">
                    <img src="/uploads/thumb/{{this}}" alt="{{../name}} {{@index}}" loading="lazy">
                </button>
            {{/each}}
        </div>
    {{/if}}
</div>
```

### Block example — category-filters.html

```html
<aside class="filters" data-filters>
    {{#if active_filters}}
        <div class="filters__active">
            <h4>{{t.active_filters}}</h4>
            {{#each active_filters}}
                <button class="filter-tag" data-remove="{{attr}}:{{value}}">
                    {{label}}: {{value_label}} ×
                </button>
            {{/each}}
            <button class="filters__clear" data-clear-filters>{{t.clear_all}}</button>
        </div>
    {{/if}}

    <!-- Price range filter -->
    <div class="filters__group">
        <h4>{{t.price}}</h4>
        <div class="price-range" data-filter="price">
            <input type="number" name="price_min" value="{{price_min}}" placeholder="{{t.from}}" min="0">
            <span>—</span>
            <input type="number" name="price_max" value="{{price_max}}" placeholder="{{t.to}}">
        </div>
    </div>

    <!-- Attribute filters — auto-generated from catalog.json -->
    {{#each available_filters}}
        <div class="filters__group">
            <h4>{{label}}</h4>
            {{#if range_filter}}
                <div class="range-filter" data-filter="{{attr_name}}">
                    <input type="number" name="{{attr_name}}_min" placeholder="{{t.from}}">
                    <input type="number" name="{{attr_name}}_max" placeholder="{{t.to}}">
                </div>
            {{else}}
                <ul class="filter-options">
                    {{#each options}}
                        <li>
                            <label class="filter-option">
                                <input type="checkbox" name="{{../attr_name}}" value="{{value}}"
                                       {{#if active}}checked{{/if}}>
                                <span>{{label}}</span>
                                <span class="filter-count">({{count}})</span>
                            </label>
                        </li>
                    {{/each}}
                </ul>
            {{/if}}
        </div>
    {{/each}}
</aside>
```

### Template engine — implementation (~150 lines)

```php
class Template {
    private static string $blocksDir = __DIR__ . '/../blocks';
    private static array $cache = [];

    // ============================================================
    // Render a page from the layouts.json definition
    // ============================================================
    public static function page(string $pageName, array $data = []): string {
        $layouts = self::loadLayouts();
        $page = $layouts['pages'][$pageName] ?? null;
        if (!$page) return '<!-- unknown page: ' . esc($pageName) . ' -->';

        $layout = $layouts['layouts'][$page['layout']] ?? $layouts['layouts']['one-column'];
        $slots = [];

        foreach ($page['sections'] as $section) {
            $slot = $section['slot'] ?? 'full';
            $html = self::renderSection($section, $data);
            $slots[$slot] = ($slots[$slot] ?? '') . $html;
        }

        return self::render('layout/' . $page['layout'], array_merge($data, ['slots' => $slots]));
    }

    // ============================================================
    // Render a block (template) with data
    // ============================================================
    public static function render(string $block, array $data = []): string {
        $tpl = self::loadBlock($block);
        return self::process($tpl, $data);
    }

    // ============================================================
    // Core processing — {{}} syntax
    // ============================================================
    private static function process(string $tpl, array $data): string {
        // 1. Partials: {{> block-name}}
        $tpl = preg_replace_callback('/\{\{>\s*([a-z0-9\/-]+)\s*\}\}/', function($m) use ($data) {
            return self::render($m[1], $data);
        }, $tpl);

        // 2. Blocks: {{#block "name"}}default{{/block}}
        $tpl = preg_replace_callback(
            '/\{\{#block\s+"([^"]+)"\}\}(.*?)\{\{\/block\}\}/s',
            function($m) use ($data) {
                $name = $m[1];
                return $data['slots'][$name] ?? self::process($m[2], $data);
            }, $tpl
        );

        // 3. Conditions: {{#if expr}}...{{else}}...{{/if}}
        $tpl = preg_replace_callback(
            '/\{\{#if\s+(.+?)\}\}(.*?)(?:\{\{else\}\}(.*?))?\{\{\/if\}\}/s',
            function($m) use ($data) {
                $condition = self::evaluate($m[1], $data);
                return self::process($condition ? $m[2] : ($m[3] ?? ''), $data);
            }, $tpl
        );

        // 4. Loops: {{#each items}}...{{/each}}
        $tpl = preg_replace_callback(
            '/\{\{#each\s+(\w[\w.]*)\}\}(.*?)\{\{\/each\}\}/s',
            function($m) use ($data) {
                $items = self::resolve($m[1], $data);
                if (!is_array($items)) return '';
                $out = '';
                foreach (array_values($items) as $i => $item) {
                    $ctx = is_array($item) ? $item : ['this' => $item];
                    $ctx['@index'] = $i;
                    $ctx['@first'] = ($i === 0);
                    $ctx['@last'] = ($i === count($items) - 1);
                    $ctx['..'] = $data; // parent context
                    $out .= self::process($m[2], $ctx);
                }
                return $out;
            }, $tpl
        );

        // 5. Raw output: {{{variable}}} (no escaping)
        $tpl = preg_replace_callback('/\{\{\{(\w[\w.]*)\}\}\}/', function($m) use ($data) {
            return (string) self::resolve($m[1], $data);
        }, $tpl);

        // 6. Escaped output: {{variable}}
        $tpl = preg_replace_callback('/\{\{(\w[\w.@]*)\}\}/', function($m) use ($data) {
            $val = self::resolve($m[1], $data);
            return esc((string) $val);
        }, $tpl);

        return $tpl;
    }

    // Resolve dot-notation: "product.price" → $data['product']['price']
    private static function resolve(string $path, array $data) {
        $keys = explode('.', $path);
        $val = $data;
        foreach ($keys as $key) {
            if (is_array($val) && array_key_exists($key, $val)) {
                $val = $val[$key];
            } else {
                return null;
            }
        }
        return $val;
    }

    // Evaluate simple conditions: "stock > 0", "compare_price", "images.length > 1"
    private static function evaluate(string $expr, array $data): bool {
        $expr = trim($expr);

        // Comparison: "field > value", "field == value"
        if (preg_match('/^(\w[\w.]*)\s*(==|!=|>|<|>=|<=)\s*(.+)$/', $expr, $m)) {
            $left = self::resolve($m[1], $data);
            $op = $m[2];
            $right = trim($m[3], '"\'');
            if (is_numeric($right)) $right = (float)$right;
            return match($op) {
                '==' => $left == $right,
                '!=' => $left != $right,
                '>'  => $left > $right,
                '<'  => $left < $right,
                '>=' => $left >= $right,
                '<=' => $left <= $right,
            };
        }

        // Truthy check: "compare_price", "variants"
        $val = self::resolve($expr, $data);
        return !empty($val);
    }

    private static function loadBlock(string $name): string {
        if (!isset(self::$cache[$name])) {
            $file = self::$blocksDir . '/' . $name . '.html';
            self::$cache[$name] = file_exists($file) ? file_get_contents($file) : "<!-- missing: {$name} -->";
        }
        return self::$cache[$name];
    }

    private static function loadLayouts(): array {
        static $layouts = null;
        if (!$layouts) $layouts = json_decode(file_get_contents(__DIR__ . '/../layouts.json'), true);
        return $layouts;
    }

    private static function renderSection(array $section, array $data): string {
        $block = $section['block'];
        $sectionData = $data;

        // If the section has a data source, load it
        if (!empty($section['data'])) {
            $sectionData['section_data'] = self::resolve($section['data'], $data);
        }
        // Title per locale
        if (!empty($section['title'])) {
            $sectionData['section_title'] = $section['title'][I18n::locale()] ?? reset($section['title']);
        }
        // Tabs
        if (!empty($section['tabs'])) {
            $sectionData['tabs'] = $section['tabs'];
        }
        // Nested children
        if (!empty($section['children'])) {
            $childSlots = [];
            foreach ($section['children'] as $child) {
                $slot = $child['slot'] ?? 'full';
                $childSlots[$slot] = ($childSlots[$slot] ?? '') . self::renderSection($child, $data);
            }
            $sectionData['slots'] = $childSlots;
        }

        return self::render($block, $sectionData);
    }
}
```

### How it is used

```php
// product.php — the whole rendering code
$product = Products::bySlug($slug);
$product = I18n::product($product);
$product = Pricing::apply($product);

echo Template::page('product', [
    'product'    => $product,
    'breadcrumb' => Categories::breadcrumb($product['category_id']),
    'related'    => Products::related($product['id'], 4),
    'reviews'    => Reviews::forProduct($product['id']),
    't'          => I18n::all(),    // all UI translations
]);
```

```php
// index.php — homepage
echo Template::page('homepage', [
    'banners'    => ContentService::banners('homepage'),
    'categories' => ['featured' => Categories::featured()],
    'products'   => [
        'new'  => Products::latest(8),
        'sale' => Products::onSale(8),
    ],
    't' => I18n::all(),
]);
```

### Production theme packages

A store selects a package with the `theme` key in `stores.json`. The package `themes/{code}/theme.json`
declares a semver version, CSS/JS assets and byte budgets; `blocks/` contains only
override blocks and `assets/` is the only public content of the package. `Template::activate()` is
called together with `StoreContext`, validates the package name and asset paths, invalidates the cache
when the store/theme switches and versions asset URLs. When rendering, the store override is looked up first,
then `themes/{theme}/blocks`, the legacy theme override and finally the base block.

`Template::document()` provides a single SSR shell with header/main/footer landmarks,
a skip link, DB navigation, the active theme assets, the SEO head and a consent-aware hook.
`ContentService` binds menus, timed banners, landing pages and redirects to the active
store; admin mutations are CSRF-protected, authorized, sanitized and audited.
`MarketingFeed` uses the same store/catalog scope and the integer-cent price for Google
Merchant XML and Meta CSV. CI runs parity and `cli/quality-gates.php`, which checks
the automatable WCAG invariants and package performance budgets.

### Juggling — why it is flexible

**1. Change the order of sections on the product page:**
```json
// layouts.json — just swap the lines
"sections": [
    {"block": "breadcrumb"},
    {"block": "product-tabs", ...},        ← moved up
    {"block": "product-detail", ...},
    {"block": "related-products"}
]
```

**2. Add a new block:**
```
Create blocks/product/size-guide.html → add it to layouts.json → done.
```

**3. A different layout for another category:**
```json
"pages": {
    "category": { "layout": "two-column", ... },
    "category:electronics": { "layout": "two-column-left", ... },
    "category:sale": { "layout": "one-column", ... }
}
```

**4. A completely different homepage for another store:**
```json
"pages": {
    "homepage":    { ... },              // default
    "homepage:de": { ... },              // German store — other sections, another layout
    "homepage:us": { ... }               // US store
}
```

**5. CMS pages — the admin creates them in JSON:**
```json
{
    "slug": "o-nas",
    "title": {"sk": "O nás", "en": "About Us"},
    "layout": "one-column",
    "sections": [
        {"block": "cms-content", "content_file": "pages/o-nas.html"},
        {"block": "newsletter-signup"}
    ]
}
```

### Comparison with Magento layout XML

```xml
<!-- Magento: product page layout override (unreadable) -->
<page xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
      xsi:noNamespaceSchemaLocation="urn:magento:framework:View/Layout/etc/page_configuration.xsd">
    <body>
        <referenceContainer name="content">
            <block class="Magento\Catalog\Block\Product\View"
                   name="product.info.main"
                   template="Magento_Catalog::product/view/main.phtml">
                <container name="product.info.price" as="product_price">
                    <block class="Magento\Catalog\Pricing\Render" name="product.price.final">
                        <!-- ... 50 more lines of XML ... -->
                    </block>
                </container>
            </block>
        </referenceContainer>
    </body>
</page>
```

```json
// BareBones: the same thing
{"block": "product-detail", "children": [
    {"block": "product-gallery", "slot": "main"},
    {"block": "product-info", "slot": "sidebar"}
]}
```

**Covers 80% of use cases. It has no Magento edge-case handling (block references, move operations, layout merging). Deliberately limited — simplicity > completeness.**

### Inheritance and overrides

```
blocks/
├── product/
│   └── product-card.html              ← base template
│
├── themes/
│   └── dark/
│       └── product/
│           └── product-card.html      ← override for the dark theme
│
└── stores/
    └── de/
        └── product/
            └── product-card.html      ← override for the German store
```

The template engine looks in this order:
1. `blocks/stores/{store}/` — store-specific override
2. `blocks/themes/{theme}/` — theme override
3. `blocks/` — base

The first one found is used. The same principle as the Magento fallback, but without an XML config.

### Principle: a configurable queue (file / database / Redis)

```
jobs/
├── pending/                     # new jobs
│   ├── 1710234567_cache_rebuild_cat_oblecenie.json
│   ├── 1710234568_email_order_confirm_ORD-123.json
│   └── 1710234569_image_resize_product_456.json
├── processing/                  # currently being processed
├── completed/                   # done (auto-cleanup after 24h)
└── failed/                      # failed (for debugging)
```

### Job format

```json
{
    "type": "cache_rebuild",
    "priority": 1,
    "payload": {
        "scope": "category",
        "category_slug": "oblecenie",
        "locales": ["sk", "en", "de"]
    },
    "created_at": "2026-03-12T14:30:00Z",
    "attempts": 0,
    "max_attempts": 3
}
```

### Worker — cron or a long-running process

```php
// cli/worker.php — run from cron (every minute) or as a daemon

// Exclusive lock — always only 1 worker (protection against race conditions)
$lock = fopen(__DIR__ . '/../jobs/worker.lock', 'w+');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Worker is already running.\n");
}

class JobWorker {
    private static array $handlers = [
        'cache_rebuild'  => 'JobHandlers::cacheRebuild',
        'email_send'     => 'JobHandlers::emailSend',
        'image_resize'   => 'JobHandlers::imageResize',
        'import_process' => 'JobHandlers::importProcess',
        'search_reindex' => 'JobHandlers::searchReindex',
        'stock_alert'    => 'JobHandlers::stockAlert',
        'order_process'  => 'JobHandlers::orderProcess',
        'sitemap_build'  => 'JobHandlers::sitemapBuild',
    ];

    public static function run(): void {
        $pending = glob(__DIR__ . '/../jobs/pending/*.json');
        // Sort by priority + timestamp
        usort($pending, fn($a, $b) => self::jobPriority($a) <=> self::jobPriority($b));

        foreach ($pending as $file) {
            $job = json_decode(file_get_contents($file), true);
            $handler = self::$handlers[$job['type']] ?? null;
            if (!$handler) { self::fail($file, $job, 'Unknown job type'); continue; }

            // Move to processing (atomic rename)
            $procFile = str_replace('/pending/', '/processing/', $file);
            rename($file, $procFile);

            try {
                call_user_func($handler, $job['payload']);
                // Success → move to completed
                rename($procFile, str_replace('/processing/', '/completed/', $procFile));
            } catch (\Throwable $e) {
                $job['attempts']++;
                $job['last_error'] = $e->getMessage();
                if ($job['attempts'] >= $job['max_attempts']) {
                    // Final failure
                    file_put_contents(
                        str_replace('/processing/', '/failed/', $procFile),
                        json_encode($job, JSON_PRETTY_PRINT)
                    );
                    unlink($procFile);
                } else {
                    // Retry — return to pending
                    file_put_contents(
                        str_replace('/processing/', '/pending/', $procFile),
                        json_encode($job, JSON_PRETTY_PRINT)
                    );
                    unlink($procFile);
                }
            }
        }
    }
}
```

### When jobs are created

```
Admin saves a product    → job: cache_rebuild (category + product)
                         → job: search_reindex (product)
                         → job: image_resize (if a new image)

Admin saves a category   → job: cache_rebuild (category tree + listings)

An order is created      → job: email_send (confirmation)
                         → job: email_send (admin notify)
                         → job: stock_alert (if low stock)

Bulk import              → job: import_process (the whole CSV)
                         → after completion: cache_rebuild (full)

Cron (daily)             → job: sitemap_build
                         → job: cart_abandonment (carts > 24h with an e-mail → reminder)
                         → job: cleanup (completed jobs, expired sessions)
```

### Comparison with Magento

```
Magento:
  RabbitMQ/MySQL message queue → consumers (PHP processes)
  → indexer:reindex (5-15 different indexers)
  → cache:flush (Redis, Varnish, built-in)
  → Elasticsearch reindex
  = Complex infrastructure, dozens of moving parts

BareBones:
  file: JSON in jobs/pending/ → worker.php (1 process, no dependencies)
  database: queue_jobs → atomic claim + lease (several workers)
  Redis: sorted sets + Lua claim/complete/fail (several workers)
  → the same JobQueue contract and handler functions
```

The file backend uses an atomic `rename()` and a process lock. The database and Redis backends
store the store/channel/correlation context, retry state and dead jobs; a lease token prevents
two workers from completing the same claim. The backend is chosen with `queue.backend` without changing
producers or handlers. Switching the backend requires first draining or
reconciling the old queue — jobs are not copied between backends automatically.

A worker that dies during a job (fatal error, OOM, kill) does not call `fail()`. After the
lease expires (database/Redis `lease_seconds`, file backend 300 s from the claim in `processing/`)
such a run counts as a failed attempt: the job goes back to retry, and after `max_attempts` (3) to
`failed` (dead letter) with the error "lease expired". A job that keeps crashing the worker
therefore does not repeat forever and the file backend does not get stuck in `processing/`.

---

## Price rules, discounts, B2B — pricing.json

```json
{
    "customer_groups": {
        "guest":      {"name": "Guest", "discount": 0},
        "registered": {"name": "Registered", "discount": 0},
        "wholesale":  {"name": "Wholesale", "discount": 15},
        "vip":        {"name": "VIP", "discount": 10}
    },

    "catalog_rules": [
        {
            "name": "Summer Sale",
            "active": true,
            "from": "2026-06-01",
            "to": "2026-08-31",
            "conditions": {"category": "oblecenie"},
            "discount_type": "percent",
            "discount_value": 20,
            "priority": 1
        },
        {
            "name": "Bulk Electronics",
            "active": true,
            "conditions": {"category": "elektronika", "min_qty": 5},
            "discount_type": "percent",
            "discount_value": 10,
            "priority": 2
        }
    ],

    "cart_rules": [
        {
            "name": "WELCOME10",
            "type": "coupon",
            "code": "WELCOME10",
            "discount_type": "percent",
            "discount_value": 10,
            "min_subtotal": 5000,
            "max_uses": 1000,
            "per_customer": 1,
            "active": true
        },
        {
            "name": "Free Shipping over €50",
            "type": "automatic",
            "conditions": {"min_subtotal": 5000},
            "action": "free_shipping",
            "active": true
        }
    ],

    "tier_pricing": {
        "enabled": true,
        "rules": [
            {"min_qty": 10, "discount_percent": 5},
            {"min_qty": 50, "discount_percent": 12},
            {"min_qty": 100, "discount_percent": 20}
        ]
    }
}
```

### Pre-computed prices per customer group

```
cache/
├── prices/
│   ├── guest.json           # {"TSHIRT-BLK-L": 1990, ...}
│   ├── wholesale.json       # {"TSHIRT-BLK-L": 1692, ...}  (15% off)
│   └── vip.json             # {"TSHIRT-BLK-L": 1791, ...}  (10% off)
```

### Rebuild vs runtime — clear boundaries

| Rule type | When it is computed | Why |
|---|---|---|
| Customer group discount (%) | **Rebuild** | Static, does not change per request |
| Catalog rules (seasonal sales) | **Rebuild** | Date range, deterministic |
| Tier pricing (qty-based) | **Runtime** (checkout) | Depends on the qty in the cart |
| Coupon/discount code | **Runtime** (checkout) | Depends on user input |
| Shipping cost | **Runtime** (checkout) | Depends on the address + weight |
| Tax | **Runtime** (checkout) | Depends on the customer's country |

**Rule:** if the result depends only on the product and the customer group → rebuild.
If it depends on the cart contents or user input → runtime.
This prevents a cache explosion as the rules grow.

---

## Shipping & Tax — shipping.json, tax.json

### shipping.json

```json
{
    "methods": {
        "pickup": {
            "name": {"en": "In-store pickup", "sk": "Osobný odber"},
            "price": 0,
            "available_zones": ["local"]
        },
        "standard": {
            "name": {"en": "Standard Delivery", "sk": "Štandardné doručenie"},
            "delivery_days": "5-7",
            "rates": {
                "domestic":      {"base": 399, "per_kg": 0},
                "eu":            {"base": 799, "per_kg": 100},
                "international": {"base": 1599, "per_kg": 200}
            },
            "free_above": 5000
        },
        "express": {
            "name": {"en": "Express Delivery", "sk": "Expresné doručenie"},
            "delivery_days": "1-2",
            "rates": {
                "domestic": {"base": 799, "per_kg": 0},
                "eu":       {"base": 1499, "per_kg": 150}
            }
        }
    },

    "zones": {
        "local":   {"countries": ["SK"]},
        "domestic": {"countries": ["SK"]},
        "eu":       {"countries": ["CZ","DE","AT","PL","HU","IT","FR","ES","NL","BE"]},
        "international": {"countries": ["*"]}
    }
}
```

### tax.json

```json
{
    "default_rate": 20,
    "prices_include_tax": true,

    "rates": {
        "SK": {"standard": 20, "reduced": 10, "zero": 0},
        "CZ": {"standard": 21, "reduced": 15},
        "DE": {"standard": 19, "reduced": 7},
        "AT": {"standard": 20, "reduced": 10},
        "US": {"standard": 0, "note": "varies by state — set per-state if needed"}
    },

    "product_tax_class": {
        "default":     "standard",
        "food":        "reduced",
        "books":       "reduced",
        "digital":     "standard",
        "baby":        "reduced"
    }
}
```

---

## Feature matrix — BareBones vs Magento

### V1 (core)

| Feature | Magento | BareBones V1 | How | Limitations |
|---|---|---|---|---|
| Multi-store | Yes (complex) | Yes | stores.json, URL prefix | Max ~5 stores tested |
| Multi-language | Yes (store views) | Yes | translations JSON + BBF lang | — |
| Multi-currency | Yes | Yes | stores.json, pre-computed prices | Manual exchange rates |
| Product types | 6 built-in | Custom | catalog.json | You define them yourself |
| Variants | Yes | Yes | variant_dimensions | — |
| Bundle | Yes | Yes | bundle_components, Inventory | No Magento grouped (choice of sub-products) |
| Digital/downloadable | Yes | Yes | physical: false | No DRM |
| Faceted filtering | Elasticsearch | DB-based | product_attrs + filter_tags | No synonyms, typo tolerance |
| Full-text search | Elasticsearch | FTS5/FULLTEXT | Built into the DB | No merchandising rules |
| Catalog price rules | Yes | Yes | pricing.json | Simpler than Magento |
| Coupons | Yes | Yes | pricing.json cart_rules | Basic (%, absolute) |
| B2B tier pricing | Yes | Yes | pricing.json | — |
| Tax per country | Yes | Yes | tax.json | No per-state (US) in V1 |
| Shipping | Yes | Yes | shipping.json + carrier adapters (HttpShipping, Packeta, GLS) | Live rates only through an adapter with getRates |
| Order management | Yes (complex) | Yes | Status workflow + audit log, Fulfillment (partial shipments), Rma (returns/refunds) | — |
| Customer accounts | Yes | Yes | account.php, Customers | — |
| Reviews / Wishlist | Yes | Yes | Reviews, Wishlist | Review moderation in the admin |
| 2FA for admin | Yes | Yes | AdminIdentity + TOTP | — |
| Webhooks (outbound) | Yes | Yes | Outbox + worker | — |
| Multi-warehouse | Yes (MSI) | Yes | Warehouse, inventory ledger | — |
| Email templates | Yes | Yes | BBF template system + admin editor (per store and locale overrides) | — |
| SEO (SSR, JSON-LD) | Plugin | Built-in | product.php SSR | — |
| Import/Export CSV | Yes | Yes | Auto-generated | — |
| Admin panel | Yes (huge) | Minimalist | BBF-driven forms | Session-based auth, role-based |
| Async operations | RabbitMQ | File/DB queue | Non-critical only | Single-node (file), multi-node (DB adapter) |

### Deliberately out of scope for V1

| Feature | Why | V2 plan |
|---|---|---|
| A/B testing, personalization | Enterprise feature | Out of scope |
| Magento grouped products | Bundle covers the common cases | On demand |
| Specific UPS/DHL integrations | The generic HttpShipping contract is enough | Adapter |

Originally this list also included customer accounts, reviews, wishlist, live carrier rates, partial shipments/refunds, 2FA for admin, outbound webhooks and multi-warehouse — all of them are implemented today (see the V1 table).

---

## Directory structure

```
barebone-shop/
├── index.php                     # Home page / catalog
├── product.php                   # Product detail (SSR for SEO)
├── api.php                       # API router
├── config.php                    # Configuration (1 file, all credentials)
├── shop.js                       # Frontend logic
├── shop.css                      # Styles
│
├── catalog.json                  # DEFINITION: product types, attributes, filters
├── stores.json                   # DEFINITION: stores, languages, currencies
├── layouts.json                  # DEFINITION: page layouts, sections, blocks
├── pricing.json                  # DEFINITION: price rules, discounts, B2B
├── shipping.json                 # DEFINITION: methods, zones, rates
├── tax.json                      # DEFINITION: taxes per country
│
├── src/
│   ├── Db.php                    # PDO abstraction (SQLite + MySQL/MariaDB)
│   ├── Template.php              # Template engine (~150 lines)
│   ├── Products.php              # Product queries
│   ├── Categories.php            # Category tree structure
│   ├── Search.php                # Full-text search (FTS5 / FULLTEXT)
│   ├── Cart.php                  # Cart (session-based)
│   ├── Orders.php                # Orders + status workflow
│   ├── OrderWorkflow.php         # Order state machine
│   ├── Pricing.php               # Price rules, discounts, coupons
│   ├── Shipping.php              # Shipping calculation
│   ├── Tax.php                   # Tax calculation
│   ├── I18n.php                  # Multi-language, locale, translations
│   ├── Cache.php                 # Flat-file cache manager
│   ├── Import.php                # CSV/JSON import
│   ├── Sync.php                  # ERP/integration sync orchestration
│   ├── AuditLog.php              # Who changed what and when
│   ├── Idempotency.php           # Webhook/import dedup
│   ├── Security.php              # CSRF, rate limiting, input sanitization
│   └── JobQueue.php              # Async job queue
│
├── adapters/                     # Swappable interfaces (5 extension points)
│   ├── SearchAdapter.php         #   interface + FtsSearch (default)
│   ├── QueueAdapter.php          #   interface + FileQueue (default)
│   ├── PaymentAdapter.php        #   interface + StripePayment (default)
│   ├── StorageAdapter.php        #   interface + LocalStorage (default)
│   └── IntegrationAdapter.php    #   interface + null (standalone default)
│
├── adapters/contrib/             # Community / custom adapters
│   ├── MeilisearchAdapter.php    #   (optional search)
│   ├── MoneyErpAdapter.php       #   Money S3/S4/S5 ERP
│   ├── PohodaAdapter.php         #   Pohoda accounting
│   ├── S3StorageAdapter.php      #   AWS S3 / Cloudflare R2
│   └── DbQueueAdapter.php        #   DB-backed queue for multi-node
│
├── extensions/                   # Shop-specific extensions (never packaged or overwritten by updates)
│
├── blocks/                       # HTML templates ({{}} syntax)
│   ├── layout/                   #   page layouts (one-column, two-column...)
│   ├── global/                   #   header, footer, breadcrumb, pagination
│   ├── product/                  #   product card, gallery, info, tabs...
│   ├── category/                 #   filters, sort, grid
│   ├── cart/                     #   cart items, summary
│   ├── checkout/                 #   checkout form, order summary
│   ├── homepage/                 #   hero, featured
│   ├── cms/                      #   static content
│   ├── themes/                   #   theme overrides (optional)
│   └── stores/                   #   store-specific overrides (optional)
│
├── cli/
│   ├── schema-build.php          # init wrapper: runs migrations + (re)gens forms/templates
│   ├── migrate.php               # apply / status / create / baseline (forward-only)
│   ├── cache-rebuild.php         # Regenerate the flat cache (all locales)
│   ├── worker.php                # Job queue worker (cron)
│   ├── import.php                # CLI import CSV/JSON
│   └── seed.php                  # Test seed (generator of 1M products)
│
├── migrations/                   # Numbered <YYYYMMDDHHMMSS>_<name>.php files
│   └── 20260424000000_baseline.php   # v1.4.0 schema baseline
│
├── db/
│   ├── shop.sqlite               # SQLite database (if used)
│   ├── schema-sqlite.sql         # Generated from catalog.json
│   └── schema-mysql.sql          # Generated from catalog.json
│
├── cache/                        # Generated per-locale flat cache
│   ├── sk/                       #   Slovak store
│   │   ├── categories.json
│   │   ├── cat/{slug}/page-{n}.json
│   │   └── product/{slug}.json
│   ├── en/                       #   English store
│   ├── de/                       #   German store
│   ├── filters/                  #   filter configuration per category
│   └── prices/                   #   pre-computed prices per customer group
│
├── jobs/                         # Async job queue (flat files)
│   ├── pending/
│   ├── processing/
│   ├── completed/
│   └── failed/
│
├── uploads/                      # Product images
│   ├── thumb/                    #   150x150
│   ├── medium/                   #   600x600
│   └── full/                     #   original
│
├── admin/
│   ├── index.php                 # Admin panel
│   └── admin.js                  # Admin frontend
│
├── lang/                         # UI translations (BBF system)
│   ├── sk.php
│   ├── en.php
│   ├── de.php
│   ├── cs.php
│   └── ... (32 languages)
│
├── forms/
│   └── checkout.json             # BBF checkout form
│
├── templates/
│   ├── confirm-order.html        # E-mail confirmation (BBF template)
│   └── notify-order.html         # Admin notification
│
└── import/                       # Auto-generated import templates per type
    ├── clothing-template.csv
    └── electronics-template.csv
```

---

## Database abstraction — SQLite + MySQL/MariaDB

### config.php — one switch, no further configuration

```php
return [
    // 'sqlite' or 'mysql'
    'db_driver' => 'sqlite',

    // SQLite — this is all you need:
    'db_sqlite_path' => __DIR__ . '/db/shop.sqlite',

    // MySQL/MariaDB — if you have an existing server:
    'db_host'     => '127.0.0.1',
    'db_port'     => 3306,
    'db_name'     => 'barebone_shop',
    'db_user'     => 'shop',
    'db_password' => '',             // or from env: getenv('DB_PASSWORD')
    'db_charset'  => 'utf8mb4',

    // Cache
    'cache_dir'   => __DIR__ . '/cache',
    'cache_ttl'   => 3600,

    // Shop
    'currency'       => 'eur',
    'per_page'       => 24,
    'admin_user'     => 'admin',
    'admin_password' => '', // password_hash() output — NEVER plaintext
];
```

### Db.php — unified PDO wrapper

```php
class Db {
    private static ?PDO $pdo = null;
    private static string $driver = '';

    public static function get(): PDO {
        if (self::$pdo) return self::$pdo;
        $cfg = require __DIR__ . '/../config.php';
        self::$driver = $cfg['db_driver'];

        if (self::$driver === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['db_host'], $cfg['db_port'], $cfg['db_name'], $cfg['db_charset']);
            self::$pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,  // real prepared statements
            ]);
            self::$pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE'");
        } else {
            self::$pdo = new PDO('sqlite:' . $cfg['db_sqlite_path'], null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // SQLite performance PRAGMAs
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA synchronous = NORMAL');
            self::$pdo->exec('PRAGMA cache_size = -64000');
            self::$pdo->exec('PRAGMA temp_store = MEMORY');
            self::$pdo->exec('PRAGMA mmap_size = 268435456');
            self::$pdo->exec('PRAGMA foreign_keys = ON');
        }
        return self::$pdo;
    }

    public static function driver(): string { return self::$driver; }
    public static function isSqlite(): bool  { return self::$driver === 'sqlite'; }
    public static function isMysql(): bool   { return self::$driver === 'mysql'; }
}
```

### Where SQLite and MySQL differ

The SQL is 95% identical. The differences are isolated in a few places:

| Feature | SQLite | MySQL/MariaDB |
|---|---|---|
| Auto-increment PK | `INTEGER PRIMARY KEY` | `INT AUTO_INCREMENT PRIMARY KEY` |
| Full-text search | FTS5 virtual table | `FULLTEXT INDEX` + `MATCH...AGAINST` |
| JSON functions | `json_extract()` | `JSON_EXTRACT()` (the same!) |
| Current timestamp | `datetime('now')` | `NOW()` |
| UPSERT | `ON CONFLICT(sku) DO UPDATE` | `ON DUPLICATE KEY UPDATE` |
| Boolean | INTEGER 0/1 | TINYINT 0/1 |
| Concurrent writes | WAL mode (1 writer) | InnoDB (full MVCC) |

### Search.php — an abstraction for FTS

```php
class Search {
    public static function query(string $q, int $page = 1, int $perPage = 24): array {
        $db = Db::get();
        $offset = ($page - 1) * $perPage;

        if (Db::isSqlite()) {
            // FTS5 — BM25 ranking, diacritics, prefix match
            $ftsQuery = self::sanitizeFtsQuery($q);
            $stmt = $db->prepare("
                SELECT p.*, rank
                FROM products_fts fts
                JOIN products p ON p.id = fts.rowid
                WHERE products_fts MATCH :q AND p.status = 'active'
                ORDER BY rank
                LIMIT :limit OFFSET :offset
            ");
            $stmt->execute([':q' => $ftsQuery, ':limit' => $perPage, ':offset' => $offset]);
        } else {
            // MySQL FULLTEXT — natural language mode
            $stmt = $db->prepare("
                SELECT p.*, MATCH(p.name, p.summary, p.tags) AGAINST(:q) AS relevance
                FROM products p
                WHERE MATCH(p.name, p.summary, p.tags) AGAINST(:q2 IN NATURAL LANGUAGE MODE)
                  AND p.status = 'active'
                ORDER BY relevance DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->execute([':q' => $q, ':q2' => $q, ':limit' => $perPage, ':offset' => $offset]);
        }

        return $stmt->fetchAll();
    }

    // FTS query sanitization — protection against injection into the FTS5 syntax
    private static function sanitizeFtsQuery(string $q): string {
        // Remove FTS5 operators (AND, OR, NOT, NEAR, special characters)
        $q = preg_replace('/["\*\(\)\{\}\[\]:^~]/', ' ', $q);
        $q = preg_replace('/\b(AND|OR|NOT|NEAR)\b/i', '', $q);
        $q = trim(preg_replace('/\s+/', ' ', $q));
        if ($q === '') return '""';
        // Prefix match for the last word (autocomplete effect)
        $words = explode(' ', $q);
        $last = array_pop($words);
        $words[] = '"' . $last . '" *';
        return implode(' ', $words);
    }
}
```

### UPSERT abstraction

```php
// In Import.php — works on both databases
public static function upsertProduct(array $data): void {
    $db = Db::get();

    if (Db::isSqlite()) {
        $stmt = $db->prepare("
            INSERT INTO products (sku, slug, name, category_id, price, stock, summary, description, images, attrs, tags)
            VALUES (:sku, :slug, :name, :cat, :price, :stock, :summary, :desc, :images, :attrs, :tags)
            ON CONFLICT(sku) DO UPDATE SET
                name = excluded.name,
                price = excluded.price,
                stock = excluded.stock,
                summary = excluded.summary,
                description = excluded.description,
                images = excluded.images,
                attrs = excluded.attrs,
                tags = excluded.tags,
                updated_at = datetime('now')
        ");
    } else {
        $stmt = $db->prepare("
            INSERT INTO products (sku, slug, name, category_id, price, stock, summary, description, images, attrs, tags)
            VALUES (:sku, :slug, :name, :cat, :price, :stock, :summary, :desc, :images, :attrs, :tags)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                price = VALUES(price),
                stock = VALUES(stock),
                summary = VALUES(summary),
                description = VALUES(description),
                images = VALUES(images),
                attrs = VALUES(attrs),
                tags = VALUES(tags),
                updated_at = NOW()
        ");
    }

    $stmt->execute([
        ':sku'     => $data['sku'],
        ':slug'    => $data['slug'],
        ':name'    => $data['name'],
        ':cat'     => $data['category_id'],
        ':price'   => $data['price'],
        ':stock'   => $data['stock'],
        ':summary' => $data['summary'] ?? '',
        ':desc'    => $data['description'] ?? '',
        ':images'  => json_encode($data['images'] ?? []),
        ':attrs'   => json_encode($data['attrs'] ?? []),
        ':tags'    => json_encode($data['tags'] ?? []),
    ]);
}
```

### When to use which

| Scenario | Recommendation |
|---|---|
| Small shop, shared hosting, <100k products | **SQLite** — zero configuration, 1 file, backup = a copy |
| An existing server with MariaDB/MySQL | **MySQL** — use what is already running |
| >100k products, several concurrent writers | **MySQL** — better MVCC, connection pooling |
| VPS/dedicated, you want maximum simplicity | **SQLite** — a simple read-heavy profile; 1M performance is not qualified |
| Several PHP processes write at once (high-traffic admin) | **MySQL** — SQLite WAL handles 1 writer |

---

## Database schema

The schema is almost identical. Below is the **shared logic**; differences are marked with comments.

```sql
-- ============================================================
-- CATEGORIES — tree structure with a materialized path
-- ============================================================
CREATE TABLE categories (
    id            INTEGER PRIMARY KEY,
    slug          TEXT UNIQUE NOT NULL,
    name          TEXT NOT NULL,
    parent_id     INTEGER,
    path          TEXT NOT NULL DEFAULT '',     -- "electronics/phones/android"
    depth         INTEGER NOT NULL DEFAULT 0,
    sort_order    INTEGER DEFAULT 0,
    product_count INTEGER DEFAULT 0,           -- pre-computed, updated on import
    meta          TEXT,                        -- JSON: description, image, SEO
    FOREIGN KEY (parent_id) REFERENCES categories(id)
);
CREATE INDEX idx_cat_parent ON categories(parent_id);
CREATE INDEX idx_cat_path ON categories(path);

-- ============================================================
-- PRODUCTS — the catalog core
-- ============================================================
CREATE TABLE products (
    id            INTEGER PRIMARY KEY,
    sku           TEXT UNIQUE NOT NULL,
    slug          TEXT UNIQUE NOT NULL,
    name          TEXT NOT NULL,
    category_id   INTEGER NOT NULL,
    price         INTEGER NOT NULL,            -- in cents (1990 = €19.90)
    compare_price INTEGER,                     -- original price (for discounts)
    currency      TEXT NOT NULL DEFAULT 'eur',
    stock         INTEGER NOT NULL DEFAULT 0,
    status        TEXT NOT NULL DEFAULT 'active', -- active | draft | archived
    images        TEXT DEFAULT '[]',           -- JSON array of file names
    summary       TEXT DEFAULT '',             -- short description (listing)
    description   TEXT DEFAULT '',             -- full description (HTML, detail)
    attrs         TEXT DEFAULT '{}',           -- JSON: free-form attributes
    tags          TEXT DEFAULT '[]',           -- JSON array of tags
    weight_g      INTEGER DEFAULT 0,           -- weight in grams (shipping)
    created_at    TEXT DEFAULT (datetime('now')),
    updated_at    TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (category_id) REFERENCES categories(id)
);
CREATE INDEX idx_prod_category  ON products(category_id, status, price);
CREATE INDEX idx_prod_status    ON products(status);
CREATE INDEX idx_prod_price     ON products(price);
CREATE INDEX idx_prod_updated   ON products(updated_at);

-- ============================================================
-- FULL-TEXT SEARCH
-- ============================================================

-- === SQLite: FTS5 virtual table + sync triggers ===
-- (in schema-sqlite.sql)
CREATE VIRTUAL TABLE products_fts USING fts5(
    name, sku, summary, tags,
    content=products, content_rowid=id,
    tokenize='unicode61 remove_diacritics 2'  -- Slovak/Czech friendly
);

CREATE TRIGGER products_ai AFTER INSERT ON products BEGIN
    INSERT INTO products_fts(rowid, name, sku, summary, tags)
    VALUES (new.id, new.name, new.sku, new.summary, new.tags);
END;
CREATE TRIGGER products_ad AFTER DELETE ON products BEGIN
    INSERT INTO products_fts(products_fts, rowid, name, sku, summary, tags)
    VALUES ('delete', old.id, old.name, old.sku, old.summary, old.tags);
END;
CREATE TRIGGER products_au AFTER UPDATE ON products BEGIN
    INSERT INTO products_fts(products_fts, rowid, name, sku, summary, tags)
    VALUES ('delete', old.id, old.name, old.sku, old.summary, old.tags);
    INSERT INTO products_fts(rowid, name, sku, summary, tags)
    VALUES (new.id, new.name, new.sku, new.summary, new.tags);
END;

-- === MySQL/MariaDB: FULLTEXT index directly on the table ===
-- (in schema-mysql.sql — no triggers or extra table needed)
-- ALTER TABLE products ADD FULLTEXT idx_ft_products (name, sku, summary, tags);

-- ============================================================
-- FILTERABLE ATTRIBUTES — faceted search without Elasticsearch
-- ============================================================
-- A denormalized table for fast filtering
-- 1M products × ~10 attributes = 10M rows, still <10ms with an index
CREATE TABLE product_attrs (
    product_id  INTEGER NOT NULL,
    attr_name   TEXT NOT NULL,       -- "color", "size", "brand", "material"
    attr_value  TEXT NOT NULL,       -- "black", "XL", "Nike", "cotton"
    numeric_val REAL,                -- for range filters (weight, width...)
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
CREATE INDEX idx_pa_lookup ON product_attrs(attr_name, attr_value, product_id);
CREATE INDEX idx_pa_product ON product_attrs(product_id);
CREATE INDEX idx_pa_numeric ON product_attrs(attr_name, numeric_val)
    WHERE numeric_val IS NOT NULL;

-- ============================================================
-- VARIANTS — size/color combinations
-- ============================================================
CREATE TABLE variants (
    id          INTEGER PRIMARY KEY,
    product_id  INTEGER NOT NULL,
    sku         TEXT UNIQUE NOT NULL,
    name        TEXT NOT NULL DEFAULT '',       -- "XL / Black"
    attrs       TEXT NOT NULL DEFAULT '{}',     -- {"size": "XL", "color": "black"}
    price_delta INTEGER NOT NULL DEFAULT 0,    -- +/- in cents relative to the base price
    price_absolute INTEGER,                   -- absolute price (if set, ignores base+delta)
    stock       INTEGER NOT NULL DEFAULT 0,
    sort_order  INTEGER DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
CREATE INDEX idx_var_product ON variants(product_id);

-- ============================================================
-- ORDERS
-- ============================================================
CREATE TABLE orders (
    id             INTEGER PRIMARY KEY,
    order_number   TEXT UNIQUE NOT NULL,        -- "ORD-20260312-XXXX"
    status         TEXT NOT NULL DEFAULT 'pending', -- pending|paid|shipped|delivered|cancelled
    customer_name  TEXT NOT NULL,
    customer_email TEXT NOT NULL,
    customer_phone TEXT DEFAULT '',
    shipping_addr  TEXT DEFAULT '{}',           -- JSON address object
    payment_method TEXT NOT NULL,
    payment_id     TEXT DEFAULT '',             -- Stripe payment ID
    subtotal       INTEGER NOT NULL,            -- in cents
    shipping_cost  INTEGER NOT NULL DEFAULT 0,
    total          INTEGER NOT NULL,
    currency       TEXT NOT NULL DEFAULT 'eur',
    notes          TEXT DEFAULT '',
    ip_address     TEXT DEFAULT '',
    created_at     TEXT DEFAULT (datetime('now')),
    updated_at     TEXT DEFAULT (datetime('now'))
);
CREATE INDEX idx_ord_status ON orders(status, created_at);
CREATE INDEX idx_ord_email ON orders(customer_email);

CREATE TABLE order_items (
    id          INTEGER PRIMARY KEY,
    order_id    INTEGER NOT NULL,
    product_id  INTEGER NOT NULL,
    variant_id  INTEGER,
    sku         TEXT NOT NULL,
    name        TEXT NOT NULL,                  -- snapshot of the name at order time
    quantity    INTEGER NOT NULL,
    unit_price  INTEGER NOT NULL,               -- snapshot of the price at order time
    total       INTEGER NOT NULL,
    attrs       TEXT DEFAULT '{}',              -- variant snapshot
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
);
CREATE INDEX idx_oi_order ON order_items(order_id);
```

---

## API endpoints

Everything goes through `api.php`, routed by `$_GET['action']`.

| Endpoint | Method | Description | Cache |
|---|---|---|---|
| `?action=categories` | GET | Category tree | `cache/categories.json`, TTL 1h |
| `?action=products&cat={slug}&page={n}&sort={field}&filters={json}` | GET | Paginated listing | `cache/cat/{slug}/page-{n}.json` |
| `?action=product&slug={slug}` | GET | Product detail + variants | `cache/product/{slug}.json` |
| `?action=search&q={query}&page={n}` | GET | FTS5 search | Not cached (fast even without) |
| `?action=filters&cat={slug}` | GET | Available filters for a category | `cache/cat/{slug}/filters.json` |
| `?action=cart` | GET | Current cart | Session |
| `?action=cart_add` | POST | Add to cart | — |
| `?action=cart_update` | POST | Change quantity / remove | — |

Checkout goes through BareBonesForms (`forms/checkout.json` → `submit.php`).

The separate machine API `/api/v1` (products, orders with status changes and shipments, categories, customers, inventory) is described in `docs/openapi.yaml`.

---

## Cache strategy — three levels

```
Level 0: Apache/Nginx (fastest)
  .htaccess: if cache/cat/{slug}/page-1.json exists → serve it directly
  PHP does not run at all. A pure filesystem read.

Level 1: PHP flat-file cache
  api.php checks the cache file's filemtime()
  If < TTL → readfile() and exit
  If expired → query SQLite → write the cache → respond

Level 2: SQLite (always fast)
  Indexed queries: <5ms for 1M rows
  FTS5 search: <20ms
  Fallback if the cache does not exist
```

### Cache invalidation — stale-while-revalidate

We never delete a cache file directly. Always an atomic swap: a new `.tmp` → `rename()`.
Users never feel a "cold cache".

```php
class Cache {
    // Atomic write — write to .tmp, then rename (atomic on POSIX)
    public static function write(string $path, string $data): void {
        $file = self::path($path);
        $tmp = $file . '.tmp.' . getmypid();
        $dir = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        file_put_contents($tmp, $data, LOCK_EX);
        rename($tmp, $file);  // atomic swap — the old file is replaced immediately
    }

    // Invalidation = schedule a rebuild, but DO NOT delete the existing file
    public static function invalidate(string $path): void {
        JobQueue::push('cache_rebuild', ['path' => $path], priority: 1);
    }

    // Bulk invalidation — rebuild in the background, the existing cache keeps serving
    public static function invalidatePrefix(string $prefix): void {
        JobQueue::push('cache_rebuild', ['prefix' => $prefix], priority: 2);
    }

    // Flush = mark everything as stale, rebuild lazily
    // We delete nothing — old files are served until new ones are generated
    public static function flush(): void {
        file_put_contents(self::path('.flush_marker'), time());
        JobQueue::push('cache_rebuild', ['scope' => 'full'], priority: 3);
    }
}
```

### .htaccess rules for the Level 0 cache

```apache
# If a cache file exists, serve it directly (bypass PHP)
RewriteCond %{DOCUMENT_ROOT}/cache/cat/%1/page-%2.json -f
RewriteRule ^api\.php\?action=products&cat=([^&]+)&page=(\d+)$ cache/cat/$1/page-$2.json [L,T=application/json]

# API fallback
RewriteRule ^api/(.*)$ api.php?route=$1 [QSA,L]

# SEO-friendly product URLs
RewriteRule ^product/([a-z0-9-]+)$ product.php?slug=$1 [QSA,L]

# SEO-friendly category URLs
RewriteRule ^category/([a-z0-9/-]+)$ index.php?category=$1 [QSA,L]
```

---

## Product JSON format

```json
{
    "sku": "TSHIRT-BLK-L",
    "slug": "cierne-tricko",
    "name": "Čierne tričko Classic",
    "category": "oblecenie/tricka",
    "price": 1990,
    "compare_price": 2490,
    "currency": "eur",
    "stock": 342,
    "status": "active",
    "images": ["tshirt-blk-1.jpg", "tshirt-blk-2.jpg"],
    "summary": "Klasické čierne tričko zo 100% bavlny.",
    "description": "<p>Prémiová bavlna, double-stitch švy...</p>",
    "attrs": {
        "brand": "BasicWear",
        "material": "100% bavlna",
        "country": "Portugalsko"
    },
    "tags": ["leto", "basics", "bestseller"],
    "weight_g": 200,
    "variant_dimensions": ["size", "color"],
    "variants": [
        {"sku": "TSHIRT-BLK-S", "name": "S / Čierna", "attrs": {"size": "S", "color": "čierna"}, "price_delta": 0, "stock": 85},
        {"sku": "TSHIRT-BLK-M", "name": "M / Čierna", "attrs": {"size": "M", "color": "čierna"}, "price_delta": 0, "stock": 120},
        {"sku": "TSHIRT-BLK-L", "name": "L / Čierna", "attrs": {"size": "L", "color": "čierna"}, "price_delta": 0, "stock": 95},
        {"sku": "TSHIRT-BLK-XL", "name": "XL / Čierna", "attrs": {"size": "XL", "color": "čierna"}, "price_delta": 200, "stock": 42}
    ]

    // Alternative for electronics — an absolute price instead of a delta:
    // {"sku": "IPHONE-128", "attrs": {"storage": "128GB"}, "price_absolute": 99900, "stock": 20}
    // {"sku": "IPHONE-256", "attrs": {"storage": "256GB"}, "price_absolute": 119900, "stock": 15}
    // Logic: COALESCE(price_absolute, base_price + price_delta)
}
```

---

## Search — FTS5 (SQLite) / FULLTEXT (MySQL)

### Why we do not need Elasticsearch

| Feature | Elasticsearch | SQLite FTS5 | MySQL FULLTEXT |
|---|---|---|---|
| Full-text search | Yes | Yes | Yes |
| Diacritics (`č`, `š`, `ž`) | Plugin | `remove_diacritics 2` | Collation dependent |
| Ranking (relevance) | BM25 | BM25 (built-in) | TF-IDF |
| Prefix match ("tri*") | Yes | Yes | Yes (BOOLEAN mode) |
| Phrase match | Yes | Yes | Yes |
| Highlight snippets | Yes | `highlight()` | Manual |
| 1M documents, <50ms | Yes | Yes | Yes (InnoDB) |
| Infrastructure | JVM + cluster | None | Already running |
| RAM requirements | 2-8 GB | ~50 MB | Existing server |

### SQLite FTS5

```sql
-- Basic search with BM25 ranking
SELECT p.*, rank
FROM products_fts fts
JOIN products p ON p.id = fts.rowid
WHERE products_fts MATCH :query
  AND p.status = 'active'
ORDER BY rank
LIMIT 20 OFFSET :offset;

-- With highlight snippets
SELECT p.*,
    highlight(products_fts, 0, '<mark>', '</mark>') AS name_hl,
    rank
FROM products_fts fts
JOIN products p ON p.id = fts.rowid
WHERE products_fts MATCH :query AND p.status = 'active'
ORDER BY rank LIMIT 20 OFFSET :offset;

-- Prefix search (autocomplete): "tri" → "tričko", "trička"
WHERE products_fts MATCH '"tri" * '
```

### MySQL/MariaDB FULLTEXT

```sql
-- Setup: FULLTEXT index on the products table (in schema-mysql.sql)
ALTER TABLE products ADD FULLTEXT idx_ft_products (name, summary, tags);

-- Natural language search with relevance
SELECT p.*, MATCH(name, summary, tags) AGAINST(:query) AS relevance
FROM products p
WHERE MATCH(name, summary, tags) AGAINST(:query IN NATURAL LANGUAGE MODE)
  AND p.status = 'active'
ORDER BY relevance DESC
LIMIT 20 OFFSET :offset;

-- Boolean mode for advanced queries (+required, -excluded, "phrase")
WHERE MATCH(name, summary, tags)
    AGAINST('+čierne +tričko -detské' IN BOOLEAN MODE)

-- Prefix match (autocomplete)
WHERE MATCH(name, summary, tags) AGAINST('tri*' IN BOOLEAN MODE)
```

### MySQL full-text for Slovak/Czech

```sql
-- MariaDB 10.4+ has good InnoDB FULLTEXT with Unicode support
-- For better results with diacritics:
-- 1. The table must use the utf8mb4_unicode_ci collation
-- 2. Lower the minimum word length for short Slovak words:
--    SET GLOBAL innodb_ft_min_token_size = 2;  (the default is 3)
-- 3. Restart MySQL after the change
```

---

## Faceted filtering — without Elasticsearch

The key trick: the `product_attrs` table + set intersection in SQL.

### Getting the available filters for a category

```sql
-- Which filters and values are available in the "T-shirts" category?
SELECT pa.attr_name, pa.attr_value, COUNT(*) as cnt
FROM product_attrs pa
JOIN products p ON p.id = pa.product_id
WHERE p.category_id = :cat_id AND p.status = 'active'
GROUP BY pa.attr_name, pa.attr_value
ORDER BY pa.attr_name, cnt DESC;

-- Result:
-- brand    | BasicWear  | 45
-- brand    | Nike       | 32
-- color    | čierna     | 67
-- color    | biela      | 54
-- material | bavlna     | 89
-- size     | M          | 71
-- size     | L          | 68
```

### Applying several filters

```sql
-- Products in the "T-shirts" category, color=black, size=L
-- Technique: COUNT(DISTINCT) = the number of active filters
SELECT p.*
FROM products p
JOIN product_attrs pa ON pa.product_id = p.id
WHERE p.category_id = :cat_id
  AND p.status = 'active'
  AND (
    (pa.attr_name = 'color' AND pa.attr_value = 'čierna')
    OR (pa.attr_name = 'size' AND pa.attr_value = 'L')
  )
GROUP BY p.id
HAVING COUNT(DISTINCT pa.attr_name) = 2  -- must match both filters
ORDER BY p.price ASC
LIMIT 24 OFFSET :offset;
```

### Range filter (price, weight)

```sql
-- Products priced €10-€50
WHERE p.price BETWEEN 1000 AND 5000

-- Combined with attributes
SELECT p.*
FROM products p
LEFT JOIN product_attrs pa ON pa.product_id = p.id
  AND (
    (pa.attr_name = 'color' AND pa.attr_value = 'čierna')
  )
WHERE p.category_id = :cat_id
  AND p.status = 'active'
  AND p.price BETWEEN :min_price AND :max_price
GROUP BY p.id
HAVING COUNT(pa.product_id) = 1
LIMIT 24;
```

### Performance optimization for 1M+ products

`GROUP BY + HAVING COUNT` creates a temporary table, which for large categories (50k+ products)
can take 100-300ms.

**CAUTION: `LIKE '%..%'` is a full table scan!** If a `LIKE` condition starts with a percent sign, the B-tree index
CANNOT be used. With 1M products that = reading a million rows. Unusable.

**Solution: faceted filtering through the FTS index.** Since we already have FTS5/FULLTEXT, we add the filter
attributes directly into the FTS index as tokens. An inverted index = O(1) lookup.

```sql
-- Format: attributes as FTS-friendly tokens
-- Instead of: "|color:black|size:L|"
-- Generate:   "attr_color_black attr_size_l attr_brand_nike"

-- SQLite FTS5 — filter_tags as another column in the FTS table
CREATE VIRTUAL TABLE products_fts USING fts5(
    name, sku, summary, tags,
    filter_tags,                              -- ← new column
    content=products, content_rowid=id,
    tokenize='unicode61 remove_diacritics 2'
);

-- Faceted filter through FTS (lightning fast, even with 10M products):
SELECT p.*
FROM products_fts fts
JOIN products p ON p.id = fts.rowid
WHERE products_fts MATCH 'attr_color_black AND attr_size_l'
  AND p.category_id = :cat_id
  AND p.status = 'active'
ORDER BY p.price ASC
LIMIT 24 OFFSET :offset;
-- Performance: O(1) inverted index lookup, <5ms

-- MySQL/MariaDB — filter_tags as a FULLTEXT column:
ALTER TABLE products ADD COLUMN filter_tags TEXT DEFAULT '';
ALTER TABLE products ADD FULLTEXT idx_ft_filters (filter_tags);

SELECT p.*
FROM products p
WHERE MATCH(filter_tags) AGAINST('+attr_color_black +attr_size_l' IN BOOLEAN MODE)
  AND p.category_id = :cat_id
  AND p.status = 'active'
ORDER BY p.price ASC
LIMIT 24 OFFSET :offset;
```

```php
// filter_tags is generated on import / product update:
public static function buildFilterTags(int $productId): string {
    $attrs = Db::get()->prepare("SELECT attr_name, attr_value FROM product_attrs WHERE product_id = :id");
    $attrs->execute([':id' => $productId]);
    $tags = '';
    foreach ($attrs->fetchAll() as $a) {
        // Normalize: lowercase, no diacritics, underscore instead of spaces
        $name = self::normalizeToken($a['attr_name']);
        $value = self::normalizeToken($a['attr_value']);
        $tags .= "attr_{$name}_{$value} ";
    }
    return trim($tags);
    // Output: "attr_color_black attr_size_l attr_brand_nike attr_material_cotton"
}

private static function normalizeToken(string $s): string {
    $s = mb_strtolower($s);
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);  // diacritics → ASCII
    $s = preg_replace('/[^a-z0-9]/', '_', $s);
    return trim($s, '_');
}
```

Filter counters (counts for the sidebar) are computed asynchronously and cached in `cache/filters/{category}.json`. They refresh on import or a stock update.

---

## Cart

**Dual persistence:** PHP session + a localStorage mirror. The session expires (24 min by default) — localStorage does not. When the customer comes back (mobile, the next day), JS restores the cart from localStorage into the session. No lost cart = no lost conversion.

### Frontend — localStorage mirror (shop.js)

```javascript
const CartStore = {
    save(cart) {
        localStorage.setItem('bbs_cart', JSON.stringify(cart));
    },
    load() {
        try { return JSON.parse(localStorage.getItem('bbs_cart')) || null; }
        catch { return null; }
    },
    clear() {
        localStorage.removeItem('bbs_cart');
    },

    // On page load: if the PHP session is empty but localStorage has data → restore
    async restore() {
        const serverCart = await fetch('api.php?action=cart').then(r => r.json());
        if (serverCart.count > 0) {
            this.save(serverCart); // sync server → local
            return serverCart;
        }
        const local = this.load();
        if (local && local.items?.length > 0) {
            // The session expired but localStorage has a cart → restore it on the server
            await fetch('api.php?action=cart_restore', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
                body: JSON.stringify(local.items),
            });
            return await fetch('api.php?action=cart').then(r => r.json());
        }
        return serverCart;
    }
};
```

### Backend — session + restore endpoint

The cart is primarily session-based, but with a restore endpoint for localStorage recovery:

```php
// Cart.php — a simple session implementation
class Cart {
    public static function get(): array {
        return $_SESSION['cart'] ?? [];
    }

    public static function add(int $productId, ?int $variantId, int $qty): void {
        $key = $variantId ? "v_{$variantId}" : "p_{$productId}";
        if (!isset($_SESSION['cart'][$key])) {
            $_SESSION['cart'][$key] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'qty'        => 0,
            ];
        }
        $_SESSION['cart'][$key]['qty'] += $qty;
    }

    public static function update(string $key, int $qty): void {
        if ($qty <= 0) {
            unset($_SESSION['cart'][$key]);
        } else {
            $_SESSION['cart'][$key]['qty'] = $qty;
        }
    }

    public static function enriched(): array {
        // Load current prices and stock from the DB (not from the cache — we want real time)
        $cart = self::get();
        if (empty($cart)) return ['items' => [], 'subtotal' => 0, 'count' => 0];

        $db = Db::get();
        $items = [];
        $subtotal = 0;

        foreach ($cart as $key => $item) {
            if ($item['variant_id']) {
                $row = $db->querySingle("
                    SELECT p.name, p.slug, p.images,
                           COALESCE(v.price_absolute, p.price + v.price_delta) as price,
                           v.sku, v.name as variant_name, v.stock
                    FROM variants v
                    JOIN products p ON p.id = v.product_id
                    WHERE v.id = :vid AND p.status = 'active'
                ", [':vid' => $item['variant_id']]);
            } else {
                $row = $db->querySingle("
                    SELECT name, slug, images, price, sku, '' as variant_name, stock
                    FROM products
                    WHERE id = :pid AND status = 'active'
                ", [':pid' => $item['product_id']]);
            }

            if (!$row) { unset($_SESSION['cart'][$key]); continue; }

            $qty = min($item['qty'], $row['stock']); // cap at stock
            $total = $row['price'] * $qty;
            $subtotal += $total;

            $items[] = [
                'key'          => $key,
                'sku'          => $row['sku'],
                'name'         => $row['name'],
                'variant_name' => $row['variant_name'],
                'slug'         => $row['slug'],
                'image'        => json_decode($row['images'], true)[0] ?? null,
                'price'        => $row['price'],
                'qty'          => $qty,
                'total'        => $total,
                'stock'        => $row['stock'],
            ];
        }

        return [
            'items'    => $items,
            'subtotal' => $subtotal,
            'count'    => array_sum(array_column($items, 'qty')),
        ];
    }

    public static function clear(): void {
        unset($_SESSION['cart']);
    }

    // Restores the cart from localStorage data (when the session expired)
    public static function restore(array $items): void {
        foreach ($items as $item) {
            $productId = Security::int($item['product_id'] ?? 0);
            $variantId = $item['variant_id'] ? Security::int($item['variant_id']) : null;
            $qty = max(1, Security::int($item['qty'] ?? 1));
            if ($productId > 0) {
                self::add($productId, $variantId, $qty);
            }
        }
    }

    // Cart abandonment: stores the e-mail at the first checkout step
    // If the cart is > 24h old and has an e-mail → job: send a reminder
    public static function setEmail(string $email): void {
        $_SESSION['cart_email'] = $email;
        $_SESSION['cart_email_at'] = time();
    }
}
```

---

## Checkout — integration with BareBonesForms

The checkout form is a standard BBF form (`forms/checkout.json`).
Order flow:

```
1. The user clicks "Order" in the cart
2. JS fills the hidden fields (items JSON, subtotal, shipping)
3. BBF renders the checkout form (address, payment, GDPR)
4. BBF submit → submit.php
5. Custom handler in on_submit:
   a) Stock validation (atomic — SELECT + UPDATE in a transaction)
   b) Creating the order in the orders table
   c) Decrementing the stock
   d) Stripe payment (existing BBF integration)
   e) Confirmation e-mail (existing BBF integration)
   f) Admin notification
   g) Clearing the cart
   h) Invalidating the cache for the affected products
```

### Atomic stock reservation

```sql
-- In one transaction:
BEGIN;

-- Check and decrement the stock (atomically)
UPDATE variants SET stock = stock - :qty
WHERE id = :variant_id AND stock >= :qty;

-- If rows_affected = 0 → the product is out of stock → ROLLBACK
-- If OK → INSERT the order → COMMIT

COMMIT;
```

### Race condition UX — when a product sells out during checkout

Customers A and B have the last piece in their carts. Both click "Pay" in the same second.
A gets the lock → the UPDATE succeeds. B arrives a second later → `stock >= :qty` fails → 0 rows affected.

**Critical:** we must not charge Stripe before a successful stock reservation!

```php
// In the checkout handler (submit.php):
$db->beginTransaction();

$outOfStock = [];
foreach ($cartItems as $item) {
    $stmt = $db->prepare("UPDATE variants SET stock = stock - :qty
        WHERE id = :vid AND stock >= :qty");
    $stmt->execute([':qty' => $item['qty'], ':vid' => $item['variant_id']]);

    if ($stmt->rowCount() === 0) {
        // This product has just sold out
        $outOfStock[] = $item['name'] . ($item['variant_name'] ? ' — ' . $item['variant_name'] : '');
    }
}

if (!empty($outOfStock)) {
    $db->rollBack();
    // Update the cart — remove/reduce the unavailable items
    Cart::removeUnavailable($outOfStock);

    // Return a friendly error — BBF error format
    echo json_encode([
        'success' => false,
        'error'   => I18n::t('stock_changed'),
        'details' => $outOfStock,
        // "Sorry, these items sold out in the meantime: Black T-shirt L.
        //  Your cart has been updated."
    ]);
    exit;
}

// All stock OK → create the order → ONLY THEN Stripe
$orderId = Orders::create($cartItems, $customerData);
$db->commit();

// Stripe charge ONLY after a successful commit
$payment = Adapters::payment();
$session = $payment->createSession(Orders::byId($orderId));
```

---

## Import — CSV and JSON

### CSV format (simple, Excel-friendly)

```csv
sku,name,category,price,stock,summary,image,attr:color,attr:size,attr:brand
TSHIRT-BLK-S,Čierne tričko S,oblecenie/tricka,1990,85,Klasické čierne tričko,tshirt-blk.jpg,čierna,S,BasicWear
TSHIRT-BLK-M,Čierne tričko M,oblecenie/tricka,1990,120,Klasické čierne tričko,tshirt-blk.jpg,čierna,M,BasicWear
```

### Import pipeline

```
CSV/JSON file
    → Validation (format, required fields, types)
    → Deduplication (UPSERT by SKU)
    → Categories: auto-create from the path (oblecenie/tricka → 2 categories)
    → product_attrs: auto-populate from attr:* columns
    → FTS5: automatically through triggers
    → product_count: recount per category
    → Cache: invalidation of the affected categories
```

### Import performance

The earlier seed and query numbers were design estimates, not measurements. The reproducible
runner is `cli/benchmark.php`; it creates only a marked disposable runtime outside the repository
and refuses a normal configuration or a non-empty dataset.

```bash
# Run from a fresh disposable checkout without config.php:
php cli/benchmark.php --prepare --runtime=/absolute/path/barebones-benchmark-run \
  --dataset=100000 --iterations=100 --concurrency=4

# Recovery only after an interrupted read profile with exactly the same completed dataset:
php cli/benchmark.php --reuse --runtime=/absolute/path/barebones-benchmark-run \
  --dataset=100000 --iterations=20 --concurrency=4
```

---

## Measured performance and failure baseline (SQLite, 100k products)

Measured on 2026-08-02 on the base commit `8a364e9` with the G13 working-tree changes: Windows 10 AMD64, 24 logical CPUs,
PHP 8.5.1, SQLite WAL, PHP memory limit 128 MB, database 76,271,616 B. The dataset has
100,000 deterministic products, 20 categories and two attributes per product; neither the data nor the
JSON report is committed. Every percentile operation had 5 warmups and 20 measurements;
p50/p95/p99 is nearest-rank. HTTP TTFB measures the real SSR response over loopback
`php -S`, not a production web server or a network.

| Operation | p50 | p95 | p99 | Interpretation |
|---|---:|---:|---:|---|
| Product by slug (indexed) | 0.036 ms | 0.038 ms | 0.038 ms | The B-tree lookup is under 1 ms in this profile. |
| Category listing, 24, price asc | 40.797 ms | 44.314 ms | 45.087 ms | The original 2–5 ms target was not confirmed. |
| Selective FTS5 lookup by SKU | 0.420 ms | 0.432 ms | 0.453 ms | The FTS-first count plan removed the 100k scan. |
| Broad FTS5 term, 100k matches | 126.567 ms | 129.186 ms | 135.722 ms | A saturation profile, not a normal selective lookup. |
| Faceted filter, 2 attributes | 27.325 ms | 28.218 ms | 28.630 ms | The original 5–15 ms target was not confirmed. |
| Price range | 27.355 ms | 27.899 ms | 27.904 ms | The original 2–5 ms target was not confirmed. |
| Storefront SSR TTFB | 6.152 ms | 7.835 ms | 8.220 ms | Single-process PHP dev server, warm local cache. |
| Product SSR TTFB | 6.521 ms | 7.341 ms | 7.425 ms | Single-process PHP dev server, warm local cache. |

For `COUNT(*)` the original SQLite planner started with the store/status index and ran an FTS
probe for all 100,000 products. The FTS-first `CROSS JOIN` plan reduced the selective
p95 from 4,091.096 ms to 0.432 ms; a broad term that matches the whole dataset has a p95 of
129.186 ms. The 1M profile was not run, so claims about 1M performance remain
**unverified**. Larger or search-heavy catalogs need a separate qualification
or a declared `SearchAdapter`.

### G4 full category and API profile

G4 extended the same disposable 100k profile to 2,020 categories and 400,000
`product_specifications`. It measures the total response time and TTFB for the real category SSR
and the `parametric_search` API, always guest/customer and cold/warm; it also records the query count.
The cold profile clears the application cache before every request, the warm profile uses five
warmups. The query budget is more authoritative than the portability of local wall-clock time.

| Measured path | Before p95 / queries | After p95 / queries | Decision |
|---|---:|---:|---|
| Specification facets without a filter | 364.124 ms / 5 | 24.106 ms / 1 | Criteria are aggregated with one batch query. |
| Specification facets, 1 active filter | 372.684 ms / 5 | 56.891 ms / 2 | One shared subset + one self-exclusion query. |
| Category tree, 2,020 nodes | 91.556 ms / 1 | 4.733 ms / 1 | An O(n) parent index replaced the repeated scan of all rows. |
| Related, a category with 5,000 products | 1.032 ms / 2 | 1.059 ms / 2 | `RANDOM/RAND` was not changed: the measurement did not confirm a hotspot. |

The optimized full SSR had a p95 of 43.533–62.049 ms with 35 guest and 38 customer
queries; the API p95 was 38.388–48.660 ms with 14 guest and 17 customer queries. The runner
fails above the query budgets of 36/39 for SSR and 15/18 for the API. The CI time ceilings are
deliberately conservative (500 ms for HTTP, 150/250 ms for facets, 25 ms for the tree
and related) to catch an order-of-magnitude regression without pretending a universal latency.
The raw JSON is written only into the marked runtime and is not committed.

### Concurrency and failure invariants

The serial `Orders::create()` control and 4/4 SQLite checkout workers passed. The workers
started with an explicit 25 ms ramp, still overlapped in time, and finished in
167.537 ms wall time on different SKUs. The SQLite checkout uses a file lock next to the database,
`BEGIN IMMEDIATE`, a 5 s bounded wait and does not translate a `PDOException` into a false out-of-stock.
This is safe serialization of a single SQLite writer, not proof of parallel write
throughput; for a high number of concurrent writers MySQL/MariaDB remains the recommendation.

On the same disposable profile 6/6 fault invariants passed:

- a payment timeout did not create a false initiated transaction,
- a duplicate payment webhook changed the state only once,
- an expired database-queue lease was reclaimed and the old owner could not complete it,
- a mail outage stayed audited as `failed`, not `sent`,
- a carrier outage did not create a phantom shipping label,
- a blocked backup target failed closed without a partial `.db` file.

The runner writes the resource envelope and all raw percentile/failure data to
`<runtime>/reports/benchmark-100000.json`. The backup failure simulation uses a blocked
target path; it deliberately does not fill a real disk.

### SQLite PRAGMA optimizations

```sql
PRAGMA journal_mode = WAL;          -- concurrent reads during a write
PRAGMA synchronous = NORMAL;        -- safe, but faster than FULL
PRAGMA cache_size = -64000;         -- 64MB cache (the default is 2MB)
PRAGMA temp_store = MEMORY;         -- temp tables in RAM
PRAGMA mmap_size = 268435456;       -- 256MB memory-mapped I/O
PRAGMA page_size = 4096;            -- optimal for most operating systems
```

---

## Security — in depth

### Overview of attack vectors

| Vector | Protection | Implementation |
|---|---|---|
| SQL injection | Prepared statements only | PDO with `EMULATE_PREPARES=false` |
| XSS | Output escaping + CSP | `esc()` helper + Content-Security-Policy header |
| CSRF | Token in the session | The same mechanism as BBF |
| Rate limiting | IP-based counter | Flat file, configurable limits |
| Directory traversal | Whitelist pattern | `[a-z0-9-]` only for slugs |
| File upload | Reprocess + rename | GD/Imagick rewrite, random name |
| Brute force (admin) | Progressive delay + lockout | Exponential backoff after 3 attempts |
| Stock manipulation | Atomic transactions | Server-side price, never from the client |
| Session fixation | ID regeneration | `session_regenerate_id(true)` after login |
| Clickjacking | X-Frame-Options | `DENY` or `SAMEORIGIN` |
| MIME sniffing | X-Content-Type-Options | `nosniff` |
| Open redirect | Domain whitelist | No redirect to a user-supplied URL |

### Security.php — centralized security

```php
class Security {
    // ============================================================
    // HTTP headers — call once at the start of every request
    // ============================================================
    public static function headers(): void {
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: DENY");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
        // CSP — strict but practical
        header("Content-Security-Policy: "
            . "default-src 'self'; "
            . "script-src 'self' https://js.stripe.com; "   // Stripe JS
            . "style-src 'self' 'unsafe-inline'; "           // inline styles for the dynamic UI
            . "img-src 'self' data: https:; "                // images from a CDN
            . "connect-src 'self' https://api.stripe.com; "  // API + Stripe
            . "frame-src https://js.stripe.com; "            // Stripe iframe
            . "object-src 'none'; "
            . "base-uri 'self';"
        );
    }

    // ============================================================
    // CSRF — token generation and validation
    // ============================================================
    public static function csrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function csrfValidate(): bool {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return hash_equals($_SESSION['csrf_token'] ?? '', $token);
    }

    // ============================================================
    // Rate limiting — a dedicated SQLite (not flat files)
    // ============================================================
    // Flat-file rate limiting chokes on disk I/O during an L7 DDoS.
    // A separate SQLite DB with PRAGMA synchronous=OFF is 100x faster.
    private static ?PDO $rateDb = null;

    private static function rateDb(): PDO {
        if (!self::$rateDb) {
            self::$rateDb = new PDO('sqlite:' . __DIR__ . '/../cache/rate_limit.sqlite');
            self::$rateDb->exec('PRAGMA synchronous = OFF');
            self::$rateDb->exec('PRAGMA journal_mode = MEMORY');
            self::$rateDb->exec('CREATE TABLE IF NOT EXISTS hits (
                key TEXT NOT NULL, ts INTEGER NOT NULL
            )');
            self::$rateDb->exec('CREATE INDEX IF NOT EXISTS idx_hits ON hits(key, ts)');
            // Clean up old records (>5 min) — on every open
            self::$rateDb->exec("DELETE FROM hits WHERE ts < " . (time() - 300));
        }
        return self::$rateDb;
    }

    public static function rateLimit(string $action, int $maxPerMinute = 60): bool {
        $ip = self::clientIp();
        $key = md5($action . '|' . $ip);
        $now = time();
        $window = $now - 60;
        $db = self::rateDb();

        // Record the hit
        $db->prepare("INSERT INTO hits (key, ts) VALUES (:key, :ts)")
           ->execute([':key' => $key, ':ts' => $now]);

        // Count the hits in the window
        $stmt = $db->prepare("SELECT COUNT(*) FROM hits WHERE key = :key AND ts > :window");
        $stmt->execute([':key' => $key, ':window' => $window]);
        $count = (int) $stmt->fetchColumn();

        if ($count > $maxPerMinute) {
            http_response_code(429);
            header('Retry-After: 60');
            return false;
        }
        return true;
    }

    // ============================================================
    // Input sanitization
    // ============================================================
    public static function slug(string $input): string {
        return preg_replace('/[^a-z0-9-]/', '', strtolower($input));
    }

    public static function int($input, int $default = 0): int {
        return filter_var($input, FILTER_VALIDATE_INT) !== false
            ? (int)$input : $default;
    }

    public static function email(string $input): ?string {
        $email = filter_var(trim($input), FILTER_VALIDATE_EMAIL);
        return $email ?: null;
    }

    // ============================================================
    // IP address — behind a proxy/load balancer
    // ============================================================
    public static function clientIp(): string {
        // Trust X-Forwarded-For only if a trusted proxy is defined
        $cfg = require __DIR__ . '/../config.php';
        $trusted = $cfg['trusted_proxies'] ?? [];

        if (!empty($trusted) && in_array($_SERVER['REMOTE_ADDR'], $trusted)) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            $ips = array_map('trim', explode(',', $forwarded));
            return $ips[0] ?: $_SERVER['REMOTE_ADDR'];
        }
        return $_SERVER['REMOTE_ADDR'];
    }

    // ============================================================
    // Admin authentication — with brute-force protection
    // ============================================================
    public static function adminAuth(): bool {
        $cfg = require __DIR__ . '/../config.php';
        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW'] ?? '';

        // Lockout check
        $ip = self::clientIp();
        $lockFile = __DIR__ . '/../cache/rate/admin_' . md5($ip);
        $lockData = file_exists($lockFile) ? json_decode(file_get_contents($lockFile), true) : null;

        if ($lockData && $lockData['failures'] >= 5) {
            $lockoutSec = min(300, pow(2, $lockData['failures'] - 3)); // exponential backoff
            if (time() - $lockData['last_attempt'] < $lockoutSec) {
                header('HTTP/1.1 429 Too Many Requests');
                header('Retry-After: ' . $lockoutSec);
                exit('Too many login attempts. Try again later.');
            }
        }

        if ($user === $cfg['admin_user'] && password_verify($pass, $cfg['admin_password'])) {
            // Reset the lockout on a successful login
            if (file_exists($lockFile)) unlink($lockFile);
            return true;
        }

        // Record the failed attempt
        $lockData = $lockData ?? ['failures' => 0];
        $lockData['failures']++;
        $lockData['last_attempt'] = time();
        file_put_contents($lockFile, json_encode($lockData), LOCK_EX);

        header('WWW-Authenticate: Basic realm="Shop Admin"');
        header('HTTP/1.1 401 Unauthorized');
        exit('Unauthorized');
    }
}

// ============================================================
// Helper function — global, short, used everywhere
// ============================================================
function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
```

### Secure checkout flow

```
Browser                      Server
  │                             │
  │  POST /api/cart_add         │
  │  + CSRF token               │──→ Validate CSRF
  │  + product_id, variant_id   │──→ Validate the ID (int), stock > 0
  │  + qty                      │──→ Cap qty at stock, max 99
  │                             │
  │  GET checkout form (BBF)    │
  │                             │──→ Render with the CSRF token
  │                             │──→ Prices and totals = SERVER-SIDE
  │                             │    (never from hidden fields)
  │                             │
  │  POST submit (BBF)          │
  │  + form data                │──→ Validate inputs (BBF validation)
  │  + CSRF token               │──→ Validate CSRF
  │                             │──→ Rate limit: 10/min checkout
  │                             │──→ BEGIN TRANSACTION
  │                             │──→   Recalculate the total server-side
  │                             │──→   Check stock atomically
  │                             │──→   UPDATE stock (WHERE stock >= qty)
  │                             │──→   INSERT order + order_items
  │                             │──→ COMMIT (or ROLLBACK)
  │                             │──→ Stripe charge (with the server-side amount)
  │                             │──→ E-mail confirmation
  │                             │
  │  ← Redirect to thank you    │
```

### What to NEVER do

```
✗ Prices from hidden fields or JavaScript → ALWAYS recalculate on the server
✗ Stock from the client → ALWAYS check in the DB within a transaction
✗ SQL through string concatenation → ALWAYS prepared statements
✗ Trust X-Forwarded-For without a trusted proxy → spoofable
✗ A slug from user input without sanitization → directory traversal
✗ An admin without rate limiting → brute force
✗ An image without reprocessing → PHP shell upload
✗ A session token in the URL → session fixation through the referer
```

---

## Admin panel

Minimalist, without a framework. Legacy HTTP Basic auth remains only for installations without
a DB identity. Once the first `admin_accounts` record exists, a form-based login is used
with a password hash, encrypted TOTP or a one-time recovery code,
a server-side session and a revocable device record. Both the role and the store permission grant
must allow the action; an account without a grant for the current store fails closed.
A TOTP code is valid only once: `admin_accounts.totp_last_counter` atomically accepts only a newer
time step, so a replay within the ±30 s window fails.

The storefront session runs with `session.use_strict_mode`, a customer login rotates the
CSRF token and login throttling is per account + IP (someone else's attempts do not lock out the owner).
A session ID is stored in the DB (wishlist `s:<…>`, abandoned carts, cookie consent) only as
`Security::sessionKey()` = SHA-256; migration `20261012200000` rehashes old values.

The **Admin users** page (`admin.php?p=admin_users`, permission `admins.manage`, the owner has it
through `*`) replaces the CLI for everyday management: creating an account, blocking/unblocking, a new password,
TOTP enroll/reset (the secret and recovery codes are shown once; resetting another account ends its
sessions), signing out everywhere and store grants for the active store. A non-owner with a delegated
`admins.manage` must not create or change an owner account; nobody can block themselves or
the last active owner. Every change goes to `audit_log` (entity `admin_account`).

Customer e-mail verification: registration sends a link `account.php?p=verify_email&token=…`
(table `customer_email_verifications`, only the SHA-256 is stored, valid for 24 h, single-use,
bound to the address and the store). The dashboard offers a resend, at most 3 per hour per account;
a new link invalidates the old one. `verify_url` is not stored in `email_log`.

### Features

1. **Dashboard** — orders today, revenue, low stock alert
2. **Products** — CRUD, inline price/stock edit, bulk actions
3. **Orders** — list, status change, detail
4. **Import** — CSV upload, preview, confirmation
5. **Cache** — manual flush, rebuild
6. **Catalog and commerce screens** — categories, brands, promotions and coupons, price lists, B2B companies and customer groups, suppliers and purchase orders, e-mail templates

### Bulk operations

```
- Price change for a category (+10%, -€5, set to X)
- Status change (activate/archive/draft)
- CSV export (filtered)
- Stock update from CSV (sku + qty)
```

---

## Frontend — vanilla JS

### Catalog (shop.js)

```javascript
// Structure - no framework, plain DOM
const Shop = {
    state: {
        category: null,
        page: 1,
        sort: 'price_asc',
        filters: {},
        cart: { items: [], count: 0, subtotal: 0 },
    },

    // Loading products (cache-through)
    async loadProducts() {
        const params = new URLSearchParams({
            action: 'products',
            cat: this.state.category,
            page: this.state.page,
            sort: this.state.sort,
            filters: JSON.stringify(this.state.filters),
        });
        const res = await fetch(`api.php?${params}`);
        const data = await res.json();
        this.renderProducts(data.products);
        this.renderPagination(data.total, data.page, data.per_page);
    },

    // Infinite scroll OR classic pagination (configurable)
    // Faceted filters in the sidebar
    // Sort: price ↑↓, name, newest, best-selling
    // Lazy loading of images (native loading="lazy")
    // Cart widget in the header (count badge)
};
```

### SEO — server-side rendering

`product.php` renders full HTML for crawlers:

```php
// product.php — SSR for SEO
$slug = preg_replace('/[^a-z0-9-]/', '', $_GET['slug'] ?? '');
$product = Products::bySlug($slug);
if (!$product) { http_response_code(404); exit; }
?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <title><?= esc($product['name']) ?> | Shop</title>
    <meta name="description" content="<?= esc($product['summary']) ?>">
    <meta property="og:title" content="<?= esc($product['name']) ?>">
    <meta property="og:image" content="/uploads/medium/<?= esc($product['images'][0]) ?>">
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Product",
        "name": "<?= esc($product['name']) ?>",
        "image": "/uploads/full/<?= esc($product['images'][0]) ?>",
        "offers": {
            "@type": "Offer",
            "price": "<?= number_format($product['price'] / 100, 2) ?>",
            "priceCurrency": "EUR",
            "availability": "<?= $product['stock'] > 0 ? 'InStock' : 'OutOfStock' ?>"
        }
    }
    </script>
</head>
```

---

## 5 adapter interfaces — swappable boundaries

The core is bare bones. But in 5 key places it has a clear interface that can be swapped without changing core code. The default implementation works out of the box. Whoever needs more writes an adapter.

### Principle

```php
// Every adapter implements an interface
// config.php decides which adapter is used
// Core code calls only interface methods — never a concrete implementation

// config.php
return [
    'adapters' => [
        'search'       => 'FtsSearch',       // default: SQLite FTS5 / MySQL FULLTEXT
        'queue'        => 'FileQueue',        // default: the jobs/ directory
        'payment'      => 'StripePayment',    // default: Stripe Checkout
        'storage'      => 'LocalStorage',     // default: the uploads/ directory
        'integration'  => null,               // default: none (standalone)
    ],
];
```

### 1. SearchAdapter — search

```php
interface SearchAdapter {
    public function index(array $product): void;           // add/update in the index
    public function remove(int $productId): void;          // remove from the index
    public function search(string $query, array $opts): SearchResult;
    public function suggest(string $prefix, int $limit): array;  // autocomplete
    public function reindexAll(): void;                    // full rebuild
}

// Default: FtsSearch (SQLite FTS5 / MySQL FULLTEXT)
// Optional: MeilisearchAdapter, TypesenseAdapter, AlgoliaAdapter
// Never: ElasticsearchAdapter
```

**Why:** FTS5/FULLTEXT handles 99% of shops. But if someone one day needs synonyms, typo tolerance or merchandising rules — they write an adapter for Meilisearch (lightweight, Rust, a single binary, not a JVM monster).

### 2. QueueAdapter — async operations

```php
interface QueueAdapter {
    public function push(string $type, array $payload, int $priority = 5): string; // → job ID
    public function pull(int $limit = 10): array;         // take jobs for processing
    public function complete(string $jobId): void;
    public function fail(string $jobId, string $error): void;
    public function retry(string $jobId): void;
    public function stats(): array;                       // pending/processing/failed counts
}

// Default: FileQueue (the jobs/ directory — flat JSON files)
// Optional: DbQueue (a jobs table in the DB — better for multi-node)
// Optional: RedisQueue (if Redis is already running)
```

**Why:** the file queue is perfect for single-node. You add DbQueue when you have 2+ servers. Redis if you already have it. But the core code does not change.

### 3. PaymentAdapter — payments

```php
interface PaymentAdapter {
    public function createSession(Order $order): PaymentSession;  // → redirect URL / client secret
    public function handleWebhook(array $payload): WebhookResult; // process the callback
    public function refund(string $paymentId, int $amount): RefundResult;
    public function getStatus(string $paymentId): string;
}

// Default: StripePayment (Stripe Checkout — hosted flow)
// Shipped: PayUPayment, GoPayPayment, ComgatePayment, ThePayPayment, CashOnDelivery, MockPayment; bank transfer is a built-in offline method.
// GoPay notifications are unsigned (GET ?id=): PullVerifiedNotificationAdapter rebuilds the event from an authenticated inquiry.
// Comgate pushes (JSON or form) are checked against merchant+secret, then the event comes from the authenticated status call.
// ThePay notifications are unsigned (GET ?project_id&payment_uid): the event comes from the signed payment detail call.
// Further gateways = a new class implementing PaymentAdapter.
```

**Why:** payments are the most sensitive part. A provider-hosted flow (redirect to the provider) is the easiest to implement and the hardest to mess up. Webhooks go through the same interface.

### 4. StorageAdapter — media and files

```php
interface StorageAdapter {
    public function store(string $path, string $contents): string;  // → public URL
    public function delete(string $path): void;
    public function url(string $path, string $variant = 'medium'): string;  // thumb/medium/full
    public function resize(string $path, int $width, int $height): string;
}

// Default: LocalStorage (the uploads/ directory + GD resize)
// Optional: S3Storage, CloudflareR2Storage, FtpStorage
```

**Why:** the local filesystem is enough for 90% of shops. But if you have a CDN or a multi-node deploy, you swap it for S3 without changing code.

### 5. IntegrationAdapter — ERP, CRM, accounting

```php
interface IntegrationAdapter {
    // Product sync
    public function pullProducts(?\DateTimeInterface $since = null): iterable;
    public function pushProduct(array $product): void;

    // Order sync
    public function pushOrder(Order $order): string;     // → external ID
    public function pullOrderStatus(string $externalId): string;

    // Stock sync
    public function pullStock(): array;                  // [{sku, qty}, ...]

    // Customer sync (optional)
    public function pushCustomer(array $customer): string;

    // Connection health
    public function healthCheck(): bool;
}

// Default: null (standalone shop, no integration)
// Optional: MoneyErpAdapter, PohodaAdapter, FlexiBeeAdapter
//           WooCommerceAdapter (import from an existing shop)
//           CsvFileAdapter (import/export through CSV)
```

### Example: MoneyErpAdapter

```php
// adapters/MoneyErpAdapter.php
class MoneyErpAdapter implements IntegrationAdapter {
    private PDO $erp;  // direct DB connection to Money S3/S4/S5

    public function __construct(array $config) {
        // Money ERP uses Firebird / MS SQL / MySQL
        $this->erp = new PDO($config['erp_dsn'], $config['erp_user'], $config['erp_pass']);
    }

    public function pullProducts(?\DateTimeInterface $since = null): iterable {
        $sql = "SELECT
                    s.ID, s.Kod AS sku, s.Nazev AS name, s.Popis AS description,
                    s.ProdejniCena AS price, sz.MnozstviSklad AS stock,
                    sk.Nazev AS category
                FROM sSkladoveKarty s
                LEFT JOIN sSkladoveZasoby sz ON sz.SkladovaKarta_ID = s.ID
                LEFT JOIN sSkupinyZbozi sk ON sk.ID = s.SkupinaZbozi_ID
                WHERE s.Aktivni = 1";

        if ($since) {
            $sql .= " AND s.DatumZmeny >= :since";
        }

        $stmt = $this->erp->prepare($sql);
        if ($since) $stmt->execute([':since' => $since->format('Y-m-d H:i:s')]);
        else $stmt->execute();

        while ($row = $stmt->fetch()) {
            yield [
                'sku'         => $row['sku'],
                'name'        => $row['name'],
                'description' => $row['description'] ?? '',
                'price'       => (int)($row['price'] * 100),  // to cents
                'stock'       => (int)$row['stock'],
                'category'    => $row['category'] ?? 'uncategorized',
                'external_id' => $row['ID'],
            ];
        }
    }

    public function pushOrder(Order $order): string {
        // Creates an order/invoice in Money
        $this->erp->beginTransaction();
        // INSERT into sObjednavkyPrijate + sObjednavkyPrijatePolozky
        // Mapping: order.items → Money items by SKU
        $this->erp->commit();
        return $externalId;
    }

    public function pullStock(): array {
        $stmt = $this->erp->query("
            SELECT sk.Kod AS sku, COALESCE(SUM(sz.MnozstviSklad), 0) AS qty
            FROM sSkladoveKarty sk
            LEFT JOIN sSkladoveZasoby sz ON sz.SkladovaKarta_ID = sk.ID
            WHERE sk.Aktivni = 1
            GROUP BY sk.Kod
        ");
        return $stmt->fetchAll();
    }

    public function healthCheck(): bool {
        try { $this->erp->query('SELECT 1'); return true; }
        catch (\Throwable $e) { return false; }
    }
}
```

### Sync orchestration

```php
// cli/sync.php — cron job: */15 * * * * php cli/sync.php
class Sync {
    public static function run(): void {
        $adapter = self::getAdapter();
        if (!$adapter || !$adapter->healthCheck()) return;

        // 1. Pull products (delta sync since the last run)
        $lastSync = Cache::get('last_erp_sync');
        $since = $lastSync ? new \DateTime($lastSync) : null;

        $updated = 0;
        foreach ($adapter->pullProducts($since) as $product) {
            Import::upsertProduct($product);
            $updated++;
        }

        // 2. Pull stock
        $stockData = $adapter->pullStock();
        Products::bulkUpdateStock($stockData);

        // 3. Push new orders to the ERP
        $orders = Orders::unsyncedForErp();
        foreach ($orders as $order) {
            try {
                $externalId = $adapter->pushOrder($order);
                Orders::markSynced($order['id'], $externalId);
            } catch (\Throwable $e) {
                Orders::markSyncFailed($order['id'], $e->getMessage());
            }
        }

        // 4. Pull order states from the ERP
        $pendingSync = Orders::pendingStatusSync();
        foreach ($pendingSync as $order) {
            $status = $adapter->pullOrderStatus($order['external_id']);
            if ($status !== $order['erp_status']) {
                Orders::updateErpStatus($order['id'], $status);
            }
        }

        Cache::set('last_erp_sync', date('c'));

        // 5. Cache invalidation for changed products
        if ($updated > 0) {
            JobQueue::push('cache_rebuild', ['scope' => 'changed', 'since' => $since]);
        }
    }
}
```

---

## The hard parts of an e-shop — where quality is decided

### Order lifecycle — state machine

```
                      ┌──────────────┐
                      │   pending    │ ← order created
                      └──────┬───────┘
                             │ payment received (webhook)
                      ┌──────▼───────┐
                      │     paid     │
                      └──────┬───────┘
                             │ admin/ERP confirms
                 ┌───────────┼───────────┐
                 │           │           │
          ┌──────▼──┐ ┌─────▼────┐ ┌────▼──────┐
          │ shipped │ │processing│ │ cancelled │
          └────┬────┘ └─────┬────┘ └────┬──────┘
               │            │           │ → refund
          ┌────▼─────┐ ┌───▼────┐ ┌────▼──────┐
          │delivered  │ │shipped │ │ refunded  │
          └────┬─────┘ └───┬────┘ └───────────┘
               │           │
               └─────┬─────┘
                     │ the customer wants to return it
               ┌─────▼──────┐
               │  returned   │
               └─────┬──────┘
                     │ processed
               ┌─────▼──────┐
               │  refunded   │
               └─────────────┘
```

```php
// Allowed transitions — you can never click into a wrong state
class OrderWorkflow {
    private const TRANSITIONS = [
        'pending'    => ['paid', 'cancelled'],
        'paid'       => ['processing', 'shipped', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped'    => ['delivered', 'returned'],
        'delivered'  => ['returned'],
        'returned'   => ['refunded'],
        'cancelled'  => ['refunded'],
        'refunded'   => [],  // final state
    ];

    public static function canTransition(string $from, string $to): bool {
        return in_array($to, self::TRANSITIONS[$from] ?? []);
    }

    public static function transition(int $orderId, string $to, ?string $note = null): void {
        $order = Orders::byId($orderId);
        if (!self::canTransition($order['status'], $to)) {
            throw new \LogicException("Cannot transition from {$order['status']} to {$to}");
        }

        Db::get()->beginTransaction();
        try {
            Orders::updateStatus($orderId, $to);
            AuditLog::log('order_status', $orderId, $order['status'], $to, $note);

            // Side effects
            match($to) {
                'cancelled' => self::handleCancellation($order),
                'refunded'  => self::handleRefund($order),
                'shipped'   => self::handleShipped($order),
                default     => null,
            };

            Db::get()->commit();
        } catch (\Throwable $e) {
            Db::get()->rollBack();
            throw $e;
        }
    }

    private static function handleCancellation(array $order): void {
        // Return the stock
        foreach (Orders::items($order['id']) as $item) {
            Products::incrementStock($item['variant_id'] ?? $item['product_id'], $item['quantity']);
        }
        // If there was a payment, initiate a refund
        if ($order['payment_id']) {
            JobQueue::push('payment_refund', [
                'order_id'   => $order['id'],
                'payment_id' => $order['payment_id'],
                'amount'     => $order['total'],
            ]);
        }
    }

    private static function handleRefund(array $order): void {
        $payment = Adapters::payment();
        $result = $payment->refund($order['payment_id'], $order['total']);
        Orders::setRefundId($order['id'], $result->refundId);
        JobQueue::push('email_send', [
            'template' => 'refund-confirmation',
            'to'       => $order['customer_email'],
            'data'     => $order,
        ]);
    }

    private static function handleShipped(array $order): void {
        JobQueue::push('email_send', [
            'template' => 'shipping-notification',
            'to'       => $order['customer_email'],
            'data'     => $order,
        ]);
        // Sync to the ERP
        if (Adapters::integration()) {
            JobQueue::push('erp_order_update', ['order_id' => $order['id'], 'status' => 'shipped']);
        }
    }
}
```

The current `OrderWorkflow::transition()` runs `transitionWithin()` in its own transaction and pushes the deferred jobs only after the commit; the external API calls `transitionWithin()` inside its Idempotency-Key transaction so a retried request changes the order once.

### Audit log — who did what and when

```sql
CREATE TABLE audit_log (
    id         INTEGER PRIMARY KEY,
    entity     TEXT NOT NULL,      -- 'order', 'product', 'customer'
    entity_id  INTEGER NOT NULL,
    action     TEXT NOT NULL,      -- 'status_change', 'price_change', 'stock_update'
    old_value  TEXT,
    new_value  TEXT,
    note       TEXT,
    actor      TEXT NOT NULL,      -- 'admin:peter', 'system:webhook', 'cron:sync'
    ip_address TEXT,
    created_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX idx_audit_entity ON audit_log(entity, entity_id, created_at);
CREATE INDEX idx_audit_actor ON audit_log(actor, created_at);
```

```php
class AuditLog {
    public static function log(string $entity, int $entityId, $old, $new, ?string $note = null): void {
        $db = Db::get();
        $stmt = $db->prepare("
            INSERT INTO audit_log (entity, entity_id, action, old_value, new_value, note, actor, ip_address)
            VALUES (:entity, :eid, :action, :old, :new, :note, :actor, :ip)
        ");
        $stmt->execute([
            ':entity' => $entity,
            ':eid'    => $entityId,
            ':action' => self::detectAction($old, $new),
            ':old'    => is_array($old) ? json_encode($old) : (string)$old,
            ':new'    => is_array($new) ? json_encode($new) : (string)$new,
            ':note'   => $note,
            ':actor'  => self::currentActor(),
            ':ip'     => Security::clientIp(),
        ]);
    }

    private static function currentActor(): string {
        if (php_sapi_name() === 'cli') return 'cron:' . basename($_SERVER['SCRIPT_NAME']);
        if (!empty($_SERVER['PHP_AUTH_USER'])) return 'admin:' . $_SERVER['PHP_AUTH_USER'];
        if (!empty($_SERVER['HTTP_X_WEBHOOK_SOURCE'])) return 'webhook:' . $_SERVER['HTTP_X_WEBHOOK_SOURCE'];
        return 'system';
    }
}
```

### Idempotency — webhook safe, import safe, order safe

```php
class Idempotency {
    // A Stripe webhook can arrive 2x, 5x, 10x — it must be idempotent
    public static function webhookProcessed(string $eventId): bool {
        $db = Db::get();
        // Try to insert — if it already exists, return true (already processed)
        try {
            $stmt = $db->prepare("INSERT INTO processed_events (event_id, created_at) VALUES (:id, datetime('now'))");
            $stmt->execute([':id' => $eventId]);
            return false; // a new event, it must be processed
        } catch (\PDOException $e) {
            return true;  // a duplicate, skip it
        }
    }

    // Importing the same CSV twice → UPSERT, not duplicates
    // → handled through ON CONFLICT(sku) in Import.php

    // Order created but the payment has not arrived yet → an idempotent state
    // → handled through OrderWorkflow::canTransition()
}
```

```sql
CREATE TABLE processed_events (
    event_id   TEXT PRIMARY KEY,     -- Stripe event ID, webhook delivery ID
    created_at TEXT DEFAULT (datetime('now'))
);
-- Cleanup: DELETE FROM processed_events WHERE created_at < datetime('now', '-7 days')
```

### Payment webhooks — correct handling

```php
// api.php?action=webhook_stripe
public static function handleStripeWebhook(): void {
    // 1. Signature verification (Stripe signing secret)
    $payload = file_get_contents('php://input');
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $secret = $GLOBALS['config']['stripe_webhook_secret'];

    if (!self::verifyStripeSignature($payload, $sigHeader, $secret)) {
        http_response_code(400);
        exit('Invalid signature');
    }

    $event = json_decode($payload, true);

    // 2. Idempotency — have we processed this already?
    if (Idempotency::webhookProcessed($event['id'])) {
        http_response_code(200);
        exit('Already processed');
    }

    // 3. Processing by event type
    match($event['type']) {
        'checkout.session.completed' => self::onPaymentCompleted($event['data']['object']),
        'charge.refunded'            => self::onRefund($event['data']['object']),
        'charge.dispute.created'     => self::onDispute($event['data']['object']),
        default => null,  // ignore unknown events
    };

    // 4. Always return 200 (even if processing fails — Stripe would retry)
    http_response_code(200);
}

private static function onPaymentCompleted(array $session): void {
    $orderId = $session['metadata']['order_id'] ?? null;
    if (!$orderId) return;

    $order = Orders::byId((int)$orderId);
    if (!$order || $order['status'] !== 'pending') return;

    OrderWorkflow::transition((int)$orderId, 'paid', 'Stripe webhook: ' . $session['id']);
    AuditLog::log('order', (int)$orderId, 'pending', 'paid', 'Payment: ' . $session['payment_intent']);

    // Async: e-mails, ERP sync
    JobQueue::push('email_send', ['template' => 'order-confirmation', 'order_id' => $orderId]);
    JobQueue::push('email_send', ['template' => 'admin-notification', 'order_id' => $orderId]);
    if (Adapters::integration()) {
        JobQueue::push('erp_order_push', ['order_id' => $orderId]);
    }
}
```

### Admin permissions — role-based

```json
// In config.php or admin-roles.json
{
    "roles": {
        "owner": {
            "permissions": ["*"]
        },
        "manager": {
            "permissions": ["products.*", "categories.edit", "orders.*", "customers.view", "customers.edit", "reports.view", "gdpr.view", "import", "cache",
                            "payments.manage", "shipping.manage", "rma.manage"]
        },
        "editor": {
            "permissions": ["products.edit", "products.view", "categories.edit", "media.upload"]
        },
        "fulfillment": {
            "permissions": ["orders.view", "orders.edit", "orders.ship", "orders.print"]
        },
        "viewer": {
            "permissions": ["products.view", "orders.view", "reports.view"]
        }
    },

    "users": {
        "peter":   {"role": "owner", "password_hash": "$2y$..."},
        "manager": {"role": "manager", "password_hash": "$2y$..."},
        "sklad":   {"role": "fulfillment", "password_hash": "$2y$..."}
    }
}
```

Money has its own permissions that neither `products.edit` nor `orders.edit` includes:
`orders.payments` (manual change/confirmation of a payment), `orders.refund` (refund, the `refunded` state, RMA → credit note),
`payments.manage` and `shipping.manage` (methods and fees), `rma.manage` (returns and complaints). `orders.*` includes
both `orders.payments` and `orders.refund`; custom roles in `admin-roles.json` must list them explicitly.

---

## Why not Magento / WooCommerce / Elasticsearch

### Attack surface comparison

| Component | Magento | BareBones Shop |
|---|---|---|
| PHP framework | Zend/Laminas (~500k LoC) | None (2k LoC of own code) |
| JavaScript | RequireJS + KnockoutJS + jQuery | Vanilla JS (~600 lines) |
| Database | MySQL + Redis + Elasticsearch | SQLite or MySQL (1 service) |
| Extra services | Varnish, RabbitMQ, Elasticsearch | None |
| Admin panel | Huge, hundreds of routes | 5 pages, HTTP Basic |
| Composer dependencies | 200+ packages | 0 |
| Cron jobs | Dozens | 0-1 (optional cache rebuild) |
| Known CVEs (2020-2025) | 50+ critical | N/A (no framework = no CVEs in third-party code) |

### Elasticsearch — a typical attack vector

Elasticsearch in Magento is notoriously problematic:

1. **No authentication by default** — it runs on port 9200, often reachable from the internet
2. **Remote code execution** — CVE-2021-22145, CVE-2021-44228 (Log4Shell), CVE-2023-31419
3. **Data exfiltration** — an attacker reads the whole product index + potentially customer data
4. **Resource exhaustion** — crafted queries cause OOM, a DoS of the whole server
5. **No isolation** — Magento + ES run under the same user, compromising ES = compromising the shop

**BareBones Shop eliminates this completely.** Full-text search is a built-in database feature (FTS5/FULLTEXT), not an external service with its own network port.

### The philosophical difference

```
Magento:   1 thing is done by 50 services → each one is attack surface
BareBones: 1 thing is done by 1 PHP + 1 database → 2 attack surfaces

Less code = fewer bugs = fewer vulnerabilities = less maintenance.
```

---

## Images — WebP/AVIF, lazy loading, responsive

### Automatic conversion on upload

```php
// In StorageAdapter::store() or as an async job
// CAUTION: runs in the CLI worker, not in a web request!
class ImageProcessor {
    // An iPhone photo 4000x3000 = 48MB RAM after decompression (4000*3000*4 bytes RGBA)
    // A modern DSLR 8000x6000 = 192MB RAM
    private const MAX_DIMENSION = 8000;   // reject larger ones
    private const MAX_FILESIZE = 20_000_000; // 20MB

    public static function process(string $sourcePath, string $filename): array {
        // Memory guard — only for the CLI worker, not for the web
        if (php_sapi_name() === 'cli') {
            ini_set('memory_limit', '512M');
        }

        // Size guard
        if (filesize($sourcePath) > self::MAX_FILESIZE) {
            throw new \RuntimeException('Image too large: ' . filesize($sourcePath) . ' bytes');
        }
        [$origW, $origH] = getimagesize($sourcePath);
        if ($origW > self::MAX_DIMENSION || $origH > self::MAX_DIMENSION) {
            throw new \RuntimeException("Image dimensions {$origW}x{$origH} exceed limit");
        }

        $variants = [];
        $sizes = ['thumb' => 150, 'medium' => 600, 'full' => 1200];
        $formats = ['webp', 'jpg']; // WebP primary, JPG fallback

        foreach ($sizes as $name => $maxDim) {
            foreach ($formats as $fmt) {
                $outFile = pathinfo($filename, PATHINFO_FILENAME) . ".{$fmt}";
                $outPath = __DIR__ . "/../uploads/{$name}/{$outFile}";

                $img = imagecreatefromstring(file_get_contents($sourcePath));
                [$w, $h] = getimagesize($sourcePath);
                $ratio = min($maxDim / $w, $maxDim / $h, 1);
                $newW = (int)($w * $ratio);
                $newH = (int)($h * $ratio);
                $resized = imagecreatetruecolor($newW, $newH);
                imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);

                match($fmt) {
                    'webp' => imagewebp($resized, $outPath, 85),
                    'avif' => function_exists('imageavif') ? imageavif($resized, $outPath, 60) : null,
                    'jpg'  => imagejpeg($resized, $outPath, 85),
                };
                imagedestroy($resized);
                $variants["{$name}_{$fmt}"] = $outFile;
            }
        }
        imagedestroy($img);
        return $variants;
    }
}
```

### Frontend — a `<picture>` element with a fallback

```html
<!-- In product-card.html and product-gallery.html -->
<picture>
    <source srcset="/uploads/medium/{{image_webp}}" type="image/webp">
    <img src="/uploads/medium/{{image_jpg}}" alt="{{name}}" loading="lazy"
         width="600" height="600" decoding="async">
</picture>
```

### Soft deletes for products

A product is never deleted from the DB. Status `archived` = invisible in the shop, but the URL returns alternatives.

```php
// product.php — an archived product → 410 Gone + related products
$product = Products::bySlug($slug);
if (!$product) { http_response_code(404); exit; }
if ($product['status'] === 'archived') {
    http_response_code(410); // Gone — a signal to Google that the page was removed
    $related = Products::related($product['category_id'], 8);
    echo Template::render('product/archived', [
        'message' => I18n::t('product_discontinued'),
        'related' => $related,
    ]);
    exit;
}
```

### CLI helper — hash-password

```php
// cli/hash-password.php
// Usage: php cli/hash-password.php "mypassword"
$password = $argv[1] ?? null;
if (!$password) { echo "Usage: php hash-password.php <password>\n"; exit(1); }
echo password_hash($password, PASSWORD_ARGON2ID) . "\n";
```

---

## What is in the core vs what are modules

Everything from the feature matrix is included. The split into the core and optional modules:

### Core (always present)

- Multi-store, multi-language, multi-currency
- Product types through catalog.json
- Variants (configurable products)
- Categories (tree, unlimited depth)
- Full-text search (FTS5/FULLTEXT)
- Faceted filtering
- Cart + checkout (BBF)
- Orders + status workflow
- Price rules (catalog + cart rules)
- Taxes per country
- Shipping (methods + zones)
- Async job queue
- CSV import/export
- Admin panel (BBF-driven)
- SEO (SSR, JSON-LD, sitemap, slugs per locale)
- Security (CSRF, rate limit, CSP, brute-force protection)

### Optional modules (enabled in config.php)

| Module | State | Note |
|---|---|---|
| Wishlist | V2 | localStorage + session sync |
| Reviews/ratings | V2 | Moderation in the admin |
| Customer accounts | V2 | Login, order history, saved addresses |
| B2B tier pricing | V1 | Already in pricing.json |
| Coupons | V1 | Already in pricing.json cart_rules |
| Image CDN | V2 | S3/Cloudflare adapter |
| API auth (JWT/API key) | V2 | For a mobile app / headless |
| Webhooks | V2 | Event → HTTP POST to an external system |
| Elasticsearch | **Never** | FTS5/FULLTEXT is enough. No extra port, no attack surface. |

---

## Getting started (build sequence)

Multiple languages and multiple stores are in every phase. They are not features — they are parameters
that everything flows through from the start.

```
Phase 1: Foundation + schema
  [ ] config.php — DB, stores, languages
  [ ] catalog.json — product types + attributes
  [ ] stores.json — stores + languages + currencies
  [ ] layouts.json — page layouts + blocks
  [ ] cli/schema-build.php — JSON → SQL + admin forms + import templates
  [ ] Db.php, Security.php, I18n.php — database, security, languages
  [ ] Import.php — load a test CSV (multi-language)
  [ ] lang/*.php — UI translations (reused from BBF)

Phase 2: Storefront (multi-language from the first request)
  [ ] Template.php — engine + the blocks/ directory
  [ ] api.php — products, categories, search, cart
  [ ] shop.js — catalog, filters, cart, locale switcher
  [ ] product.php — SSR detail with JSON-LD (per locale)
  [ ] Cache.php — flat cache per locale, stale-while-revalidate
  [ ] .htaccess — rewrite + Level 0 cache

Phase 3: Cart + payments
  [ ] Cart.php — session + localStorage dual persistence
  [ ] forms/checkout.json — BBF checkout form
  [ ] Orders.php + OrderWorkflow.php — orders, stock, audit
  [ ] Stripe (reused from BBF) + webhook handler + idempotency
  [ ] E-mail templates (reused from BBF)

Phase 4: Admin + worker
  [ ] admin/ — CRUD (auto-generated BBF forms), orders, role-based auth
  [ ] JobQueue.php + cli/worker.php — cache rebuild, e-mails, image resize
  [ ] AuditLog.php — who did what and when

Phase 5: Benchmark + hardening
  [ ] seed.php — 1M products × 3 languages × 2 stores
  [ ] Benchmark: listing, search, filter, checkout (validating the performance targets)
  [ ] Security audit: injection, XSS, CSRF, rate limit, webhook replay
```

---

## Invoices — InvoiceService (Roadmap 1.2)

PDF invoices for paid orders, legally usable in the EU (SK/CZ/PL/HU/DE/EN), with atomic numbering, data snapshots and a signed download token.

### Principle

- **Immutability** — an issued invoice never changes; a correction = `cancelled_at` + a new invoice (a cancelled + a new document).
- **Snapshots** — `customer_snapshot` and `seller_snapshot` are JSON copies at the time of issue. A change of the customer's address does not affect a historical invoice.
- **Atomic numbering** — `invoice_counters(year, prefix, last_number)` with `UPDATE last_number=last_number+1` in a transaction. A backstop `UNIQUE` constraint on `invoices.number`.
- **Forward-only** — no automatic regeneration. Backfill with `php cli/invoices-backfill.php --dry-run` for legacy paid orders.

### Schema

```sql
CREATE TABLE invoices (
  id INTEGER PRIMARY KEY,
  order_id INTEGER NOT NULL,
  number TEXT NOT NULL UNIQUE,             -- "20260001" or "SK-20260001"
  issued_at TEXT NOT NULL,
  delivery_date TEXT NOT NULL,
  due_date TEXT NOT NULL,
  pdf_path TEXT NOT NULL,                  -- relative: storage/invoices/2026/04/20260001.pdf
  total_net INTEGER NOT NULL,
  total_vat INTEGER NOT NULL,
  total_gross INTEGER NOT NULL,
  currency TEXT NOT NULL,
  vat_breakdown TEXT NOT NULL,             -- JSON {"25":{"net":8400,"vat":2100}}
  customer_snapshot TEXT NOT NULL,         -- JSON frozen
  seller_snapshot TEXT NOT NULL,           -- JSON frozen
  payment_method TEXT,
  locale TEXT,
  status TEXT NOT NULL DEFAULT 'issued',
  download_token TEXT NOT NULL UNIQUE,     -- 64 hex (32 bytes)
  cancelled_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY (order_id) REFERENCES orders(id)
);
CREATE TABLE invoice_counters (
  year INTEGER NOT NULL,
  prefix TEXT NOT NULL DEFAULT '',
  last_number INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (year, prefix)
);
```

### Per-store configuration (`stores.json`)

```json
{
  "stores": {
    "sk": {
      "seller": {
        "name": "Woodle s.r.o.",
        "street": "Hlavná 1", "city": "Bratislava", "zip": "811 01", "country": "SK",
        "ico": "12345678", "dic": "1020304050", "ic_dph": "SK1020304050",
        "iban": "SK00 1100 0000 0012 3456 7890", "bank": "Tatra banka",
        "email": "info@example.sk", "phone": "+421 2 1234 5678",
        "register_note": "OR OS BA, oddiel Sro, vložka 12345/B"
      },
      "legal": { "payment_terms_days": 14, "invoice_prefix": "" }
    }
  }
}
```

### Generation flow

```
OrderWorkflow::transition(orderId, 'paid')
  └─ handlePaid(order) → push job invoice_issue priority=3 AFTER the commit
       └─ worker handleInvoiceIssue($payload)
            ├─ InvoiceService::issue($orderId)              ← idempotent
            │    ├─ allocateNumber() in a tx (counters → UPDATE)
            │    ├─ snapshots of customer + seller from stores.json
            │    ├─ INSERT invoices
            │    ├─ render the PDF (MinimalPdf) AFTER the commit
            │    └─ return $invoice
            └─ MailService::send('invoice_issued', email, locale, vars,
                                 orderId, [['filename'=>{number}.pdf,
                                            'content_type'=>'application/pdf',
                                            'data'=>$pdfBytes]])
```

### Reverse charge (B2B EU)

`InvoiceService::isReverseCharge($seller, $customer)` returns true iff:
- seller.country and customer.country are both in `EU_COUNTRIES`
- they are different countries
- the customer has a non-empty `ic_dph`

V1 renders only a text placeholder (the reverse-charge note, "VAT is paid by the customer", in the correct locale). The full VAT/OSS logic is handled by a separate mission.

### Customer download (signed token)

```
GET account.php?p=invoice&token=<64-hex>
  ├─ validation: strlen===64 && ctype_xdigit
  ├─ InvoiceService::byToken(token) → DB lookup
  ├─ if null or cancelled_at !== null → 404
  └─ readfile(pdfAbsolutePath); Content-Disposition: attachment
```

The endpoint is registered **BEFORE** the auth gate in `account.php` — the token is the credential, a login is not required.

### Admin

- `admin.php?p=order&id=N` — the "Invoices" section: a list, a "PDF" link, "Resend invoice email", "Issue invoice" (a manual trigger if the job failed).
- `admin.php?p=invoice_pdf&id=N` — protected by `orders.view` (including the fulfillment role), served before the admin HTML layout, so the response contains only the PDF bytes; it streams the PDF inline for preview.
- MinimalPdf encodes text into CP1252 through iconv (including `€` = 0x80); an invoice with a long list of items continues on the next page with a repeated table header (the body ends at 278 mm, the footer at 285 mm).

### Backfill

```
php cli/invoices-backfill.php --dry-run                   # preview
php cli/invoices-backfill.php                             # really issue
php cli/invoices-backfill.php --status=paid --limit=100   # batch
```

Idempotent through `LEFT JOIN invoices i ON i.order_id = o.id WHERE i.id IS NULL`. Cancelled invoices count as "not existing" — the order can get another new one.

### Storage

- `storage/invoices/{YYYY}/{MM}/{number}.pdf` — outside the webroot, gitignored except `.gitkeep`.
- The PDF is written **AFTER** the DB commit — orphan files are acceptable, orphan DB rows are not.
- Nginx/Apache must block direct requests to `/storage/`.

---

## Tax / VAT compliance — VatService (Roadmap 1.3)

An EU-compliant VAT engine: per-line snapshot, history-aware rates, B2B reverse charge,
OSS for cross-border B2C, VIES validation, OSS report. Branch `feat/tax-vat`.

### Components

| File | Purpose |
|---|---|
| `config/vat-rates.json` | 27 EU countries × classes (standard/reduced/reduced2/zero) with `valid_from`+`history` (SK 23 % from 2025-01-01, history 20 %; CZ 12 %, history 15 %; FI 25.5 %, EE 22 %, LU 17 %, …) |
| `src/VatService.php` | Engine: `rate()`, `resolveScenario()`, `computeLine()`, `buildSnapshot()`, `validateSnapshot()`, `validateVatId()`, `resolveClass()`, `isEuCountry()` |
| `adapters/TaxProvider.php` | The canonical contract for the rate, the scenario and the immutable financial snapshot |
| `adapters/VatIdValidator.php` | Adapter interface (`check(vatId)`) |
| `adapters/contrib/MockVatIdValidator.php` | A deterministic mock for tests/dev |
| `adapters/contrib/ViesVatIdValidator.php` | VIES SOAP client (5 s timeout, graceful unverified) |
| `cli/oss-report.php` | Quarterly OSS report (`--quarter=2026-Q2 --format=text|csv|json`) |
| `cli/vat-backfill.php` | Legacy order backfill (`--dry-run` default, `--apply` with an automatic pre-backup) |
| `migrations/20260425013405_add_vat_columns.php` | Schema migration (17 columns + the `vat_id_cache` table) |
| `migrations/20260801141000_financial_snapshots.php` | Nullable `orders.financial_snapshot` and `invoices.financial_snapshot` for the new canonical flow |

### Schema (migration `add_vat_columns`)

| Table | Added |
|---|---|
| `products` | `vat_class TEXT NOT NULL DEFAULT 'standard'` |
| `order_items` | `vat_class`, `vat_rate REAL`, `vat_amount INTEGER`, `net_amount INTEGER` |
| `orders` | `vat_scenario TEXT`, `vat_total INTEGER`, `net_total INTEGER`, nullable `financial_snapshot TEXT` |
| `customers` | `vat_id TEXT`, `vat_country TEXT`, `vat_validated_at TEXT` |
| `invoices` | `type` (`invoice`\|`credit_note`), `refers_to_invoice_id`, `vat_scenario`, `reverse_charge_note_key`, nullable `financial_snapshot TEXT` |
| `invoice_counters` | `type` (a sequence per type) |
| `vat_id_cache` (NEW) | `vat_id`, `country`, `status` (`valid`/`invalid`/`unverified`), `trader_name`, `checked_at`, `ttl_seconds` (24h valid/invalid, 1h unverified) |
| `stores.json` | per-store `oss_enabled: false` (opt-in) |

### Reverse-charge resolver (5 scenarios)

`VatService::resolveScenario(ctx)` returns `{scenario, vat_rate, applies_vat, note_key, rate_country}`:

| Scenario | Condition | Rate country | applies_vat | note_key |
|---|---|---|---|---|
| `domestic` | seller_country == customer_country | seller | true | — |
| `reverse_charge` | B2B intra-EU + a valid VAT ID | customer | false | `vat.reverse_charge_note` |
| `oss` | cross-border B2C, EU, store `oss_enabled=true` | customer | true | — |
| `domestic_subthreshold` | cross-border B2C, EU, OSS off | seller | true | — |
| `export` | customer outside the EU | customer | false | `vat.export_note` |

### Canonical financial snapshot (gross-inclusive)

After the coupon, shipping and payment are chosen, `VatService::buildSnapshot()` freezes the scenario, the rate date, the currency, the products, the deterministically allocated discount, shipping, the payment fee, the breakdown and the totals. Amounts are integer cents, rates are integer basis points and the rounding is `gross_line_half_up_integer_basis_points`; a SHA-256 `fingerprint` protects the whole canonically ordered content.

`Orders::create()` verifies the fingerprint and that the snapshot matches the actually reserved subtotal, currency, discount, shipping and fee. The invoice copies exactly the same JSON and the payment request carries its fingerprint. New orders are no longer recalculated downstream; legacy rows with `financial_snapshot IS NULL` stay readable through the original invoice fallback.

### VIES cache (TTL-aware)

`VatService::validateVatId(vatId, validator)`:
1. Lookup in `vat_id_cache` → if `age < ttl_seconds`, return the cached result (`cached=true`)
2. Otherwise call `validator->check()` → upsert into the cache with the right TTL (`valid`/`invalid`=86400, `unverified`=3600)
3. Service down/timeout → `unverified` with a short TTL → retry-friendly

### OSS report

`php cli/oss-report.php --quarter=YYYY-Qn --format=text|csv|json` — group by `(consumption_country, vat_rate, currency)` only for `vat_scenario=oss` invoices. The source is the immutable `invoices.financial_snapshot.breakdown` (including the allocated discount and shipping), and for a historical `NULL` the stored `invoices.vat_breakdown`; the report never computes from current rates or from raw `order_items`. Domestic is excluded. Deterministic ordering. An empty period → exit 1.

### Backfill (legacy orders)

`php cli/vat-backfill.php` (dry-run by default) → `--apply` triggers a pre-backup through BackupService, recomputes VAT from the current rates and fills `orders.vat_scenario`/`vat_total`/`net_total` + `order_items.vat_*` based on `customers.vat_id` + the address.

### Gotchas

- **The whole financial calculation is a snapshot at order creation** — a later change of `config/vat-rates.json`, coupons, shipping or payment fees does not affect the order, the payment, the invoice or OSS. A fingerprint mismatch is a hard failure.
- **VAT ID cache TTL**: 24 h for `valid`/`invalid`, 1 h for `unverified` — so that a retry happens sooner.
- **VIES timeout = unverified**, not a B2C fallback. The operator must verify it manually in the admin.
- **OSS opt-in per store** — the counter (the €10,000 threshold) is P1; in P0 the operator tracks it themselves.
- **Reverse charge needs both**: `is_b2b=true` AND `customer_vat_id_valid=true`. A single field is not enough (it protects against a false B2B claim).

### Summary

| Metric | Value |
|---|---|
| Number of PHP files | ~30 |
| Total PHP code | Estimate ~6,000 lines (validate after Phase 2) |
| Frontend JS | ~800 lines |
| HTML templates (blocks) | ~30 files |
| JSON definitions | 6 files (catalog, stores, layouts, pricing, shipping, tax) |
| Adapter interfaces | 5 (search, queue, payment, storage, integration) |
| External dependencies | 0 (except Stripe JS for payments) |
| Database | SQLite (default) or MySQL/MariaDB |
| Languages | Unlimited (BBF lang system) |
| Product types | Unlimited (catalog.json) |
| Max products (target) | 1,000,000+ (validate in the Phase 5 benchmark) |
| Response time (target) | <5ms (cache), <20ms (DB) — benchmark before committing |
| Attack surface | PHP + 1 database. No Elasticsearch, Redis, Varnish, RabbitMQ. |
| Scope | Core B2C commerce (catalog, cart, checkout, orders, multi-language) |
| Magento code | ~2,000,000 lines |
| **BareBones code** | **~6,000 lines** |
| Extension points | 5 adapter interfaces, swappable without changing the core |
| ERP integration | Adapter pattern (Money, Pohoda, FlexiBee, custom) |
| Deploy | Copy the directory + config.php + schema:build. Done. |
