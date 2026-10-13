# BareBones Shop — Design Document

> Core B2C commerce bez Magento-level complexity.
> Pokrýva 80% reálnych potrieb e-shopov. Zvyšných 20% vedome vynecháva.

---

## Čo toto JE a čo NIE JE

**JE:** Lightweight commerce engine pre malé až stredné B2C shopy. JSON-driven konfigurácia,
pre-computed katalóg, SSR, multi-jazyk, vymeniteľné adaptéry na 5 miestach.

**NIE JE:** Náhrada Magenta pre enterprise s desiatkami integrácií, stovkami admin rolí
a custom business logikou. Nie je to "Magento, len menšie". Je to iný prístup k inej množine problémov.

### Explicitné trade-offy a hranice

| Rozhodnutie | Výhoda | Cena |
|---|---|---|
| Žiadny framework | Čitateľnosť, rýchlosť, žiadne cudzie CVE | Vlastníš celý auth, validation, escaping sám. Menej kódu ≠ menej zodpovednosti. |
| JSON konfigurácia | Generator pattern, žiadny GUI na schému | Zmena schémy = versioned build + backfill kroky. Sú to migrácie pod iným menom — priznávame to. |
| MySQL-first, SQLite pre dev/small | Široká kompatibilita | Dva backendy = dva profily výkonu, dva testovacia matice. Nie je to "jeden switch". |
| Filesystem job queue | Nulové závislosti, jednoduchosť | Single-node only. Business-critical operácie (order processing) idú synchronne v request, nie cez queue. |
| Pre-computed cache | Bleskový TTFB na čítanie | Cache invalidácia pri viacerých store×jazyk×pricing group môže byť drahá. Treba presne vedieť čo precomputovať. |
| Žiadny Elasticsearch | Žiadna extra útočná plocha | FTS5/FULLTEXT nemá synonymá, typo tolerance, merchandising rules. Stačí pre shopy bez pokročilého search UX. SearchAdapter umožňuje výmenu. |
| 83 668 PHP LoC baseline | Malý runtime bez frameworku | Reprodukovateľný G14 baseline zahŕňa 299 versioned-candidate PHP súborov vrátane migrácií a rozsiahlej test suite; historický odhad ~6 000 LoC bol nepravdivý. |

### Performance tvrdenia

Historické čísla v návrhových ukážkach (napr. <5 ms, <20 ms alebo 1M) sú **neoverené ciele, nie výsledky**. Autoritatívny nameraný stav je iba v sekcii „Nameraný performance a failure baseline“; 100k meranie viaceré staré ciele vyvrátilo a 1M profil zostáva nekvalifikovaný.

---

## Princípy

1. **JSON-driven generator pattern** — catalog.json definuje product types, atribúty, filtre. Z toho sa generujú SQL tabuľky, admin formuláre, import šablóny. Zmena schémy = versioned build s migračnými krokmi.
2. **Pre-computed kde to dáva zmysel** — kategóriové listingy a produktové detaily sa predpočítajú do flat JSON. Dynamické operácie (košík, checkout, search) idú cez PHP+DB. Nie "predpočítaj všetko".
3. **Multi-jazyk v jadre** — BBF má 32 jazykov. Shop rovnako. Preklady natívne, nie plugin.
4. **Async pre non-critical operácie** — Cache rebuild, emaily, image resize, import. Business-critical operácie (order+payment) sú synchronné v request. Filesystem queue pre single-node, DbQueue adaptér pre multi-node.
5. **MySQL/MariaDB primárne, SQLite pre dev/small** — MySQL je produkčný default. SQLite pre dev, demo a malé shopy. Nie sú identické — rôzne FTS, rôzny locking, rôzne failure modes. Oboje testované, ale MySQL má prednosť pri rozhodovaní.
6. **Bezpečnosť = vlastná zodpovednosť** — Prepared statements, CSRF, rate limiting, CSP. Žiadny framework neznamená menej bezpečnostnej práce — znamená to viac. Vlastníme celý auth a validation stack.
7. **Žiadna mágia, žiadne ilúzie** — Čitateľný kód. Jasné hranice. Explicitné trade-offy.

---

## V1 scope

Multi-lang a multi-store sú v jadre od prvého dňa. Nie ako "V2 feature" — ako základ.
BBF má 32 jazykov v základe. Shop bude mať rovnako. Lepiť jazyky a store na existujúci
single-lang kód je vždy ťažšie než to navrhnúť správne od začiatku.

`StoreContext` sa rieši raz na request podľa explicitného key, domény alebo najdlhšieho URL prefixu; doména má prioritu. `id` vlastní objednávky, cache, platobné transakcie, shipping labely a faktúry. Voliteľné `catalog_scope` a `provider_scope` umožňujú viacerým marketom bezpečne zdieľať katalóg a payment/shipping konfiguráciu; bez overrideov je nový store izolovaný. Migrácia priraďuje všetky legacy dáta literálnemu `default` store a job queue prenáša store/channel do workerov. Rollback updatera obnoví predmigračný databázový snapshot.

`Adapters::registry()` mapuje stabilné provider codes na súbežné payment, shipping, ERP, mail a tax adaptéry, ich capabilities a aktívny `provider_scope`. Checkout zmrazí zvolené kódy vo fingerprintovanom finančnom snapshot-e; payment session, webhook, refund, expiry, mail a ERP worker potom resolvujú pôvodný provider, nie aktuálny globálny default. Legacy `adapters.payment` a `adapters.integration` ostávajú fallbackom pre existujúce inštalácie.

`Outbox::record()` zapisuje verziované order/payment/stock/customer/product udalosti iba v otvorenej doménovej transakcii. Worker ich doručuje cez config-only endpointy ako CloudEvents-compatible envelope, s HMAC podpisom viazaným na timestamp, event ID a presný raw body; úspešné endpointy sa pri retry neopakujú a vyčerpané udalosti končia v auditovateľnom dead-letter stave. Samostatné `/api/v1` nepoužíva browser session ani CSRF: bearer credential je store-bound, DB obsahuje iba jeho hash, scopes sú explicitné a mutácie vyžadujú per-client idempotency key. `docs/openapi.yaml` je verejný additive-first kontrakt DTO, cursor pagination a webhook delivery.

`Setup::doctor()` vracia verziovaný machine-readable onboarding checklist a `apply` idempotentne vytvorí iba bezpečné private runtime cesty; deployment config má JSON Schema so secret anotáciami. `/healthz` je side-effect-free liveness, `/readyz` overuje DB/store/runtime závislosti bez úniku detailov a `Logger` zapisuje redigované JSON Lines s correlation/store/channel kontextom. Generický `HttpShipping` používa retry-safe rates/pickup/label/cancel/tracking kontrakt a `HttpErpAdapter` reálne vykonáva order create/status update, produkty, zákazníkov, sklad aj health cez HTTPS; fixture testy dokazujú kontrakt bez predstierania live credentials.

Katalóg zachováva `products.category_id` ako spätne kompatibilnú primárnu kategóriu a `product_categories` pridáva ďalšie zaradenia. Brand/manufacturer, GTIN, MPN, integer-cent cost, rozmery, variantové médiá a časová dostupnosť sú normalizované polia. DB cenník sa resolvuje podľa store, currency, customer group, variantu, množstva, validity a priority; zvolený cenník a promotion kódy sa zmrazia v `_pricing` order-item snapshot-e pred kanonickým finančným snapshotom. Promotions sú prioritné `exclusive` alebo `stack` pravidlá a podporujú katalógovú cenu, free shipping, buy-X-get-Y a explicitné gift entitlement bez tichého stock pohybu. B2B `companies` a `customer_groups` sú store-scoped; invoice purchase je dostupný iba schválenej aktívnej firme v rámci credit limitu a quote zmrazí splatnosť aj company ID.

`stock_movements` je append-only audit každej store-scoped fyzickej a rezervačnej zmeny s delta aj resulting balance. Produkty a varianty majú explicitnú `deny`/`backorder`/`preorder` politiku; bundle order line zmrazí komponenty, ich sklady a personalization údaje bez zmeny finančného riadku. Supplier products vynucujú minimum/pack size, PO receipt je idempotentný a `shipments` + `shipment_items` umožňujú čiastkové warehouse fulfillmenty. Exact reserve/release/ship guardy a shipment-aware cancellation bránia oversellu, dvojitému restore aj uvoľneniu už odoslaného tovaru.

Return operácie sú samostatná auditovateľná vrstva nad objednávkou: viac otvorených čiastkových RMA zdieľa quantity cap, return label a každý receipt majú idempotency identity a prijatý kus dostane explicitný `restock`/`discard`/`repair` výsledok. Exchange rezervuje iba prijatú, ešte necreditovanú quantity. `InvoiceService::credit()` nevypočítava historické ceny znova; gross/net/VAT segmentuje z pôvodného fingerprintovaného financial snapshotu po integer centoch, vytvorí immutable credit note + item refund batch a payment worker pri čiastke mení iba payment lifecycle, nie celú order lifecycle.

### V1 — kompletný commerce engine

**Katalóg + storefront:**
- Produkty, kategórie, varianty, vlastné product types (catalog.json)
- Multi-store, multi-jazyk, multi-currency (stores.json) — od dňa 1
- Listing: paginácia, sort, faceted filtre (FTS-based)
- Search: FTS5/FULLTEXT
- Detail produktu: SSR, JSON-LD, obrázky (WebP)
- Template engine: bloky, sekcie, layouts.json
- Flat-file cache per locale (Level 0 + Level 1)

**Košík + checkout:**
- Session + localStorage dual persistence
- Checkout: BBF formulár, Stripe platby
- Objednávky: vytvorenie, atomický stock, email potvrdenie
- Order workflow: stavový automat, audit log

**Admin:**
- Produkty CRUD (BBF-driven formuláre per product type)
- Objednávky: view + zmena statusu
- Import/export CSV
- Role-based auth, audit log

**Infraštruktúra:**
- MySQL/MariaDB primárne, SQLite pre dev
- Job queue (file-based) pre cache rebuild, emaily, image resize
- Stabilné capability kontrakty pre search, queue, payment, shipping, mail, tax, storage, backup, ERP, PIM a WMS
- Security: CSRF, CSP, rate limiting, idempotencia

### V2 — rozšírenia

- Pricing rules (catalog + cart), kupóny, B2B tier pricing
- Shipping zones + tax rules z JSON
- ERP adapter (Money, Pohoda)
- 2FA pre admin
- Customer accounts
- Webhook endpoint management UI nad existujúcim outboxom
- DbQueue pre multi-node

---

## Architektúra

```
┌─────────────────────────────────────────────────────────┐
│  JSON definície (srdce systému)                         │
│  catalog.json — product types, atribúty, filtre         │
│  stores.json  — multi-store, jazyky, meny               │
│  pricing.json — cenové pravidlá, zľavy, B2B             │
│  shipping.json — metódy, zóny, sadzby                   │
│  tax.json     — daňové pravidlá per krajina             │
└──────────────────┬──────────────────────────────────────┘
                   │ schema:build (pregeneruje SQL + cache)
                   ▼
┌─────────────────────────────────────────────────────────┐
│  Pre-computed flat cache                                │
│  cache/store/{locale}/cat/{slug}/page-{n}.json          │
│  cache/store/{locale}/product/{slug}.json               │
│  cache/store/{locale}/categories.json                   │
│  cache/filters/{category}.json                          │
│  cache/prices/{customer_group}.json                     │
└──────────────────┬──────────────────────────────────────┘
                   │ servované priamo (.htaccess / nginx)
                   ▼
┌─────────────────────────────────────────────────────────┐
│  Browser (vanilla JS)                                   │
│  - Katalóg, filtre, vyhľadávanie                       │
│  - Košík (localStorage + session sync)                  │
│  - Checkout → BareBonesForms                            │
└──────────────────┬──────────────────────────────────────┘
                   │ dynamické operácie (košík, checkout, search)
                   ▼
┌─────────────────────────────────────────────────────────┐
│  PHP API vrstva (api.php)                               │
│  - Cache-first: flat file → DB fallback                 │
│  - CSRF, rate limiting, input sanitizácia               │
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
│  Job Queue (jobs/ adresár)                              │
│  - cache rebuild, emaily, image resize, import          │
│  - cron alebo long-running worker.php                   │
│  - žiadny RabbitMQ, len flat files                      │
└─────────────────────────────────────────────────────────┘
```

### Entry point a extension boundaries (G14 baseline)

Verejné `admin.php`, `index.php`, `api.php` a CLI `cli/test.php` sú iba 6/6/6/5-riadkové bootstrapy. HTTP implementácie sú pod webovo zakázaným `src/Entrypoints`; JSON API navyše oddeľuje 88-riadkový request lifecycle/router od `src/Http/ApiHandlers.php`. `cli/TestSuite.php` drží existujúcu sekvenčnú fixture suitu, zatiaľ čo `cli/test.php` ostáva stabilný príkaz. Source regresie skladajú celý bundle, takže presun kódu neoslabuje CSP, checkout ani permission guardy.

Reprodukovateľný baseline príkaz `git ls-files --cached --others --exclude-standard "*.php"` má v tomto stave 299 PHP súborov a 83 668 riadkov vrátane migrácií a testov; samotná historická test suite má 7 058 riadkov. Čísla nie sú marketingovým odhadom a musia sa pri ďalšom delení aktualizovať.

`Adapters::registry()` je additive-only provider boundary. `IntegrationAdapter` zachováva legacy ERP kompatibilitu, ale skladá menšie `ErpAdapter`, `PimAdapter` a `WmsAdapter`; samostatné PIM/WMS providery sú voliteľné a bez konfigurácie zostávajú vypnuté. Payment, shipping, mail a tax používajú existujúce úzke rozhrania. Verejné transakčné udalosti sú pomenované v `DomainEvents` a doručované iba cez `Outbox`, nie cez všeobecný plugin lifecycle.

Dev-only `php cli/static-analysis.php` parsuje PHP zdroje natívnym parserom aktívnej PHP verzie, zakazuje `eval`, vynucuje entrypoint budgety a overuje module/event/adapter kontrakty. CI ho spúšťa na PHP 8.1, 8.2 a 8.3; produkčný runtime preto nezískava Composer, framework ani novú službu.

---

## JSON-driven katalógová schéma — srdce systému

Rovnaký princíp ako BBF: definuješ JSON → engine z toho generuje všetko.
V BBF: form JSON → HTML formulár + validácia + submit pipeline.
V Shop: catalog JSON → SQL tabuľky + admin formuláre + filtre + import šablóny.

### catalog.json — definícia produktových typov

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

### Čo sa z toho vygeneruje (schema:build)

Keď spustíš `php cli/schema-build.php`:

1. **SQL tabuľky** — vytvoria sa cez `Migrator` (forward-only migrácie v `migrations/`).
   Schema-build interne volá `Migrator::apply()` — žiadne ďalšie ad-hoc ALTER.
2. **Admin formuláre** — pre každý product type sa vygeneruje BBF JSON form na editáciu
3. **Import šablóny** — CSV hlavičky pre každý product type
4. **Filter konfigurácia** — `cache/filters/{category}.json` — aké filtre zobrazovať
5. **Validačné pravidlá** — required, type checking, options whitelist

### Migrations runtime

- **Vývoj / fresh DB:** `php cli/schema-build.php` aplikuje všetky pending migrácie
  od baseline po HEAD. Identické s `php cli/migrate.php`.
- **Existujúca produkcia (v1.4.0):** raz spusti `php cli/migrate.php baseline` —
  označí baseline migráciu ako aplikovanú **bez spustenia** (DB už má všetky tabuľky).
  Následné `migrate` aplikujú iba nové migrácie.
- **Nová migrácia:** `php cli/migrate.php create add_xyz_column` → vyrobí
  `migrations/<TS>_add_xyz_column.php` so stub `up(PDO, bool isSqlite)`.
- **Forward-only.** Žiadne `down()`. Rollback = nová migrácia.
- **Bezpečnosť:** flock na `db/.migrations.lock` (10 s timeout), per-migration
  `BEGIN/ROLLBACK`, hard fail pri checksum mismatch (file edited po apply).

### Backup & Restore (Roadmap 1.6)

`BackupService` (online SQLite backup) + `cli/backup.php` + `cli/restore.php`.

- **Mechanizmus:** `PRAGMA wal_checkpoint(TRUNCATE)` → `VACUUM INTO 'storage/backups/YYYY/MM/DD/eshop-YYYYMMDD-HHMMSS.db'`. Atomický, defragmentovaný, WAL-safe, bez externých nástrojov.
- **Layout:** `storage/backups/YYYY/MM/DD/eshop-*.db` + sidecar `*.meta.json` (timestamp, size, sha256, schema_version, label, trigger, compressed). Audit log: `storage/backup.log` (10 MB × 5 rotations).
- **Konfigurácia:** `config/backup.json` — `enabled`, `compression`, `retention {daily, weekly, monthly, minimum_kept}`, `auto_pre_migration`, `auto_gc_on_backup`, `wal_checkpoint`. Storage adapter: `LocalBackupStorage` (default), interface `BackupStorage` umožňuje S3 / off-site neskôr.
- **Concurrency:** flock na `storage/backups/.backup.lock` (10 s timeout), rovnaké API ako Migrator.
- **Idempotencia:** sub-sekundové kolízie filename → `-1`, `-2`, … suffix counter.
- **Compression:** OFF default (`VACUUM INTO` už defragmentuje). Opt-in cez `--gz` flag alebo `compression: true` → `*.db.gz` sidecar.
- **GC retention:** grandfather-father-son (7 daily / 4 weekly / 6 monthly), `minimum_kept: 3` chráni pred GC-vyprázdnením pri prvotnom rozbehu.
- **Migrator pre-hook:** ak `auto_pre_migration: true`, `cli/migrate.php` pred apply pendingu zavolá `BackupService::create(['label' => 'pre-migration'])`. Override: `--skip-backup`. Hook throw → migrácia sa nespustí (table marker NOT inserted).
- **Manual:** `php cli/backup.php [--gc] [--gz] [--label=<text>] [--quiet]`. Cron friendly. Default label je `scheduled` (podlieha GC retencii); labely `manual`, `pre-migration` a `pre-restore-snapshot` GC nikdy nemaže — `--label=manual` len pre zálohy, ktoré sa majú držať natrvalo.
- **Oprávnenia:** zálohy obsahujú osobné údaje — `LocalBackupStorage` zapisuje `.db`/`.meta.json` s `0600` a adresáre s `0700` (umask-nezávisle; na Windows sa POSIX bity neuplatňujú, test ich vynecháva explicitným SKIP). Update SQLite snapshot je tiež `0600`.
- **GDPR GC:** `php cli/gdpr-gc.php --apply` najprv inicializuje `BackupService` a uloží `pre-retention-gc` snapshot; ak snapshot nejde (zálohy vypnuté, MySQL), odmietne s exit 3. Po externej zálohe (napr. mysqldump) `--apply --skip-backup`.
- **Restore:** `php cli/restore.php <remote_path> [--force] [--no-snapshot]`.
  - **sha256 verify** proti meta JSON (tamper detection — hard fail).
  - `PRAGMA integrity_check` pred swapom — hard fail pri corrupt backup.
  - **Pre-restore snapshot** (`label=pre-restore-snapshot`) — safety net (max 5 retained); presne ten istý kód path ako manual backup, ale beží mimo restore lock-u (žiaden re-entrant deadlock).
  - **Atomic swap** — copy → temp → `rename()` cez živý súbor. Windows: `Db::reset()` + 3× × 100 ms retry pri lock-u; po 3 retry abort s clear error message.
  - **schema_migrations mismatch** po restore → warning (NIE hard fail). `count(applied) < count(files)` → "⚠️ Restored DB has older schema. Run: php cli/migrate.php (N pending)". Reverse situácia (DB má migrácie ktoré v kóde nie sú) → "⚠️ Restored DB has migrations not in current code."
- **Backup commands typical flow:**
  ```bash
  php cli/backup.php --label=before-major-import   # manual snapshot
  php cli/backup.php --gc                          # cron: daily backup + GC
  php cli/migrate.php                              # auto-backup pred apply
  php cli/restore.php 2026/04/25/eshop-20260425-143012.db
  ```
- **Gotcha:** výmena živého SQLite súboru pod otvoreným spojením na POSIX potichu stratí ďalšie commity toho procesu (píše do odlinkovaného inode). `BackupService::restore()` aj `Updater::rollback()` (SQLite snapshot) preto bežia cez `SqliteMaintenance::exclusive()`: vytvorí `<db>.maintenance` (kým existuje, `Db::get()` hodí `DatabaseMaintenanceException`), zavrie vlastné spojenia na ten súbor a prepnutím WAL→DELETE dokáže, že DB nedrží žiadny iný proces. Ak je DB do 15 s stále otvorená (worker, web request), restore/rollback sa **odmietne bez zmeny** súborov aj kódu — **dlho-bežiace workery treba stopnúť** a zopakovať. Obmedzenie: spojenie otvorené mimo `Db` v režime DELETE sa detegovať nedá.

```
catalog.json
    │
    ├──→ db/generated-schema.sql     (CREATE TABLE, CREATE INDEX)
    ├──→ import/clothing-template.csv
    ├──→ import/electronics-template.csv
    └──→ cache/filter-config.json    (frontend filter definície)
```

**Zmeníš catalog.json → spustíš build → hotovo** pre väčšinu zmien.
Ale priznávame: zmena schémy (nový atribút, zmena typu) = migrácia pod iným menom. Build tool to musí riešiť: diff medzi starou a novou schémou, ALTER/backfill kroky, rollback scenár.

### Porovnanie s Magento EAV

```
Magento:
  Admin klikne "Add Attribute" → uloží sa do eav_attribute
  → 6 EAV tabuliek sa JOINujú pri KAŽDOM query
  → flat indexer pregeneruje flat tabuľku (async, zlyháva)
  → Elasticsearch reindexuje (async, zlyháva)
  → Varnish cache invalidácia (async, zlyháva)
  = 4 async kroky, každý môže zlyhať

BareBones:
  Edituješ catalog.json → spustíš schema:build
  → Build diffne starú vs novú schému
  → Generuje ALTER + backfill SQL (migrácia)
  → Spustí migráciu + cache rebuild
  = Stále jednoduchšie než Magento, ale nie "žiadne migrácie"
  = Na produkciu treba versioning a rollback plán
```

---

## Multi-jazyk — natívne, nie plugin

BBF má 32 jazykov. Shop bude mať rovnako.

### stores.json — multi-store + multi-lang konfigurácia

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

### Ako sa ukladajú preklady

**Nie EAV. Nie extra tabuľka per typ.** Jeden stĺpec, jeden JSON:

```sql
-- Produkty — base jazyk + preklady v jednom riadku
CREATE TABLE products (
    id          INTEGER PRIMARY KEY,
    sku         TEXT UNIQUE NOT NULL,
    slug        TEXT NOT NULL,                -- base slug
    name        TEXT NOT NULL,                -- default jazyk (napr. "sk")
    summary     TEXT DEFAULT '',
    description TEXT DEFAULT '',
    -- ... ostatné stĺpce ...

    -- Preklady: {"en": {"name": "...", "summary": "..."}, "de": {...}}
    translations TEXT DEFAULT '{}'
);

-- Kategórie — rovnaký princíp
CREATE TABLE categories (
    id   INTEGER PRIMARY KEY,
    slug TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL,                       -- default jazyk
    translations TEXT DEFAULT '{}'            -- {"en": {"name": "..."}, "de": {...}}
);
```

### Použitie v kóde

```php
class I18n {
    private static string $locale = 'sk';

    public static function setLocale(string $locale): void {
        self::$locale = $locale;
    }

    // Preloží produkt — fallback na default jazyk
    public static function product(array $row): array {
        if (self::$locale === $GLOBALS['config']['default_locale']) {
            return $row; // base jazyk, žiadny preklad
        }

        $tr = json_decode($row['translations'] ?? '{}', true);
        $localeData = $tr[self::$locale] ?? [];

        // Preloží len to, čo existuje; zvyšok zostane v base jazyku
        foreach (['name', 'summary', 'description'] as $field) {
            if (!empty($localeData[$field])) {
                $row[$field] = $localeData[$field];
            }
        }

        // Slug per jazyk (pre SEO)
        if (!empty($localeData['slug'])) {
            $row['slug'] = $localeData['slug'];
        }

        return $row;
    }

    // Preloží atribút z catalog.json definície
    public static function attr(string $attrName, string $value): string {
        // Lookup v catalog.json options prekladoch
        static $catalog = null;
        if (!$catalog) $catalog = json_decode(file_get_contents(__DIR__ . '/../catalog.json'), true);

        // Hľadaj v product_types → attributes → options
        // Fallback na hodnotu samú
        // ... (cache-ované pri prvom volaní)
        return $value; // fallback
    }

    // UI preklady (tlačidlá, labels) — BBF lang systém
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
├── sk/                          # slovenský store
│   ├── categories.json
│   ├── cat/oblecenie/tricka/
│   │   ├── page-1.json          # produkty s SK názvami/popismi
│   │   ├── page-2.json
│   │   └── filters.json         # filtre s SK labelmi
│   └── product/
│       └── cierne-tricko.json   # SK slug, SK obsah
├── en/
│   ├── categories.json
│   ├── cat/clothing/t-shirts/
│   │   ├── page-1.json          # produkty s EN názvami
│   │   └── filters.json         # filtre s EN labelmi
│   └── product/
│       └── black-t-shirt.json   # EN slug, EN obsah
└── de/
    └── ...
```

**Výsledok:** Pre každý jazyk existuje kompletný flat cache. Žiadny runtime preklad, žiadny JOIN. Apache servuje správny JSON podľa URL prefixu.

### URL štruktúra

```
example.com/product/cierne-tricko           → SK (default, bez prefixu)
example.com/en/product/black-t-shirt        → EN
example.com/de/product/schwarzes-t-shirt    → DE

example.com/category/oblecenie/tricka       → SK
example.com/en/category/clothing/t-shirts   → EN
```

### Slug uniqueness per locale

Dva produkty nesmú mať rovnaký slug v rovnakom locale. Keďže slugy sú v translations JSON,
validácia musí prebehnúť pri ukladaní.

```sql
-- Pomocná tabuľka pre slug uniqueness (generovaná pri build/import)
CREATE TABLE slug_registry (
    slug    TEXT NOT NULL,
    locale  TEXT NOT NULL,
    entity  TEXT NOT NULL,       -- 'product', 'category', 'cms'
    entity_id INTEGER NOT NULL,
    PRIMARY KEY (slug, locale, entity)
);

-- Pri uložení produktu/prekladu: skontroluj kolíziu
-- INSERT OR FAIL → ak slug+locale+entity existuje pre iné entity_id → chyba
```

```php
// V Import.php / admin save:
public static function validateSlug(string $slug, string $locale, string $entity, int $entityId): bool {
    $stmt = Db::get()->prepare("
        SELECT entity_id FROM slug_registry
        WHERE slug = :slug AND locale = :locale AND entity = :entity AND entity_id != :eid
    ");
    $stmt->execute([':slug' => $slug, ':locale' => $locale, ':entity' => $entity, ':eid' => $entityId]);
    return $stmt->fetch() === false; // true = voľný, false = kolízia
}
```

### Známe obmedzenia JSON translations stĺpca

- **Validácia:** Treba extra parsovanie pri ukladaní (nie len `json_encode` a hotovo)
- **Admin diffy:** Porovnávanie verzií prekladu nie je triviálne (riešenie: audit log zaznamenáva celý JSON)
- **Selektívne preklady:** "Preložiť všetko do DE" = query cez JSON stĺpec, pomalšie ako normalizovaná tabuľka
- **Migrácia pri rename polí:** Ak sa zmení štruktúra translations, treba backfill script

Pre V1 (1 jazyk) toto nie je problém. Pri V1.1 (multi-lang) treba tieto edge-casy vyriešiť.

---

## Template Engine + Layouty — bloky, sekcie, žonglovanie

### Filozofia

Magento má layout XML → bloky → .phtml šablóny. Je to mocné, ale nečitateľné.
Twig/Blade sú plnohodnotné enginy — dependency navyše, learning curve.

**BareBones prístup:**
- **JSON definuje layout** — čo kde je, v akom poradí, aký blok
- **HTML šablóny s `{{}}` syntaxou** — čistý HTML, žiadne PHP, žiadna špeciálna syntax
- **Vedome limitovaný engine** — partials, podmienky, cykly, bloky. Nepokrýva Magento edge-cases (block references, layout merging). Stačí na 80% e-shop layoutov.
- **Bloky sú znovupoužiteľné** — rovnaký blok na homepage, product page, CMS stránke

### Syntax — jednoduchá, mocná

```html
<!-- Premenné -->
{{name}}
{{product.price_formatted}}

<!-- Escapované (default) vs raw HTML -->
{{description}}              ← escapované (bezpečné)
{{{description_html}}}       ← raw HTML (pre rich text)

<!-- Podmienky -->
{{#if compare_price}}
    <span class="old-price">{{compare_price_formatted}}</span>
{{/if}}

{{#if stock > 0}}
    <span class="in-stock">{{t.in_stock}}</span>
{{else}}
    <span class="out-of-stock">{{t.out_of_stock}}</span>
{{/if}}

<!-- Cykly -->
{{#each variants}}
    <option value="{{sku}}" data-price="{{price_delta}}">{{name}}</option>
{{/each}}

{{#each images}}
    <img src="/uploads/medium/{{this}}" loading="lazy" alt="{{../name}}">
{{/each}}

<!-- Partials (vnorené šablóny) -->
{{> product-card}}
{{> breadcrumb}}
{{> pagination}}

<!-- Preklady (i18n) -->
{{t.add_to_cart}}
{{t.out_of_stock}}
{{t.showing_results count=total}}

<!-- Bloky (named slots — pre layout overrides) -->
{{#block "sidebar"}}
    <!-- default obsah, dá sa nahradiť v child layoute -->
    {{> category-filters}}
{{/block}}
```

### layouts.json — definícia stránkových layoutov

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

### Bloky — HTML šablóny v blocks/ adresári

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
│   ├── product-card.html      # karta v gridu (listing)
│   ├── product-gallery.html   # obrázky + zoom + thumbnaily
│   ├── product-info.html      # názov, cena, varianty, add-to-cart
│   ├── product-description.html
│   ├── product-attributes.html # tabuľka parametrov
│   ├── product-reviews.html
│   ├── product-tabs.html      # tab wrapper
│   ├── related-products.html
│   └── recently-viewed.html
│
├── category/
│   ├── category-header.html   # názov, popis, obrázok
│   ├── category-filters.html  # sidebar filtre (auto z catalog.json)
│   ├── active-filters.html    # vybrané filtre s "x" na odstránenie
│   ├── sort-bar.html          # sort dropdown + view toggle
│   └── product-grid.html      # grid/list wrapper
│
├── cart/
│   ├── cart-items.html        # zoznam položiek + qty úprava
│   ├── cart-summary.html      # subtotal, doprava, daň, total
│   └── cross-sell.html        # "mohlo by vás zaujímať"
│
├── checkout/
│   ├── checkout-form.html     # wrapper pre BBF form
│   └── order-summary.html     # rekapitulácia objednávky
│
├── homepage/
│   ├── hero-banner.html       # slideshow / hero obrázok
│   └── featured-categories.html
│
└── cms/
    └── cms-content.html       # markdown / HTML content
```

### Príklad bloku — product-card.html

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

### Príklad bloku — product-gallery.html

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

### Príklad bloku — category-filters.html

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

    <!-- Cenový range filter -->
    <div class="filters__group">
        <h4>{{t.price}}</h4>
        <div class="price-range" data-filter="price">
            <input type="number" name="price_min" value="{{price_min}}" placeholder="{{t.from}}" min="0">
            <span>—</span>
            <input type="number" name="price_max" value="{{price_max}}" placeholder="{{t.to}}">
        </div>
    </div>

    <!-- Atribútové filtre — auto-generované z catalog.json -->
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

### Template Engine — implementácia (~150 riadkov)

```php
class Template {
    private static string $blocksDir = __DIR__ . '/../blocks';
    private static array $cache = [];

    // ============================================================
    // Renderuj stránku z layouts.json definície
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
    // Renderuj blok (šablónu) s dátami
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

        // 2. Bloky: {{#block "name"}}default{{/block}}
        $tpl = preg_replace_callback(
            '/\{\{#block\s+"([^"]+)"\}\}(.*?)\{\{\/block\}\}/s',
            function($m) use ($data) {
                $name = $m[1];
                return $data['slots'][$name] ?? self::process($m[2], $data);
            }, $tpl
        );

        // 3. Podmienky: {{#if expr}}...{{else}}...{{/if}}
        $tpl = preg_replace_callback(
            '/\{\{#if\s+(.+?)\}\}(.*?)(?:\{\{else\}\}(.*?))?\{\{\/if\}\}/s',
            function($m) use ($data) {
                $condition = self::evaluate($m[1], $data);
                return self::process($condition ? $m[2] : ($m[3] ?? ''), $data);
            }, $tpl
        );

        // 4. Cykly: {{#each items}}...{{/each}}
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

        // Ak sekcia má data source, načítaj
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

### Ako sa to používa

```php
// product.php — celý renderovací kód
$product = Products::bySlug($slug);
$product = I18n::product($product);
$product = Pricing::apply($product);

echo Template::page('product', [
    'product'    => $product,
    'breadcrumb' => Categories::breadcrumb($product['category_id']),
    'related'    => Products::related($product['id'], 4),
    'reviews'    => Reviews::forProduct($product['id']),
    't'          => I18n::all(),    // všetky UI preklady
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

### Produkčné theme balíky

Store vyberá balík kľúčom `theme` v `stores.json`. Balík `themes/{code}/theme.json`
deklaruje semver verziu, CSS/JS assety a byte budgety; `blocks/` obsahuje iba
override bloky a `assets/` jediný verejný obsah balíka. `Template::activate()` sa
volá spolu so `StoreContext`, validuje názov balíka a asset paths, invaliduje cache
pri prepnutí store/theme a verziuje asset URL. Pri renderi sa hľadá store override,
potom `themes/{theme}/blocks`, legacy theme override a napokon base block.

`Template::document()` poskytuje jednotný SSR shell s header/main/footer landmarks,
skip linkom, DB navigáciou, aktívnymi theme assetmi, SEO head a consent-aware hookom.
`ContentService` viaže menu, časované bannery, landing pages a redirecty na aktívny
store; admin mutácie sú CSRF-chránené, autorizované, sanitizované a auditované.
`MarketingFeed` používa rovnaký store/catalog scope a integer-cent cenu pre Google
Merchant XML aj Meta CSV. CI spúšťa parity a `cli/quality-gates.php`, ktorý kontroluje
WCAG automatizovateľné invarianty a package performance budgety.

### Žonglovanie — prečo je to flexibilné

**1. Zmeniť poradie sekcií na product page:**
```json
// layouts.json — len prehoď riadky
"sections": [
    {"block": "breadcrumb"},
    {"block": "product-tabs", ...},        ← presunuté hore
    {"block": "product-detail", ...},
    {"block": "related-products"}
]
```

**2. Pridať nový blok:**
```
Vytvor blocks/product/size-guide.html → pridaj do layouts.json → hotovo.
```

**3. Iný layout pre inú kategóriu:**
```json
"pages": {
    "category": { "layout": "two-column", ... },
    "category:electronics": { "layout": "two-column-left", ... },
    "category:sale": { "layout": "one-column", ... }
}
```

**4. Úplne iná homepage pre iný store:**
```json
"pages": {
    "homepage":    { ... },              // default
    "homepage:de": { ... },              // nemecký store — iné sekcie, iný layout
    "homepage:us": { ... }               // US store
}
```

**5. CMS stránky — admin vytvorí v JSON:**
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

### Porovnanie s Magento layout XML

```xml
<!-- Magento: product page layout override (nečitateľné) -->
<page xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
      xsi:noNamespaceSchemaLocation="urn:magento:framework:View/Layout/etc/page_configuration.xsd">
    <body>
        <referenceContainer name="content">
            <block class="Magento\Catalog\Block\Product\View"
                   name="product.info.main"
                   template="Magento_Catalog::product/view/main.phtml">
                <container name="product.info.price" as="product_price">
                    <block class="Magento\Catalog\Pricing\Render" name="product.price.final">
                        <!-- ... 50 ďalších riadkov XML ... -->
                    </block>
                </container>
            </block>
        </referenceContainer>
    </body>
</page>
```

```json
// BareBones: to isté
{"block": "product-detail", "children": [
    {"block": "product-gallery", "slot": "main"},
    {"block": "product-info", "slot": "sidebar"}
]}
```

**Pokrýva 80% use-casov. Nemá Magento edge-case handling (block references, move operations, layout merging). Vedome limitovaný — jednoduchosť > úplnosť.**

### Dedičnosť a overrides

```
blocks/
├── product/
│   └── product-card.html              ← base šablóna
│
├── themes/
│   └── dark/
│       └── product/
│           └── product-card.html      ← override pre dark tému
│
└── stores/
    └── de/
        └── product/
            └── product-card.html      ← override pre nemecký store
```

Template engine hľadá v poradí:
1. `blocks/stores/{store}/` — store-specific override
2. `blocks/themes/{theme}/` — theme override
3. `blocks/` — base

Prvý nájdený sa použije. Rovnaký princíp ako Magento fallback, ale bez XML konfigu.

### Princíp: konfigurovateľná fronta (file / database / Redis)

```
jobs/
├── pending/                     # nové joby
│   ├── 1710234567_cache_rebuild_cat_oblecenie.json
│   ├── 1710234568_email_order_confirm_ORD-123.json
│   └── 1710234569_image_resize_product_456.json
├── processing/                  # práve sa spracovávajú
├── completed/                   # hotové (auto-cleanup po 24h)
└── failed/                      # zlyhané (pre debug)
```

### Job formát

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

### Worker — cron alebo dlhobežiaci proces

```php
// cli/worker.php — spusti cez cron (každú minútu) alebo ako daemon

// Exkluzívny lock — vždy len 1 worker (ochrana pred race conditions)
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
        // Zoraď podľa priority + timestamp
        usort($pending, fn($a, $b) => self::jobPriority($a) <=> self::jobPriority($b));

        foreach ($pending as $file) {
            $job = json_decode(file_get_contents($file), true);
            $handler = self::$handlers[$job['type']] ?? null;
            if (!$handler) { self::fail($file, $job, 'Unknown job type'); continue; }

            // Presuň do processing (atomické rename)
            $procFile = str_replace('/pending/', '/processing/', $file);
            rename($file, $procFile);

            try {
                call_user_func($handler, $job['payload']);
                // Úspech → presuň do completed
                rename($procFile, str_replace('/processing/', '/completed/', $procFile));
            } catch (\Throwable $e) {
                $job['attempts']++;
                $job['last_error'] = $e->getMessage();
                if ($job['attempts'] >= $job['max_attempts']) {
                    // Definitívne zlyhanie
                    file_put_contents(
                        str_replace('/processing/', '/failed/', $procFile),
                        json_encode($job, JSON_PRETTY_PRINT)
                    );
                    unlink($procFile);
                } else {
                    // Retry — vráť do pending
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

### Kedy sa joby vytvárajú

```
Admin uloží produkt     → job: cache_rebuild (category + product)
                         → job: search_reindex (product)
                         → job: image_resize (ak nový obrázok)

Admin uloží kategóriu    → job: cache_rebuild (category tree + listings)

Objednávka sa vytvorí    → job: email_send (confirmation)
                         → job: email_send (admin notify)
                         → job: stock_alert (ak low stock)

Bulk import              → job: import_process (celý CSV)
                         → po dokončení: cache_rebuild (full)

Cron (denne)             → job: sitemap_build
                         → job: cart_abandonment (košíky > 24h s emailom → pripomienka)
                         → job: cleanup (completed jobs, expired sessions)
```

### Porovnanie s Magento

```
Magento:
  RabbitMQ/MySQL message queue → consumers (PHP procesy)
  → indexer:reindex (5-15 rôznych indexerov)
  → cache:flush (Redis, Varnish, built-in)
  → Elasticsearch reindex
  = Komplexná infraštruktúra, desiatky pohyblivých častí

BareBones:
  file: JSON v jobs/pending/ → worker.php (1 proces, bez závislostí)
  database: queue_jobs → atomický claim + lease (viac workerov)
  Redis: sorted sets + Lua claim/complete/fail (viac workerov)
  → rovnaký JobQueue kontrakt a handler funkcie
```

File backend používa atomický `rename()` a procesný lock. Database a Redis backend
ukladajú store/channel/correlation kontext, retry stav a dead jobs; lease token bráni
dvomi workerom dokončiť rovnaký claim. Backend sa volí cez `queue.backend` bez zmeny
producerov alebo handlerov. Prepnutie backendu vyžaduje najprv vyprázdniť alebo
reconcileovať starú frontu — joby sa medzi backendmi automaticky nekopírujú.

Worker, ktorý počas jobu zomrie (fatal error, OOM, kill), nezavolá `fail()`. Po vypršaní
lease (database/Redis `lease_seconds`, file backend 300 s od claimu v `processing/`) sa
taký beh počíta ako neúspešný pokus: job ide späť na retry, a po `max_attempts` (3) do
`failed` (dead letter) s chybou „lease expired“. Job, ktorý opakovane zhadzuje workera,
sa tak neopakuje donekonečna a file backend neuviazne v `processing/`.

---

## Cenové pravidlá, zľavy, B2B — pricing.json

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

### Pre-computed ceny per customer group

```
cache/
├── prices/
│   ├── guest.json           # {"TSHIRT-BLK-L": 1990, ...}
│   ├── wholesale.json       # {"TSHIRT-BLK-L": 1692, ...}  (15% off)
│   └── vip.json             # {"TSHIRT-BLK-L": 1791, ...}  (10% off)
```

### Rebuild vs Runtime — jasné mantinely

| Typ pravidla | Kedy sa počíta | Prečo |
|---|---|---|
| Customer group zľava (%) | **Rebuild** | Statická, nemení sa per request |
| Catalog rules (sezónne akcie) | **Rebuild** | Dátumový rozsah, deterministické |
| Tier pricing (qty-based) | **Runtime** (checkout) | Závisí od qty v košíku |
| Kupón/zľavový kód | **Runtime** (checkout) | Závisí od user inputu |
| Shipping cost | **Runtime** (checkout) | Závisí od adresy + váhy |
| Tax | **Runtime** (checkout) | Závisí od krajiny zákazníka |

**Pravidlo:** Ak výsledok závisí len od produktu a customer group → rebuild.
Ak závisí od obsahu košíka alebo user inputu → runtime.
Toto zabraňuje cache explózii pri raste pravidiel.

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

## Feature matica — BareBones vs Magento

### V1 (jadro)

| Feature | Magento | BareBones V1 | Ako | Obmedzenia |
|---|---|---|---|---|
| Multi-store | Áno (komplexné) | Áno | stores.json, URL prefix | Max ~5 stores testovaných |
| Multi-jazyk | Áno (store views) | Áno | translations JSON + BBF lang | — |
| Multi-currency | Áno | Áno | stores.json, pre-computed ceny | Manuálne exchange rates |
| Product types | 6 built-in | Vlastné | catalog.json | Treba definovať sám |
| Varianty | Áno | Áno | variant_dimensions | — |
| Bundle | Áno | Áno | bundle_components, Inventory | Bez Magento grouped (výber podproduktov) |
| Digital/downloadable | Áno | Áno | physical: false | Bez DRM |
| Faceted filtering | Elasticsearch | DB-based | product_attrs + filter_tags | Bez synonymov, typo tolerance |
| Full-text search | Elasticsearch | FTS5/FULLTEXT | Built-in v DB | Bez merchandising rules |
| Catalog price rules | Áno | Áno | pricing.json | Jednoduchšie ako Magento |
| Kupóny | Áno | Áno | pricing.json cart_rules | Základné (%, absolútna) |
| B2B tier pricing | Áno | Áno | pricing.json | — |
| Tax per country | Áno | Áno | tax.json | Bez per-state (US) v V1 |
| Shipping | Áno | Áno | shipping.json + carrier adaptéry (HttpShipping, Packeta, GLS) | Live rates len cez adaptér s getRates |
| Order management | Áno (komplex) | Áno | Status workflow + audit log, Fulfillment (čiastočné zásielky), Rma (vratky/refundy) | — |
| Customer accounts | Áno | Áno | account.php, Customers | — |
| Reviews / Wishlist | Áno | Áno | Reviews, Wishlist | Moderácia recenzií v admine |
| 2FA pre admin | Áno | Áno | AdminIdentity + TOTP | — |
| Webhooky (outbound) | Áno | Áno | Outbox + worker | — |
| Multi-warehouse | Áno (MSI) | Áno | Warehouse, inventory ledger | — |
| Email templates | Áno | Áno | BBF template systém | — |
| SEO (SSR, JSON-LD) | Plugin | Built-in | product.php SSR | — |
| Import/Export CSV | Áno | Áno | Auto-generated | — |
| Admin panel | Áno (obrovský) | Minimalistický | BBF-driven formuláre | Session-based auth, role-based |
| Async operácie | RabbitMQ | File/DB queue | Non-critical only | Single-node (file), multi-node (DB adapter) |

### Vedome MIMO scope V1

| Feature | Prečo | V2 plán |
|---|---|---|
| A/B testing, personalization | Enterprise feature | Mimo scope |
| Magento grouped products | Bundle pokrýva bežné prípady | Podľa dopytu |
| Konkrétne UPS/DHL integrácie | Generický HttpShipping kontrakt stačí | Adapter |

Pôvodne sem patrili aj zákaznícke účty, recenzie, wishlist, live carrier rates, čiastočné zásielky/refundy, 2FA pre admin, outbound webhooky a multi-warehouse — všetko je dnes implementované (viď tabuľka V1).

---

## Adresárová štruktúra

```
barebone-shop/
├── index.php                     # Hlavná stránka / katalóg
├── product.php                   # Detail produktu (SSR pre SEO)
├── api.php                       # API router
├── config.php                    # Konfigurácia (1 súbor, všetky credentials)
├── shop.js                       # Frontend logika
├── shop.css                      # Štýly
│
├── catalog.json                  # DEFINÍCIA: product types, atribúty, filtre
├── stores.json                   # DEFINÍCIA: stores, jazyky, meny
├── layouts.json                  # DEFINÍCIA: page layouts, sekcie, bloky
├── pricing.json                  # DEFINÍCIA: cenové pravidlá, zľavy, B2B
├── shipping.json                 # DEFINÍCIA: metódy, zóny, sadzby
├── tax.json                      # DEFINÍCIA: dane per krajina
│
├── src/
│   ├── Db.php                    # PDO abstrakcia (SQLite + MySQL/MariaDB)
│   ├── Template.php              # Template engine (~150 riadkov)
│   ├── Products.php              # Produktové query
│   ├── Categories.php            # Stromová štruktúra kategórií
│   ├── Search.php                # Full-text search (FTS5 / FULLTEXT)
│   ├── Cart.php                  # Košík (session-based)
│   ├── Orders.php                # Objednávky + status workflow
│   ├── OrderWorkflow.php         # Stavový automat objednávok
│   ├── Pricing.php               # Cenové pravidlá, zľavy, kupóny
│   ├── Shipping.php              # Výpočet dopravy
│   ├── Tax.php                   # Výpočet daní
│   ├── I18n.php                  # Multi-jazyk, locale, preklady
│   ├── Cache.php                 # Flat-file cache manager
│   ├── Import.php                # CSV/JSON import
│   ├── Sync.php                  # ERP/integrácia sync orchestrácia
│   ├── AuditLog.php              # Kto čo kedy zmenil
│   ├── Idempotency.php           # Webhook/import dedup
│   ├── Security.php              # CSRF, rate limiting, input sanitizácia
│   └── JobQueue.php              # Async job queue
│
├── adapters/                     # Vymeniteľné rozhrania (5 extension points)
│   ├── SearchAdapter.php         #   interface + FtsSearch (default)
│   ├── QueueAdapter.php          #   interface + FileQueue (default)
│   ├── PaymentAdapter.php        #   interface + StripePayment (default)
│   ├── StorageAdapter.php        #   interface + LocalStorage (default)
│   └── IntegrationAdapter.php    #   interface + null (standalone default)
│
├── adapters/contrib/             # Komunitné / vlastné adaptéry
│   ├── MeilisearchAdapter.php    #   (voliteľný search)
│   ├── MoneyErpAdapter.php       #   Money S3/S4/S5 ERP
│   ├── PohodaAdapter.php         #   Pohoda účtovníctvo
│   ├── S3StorageAdapter.php      #   AWS S3 / Cloudflare R2
│   └── DbQueueAdapter.php        #   DB-backed queue pre multi-node
│
├── blocks/                       # HTML šablóny ({{}} syntax)
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
│   ├── cache-rebuild.php         # Pregeneruj flat cache (all locales)
│   ├── worker.php                # Job queue worker (cron)
│   ├── import.php                # CLI import CSV/JSON
│   └── seed.php                  # Testovací seed (generátor 1M produktov)
│
├── migrations/                   # Numbered <YYYYMMDDHHMMSS>_<name>.php files
│   └── 20260424000000_baseline.php   # v1.4.0 schema baseline
│
├── db/
│   ├── shop.sqlite               # SQLite databáza (ak sa používa)
│   ├── schema-sqlite.sql         # Generované z catalog.json
│   └── schema-mysql.sql          # Generované z catalog.json
│
├── cache/                        # Generovaný per-locale flat cache
│   ├── sk/                       #   slovenský store
│   │   ├── categories.json
│   │   ├── cat/{slug}/page-{n}.json
│   │   └── product/{slug}.json
│   ├── en/                       #   anglický store
│   ├── de/                       #   nemecký store
│   ├── filters/                  #   filter konfigurácia per kategória
│   └── prices/                   #   pre-computed ceny per customer group
│
├── jobs/                         # Async job queue (flat files)
│   ├── pending/
│   ├── processing/
│   ├── completed/
│   └── failed/
│
├── uploads/                      # Obrázky produktov
│   ├── thumb/                    #   150x150
│   ├── medium/                   #   600x600
│   └── full/                     #   originál
│
├── admin/
│   ├── index.php                 # Admin panel
│   └── admin.js                  # Admin frontend
│
├── lang/                         # UI preklady (BBF systém)
│   ├── sk.php
│   ├── en.php
│   ├── de.php
│   ├── cs.php
│   └── ... (32 jazykov)
│
├── forms/
│   └── checkout.json             # BBF checkout formulár
│
├── templates/
│   ├── confirm-order.html        # Email potvrdenie (BBF template)
│   └── notify-order.html         # Admin notifikácia
│
└── import/                       # Auto-generované import šablóny per type
    ├── clothing-template.csv
    └── electronics-template.csv
```

---

## Databázová abstrakcia — SQLite + MySQL/MariaDB

### config.php — jeden switch, žiadna ďalšia konfigurácia

```php
return [
    // 'sqlite' alebo 'mysql'
    'db_driver' => 'sqlite',

    // SQLite — stačí toto:
    'db_sqlite_path' => __DIR__ . '/db/shop.sqlite',

    // MySQL/MariaDB — ak máš existujúci server:
    'db_host'     => '127.0.0.1',
    'db_port'     => 3306,
    'db_name'     => 'barebone_shop',
    'db_user'     => 'shop',
    'db_password' => '',             // alebo z env: getenv('DB_PASSWORD')
    'db_charset'  => 'utf8mb4',

    // Cache
    'cache_dir'   => __DIR__ . '/cache',
    'cache_ttl'   => 3600,

    // Shop
    'currency'       => 'eur',
    'per_page'       => 24,
    'admin_user'     => 'admin',
    'admin_password' => '', // password_hash() output — NIKDY plaintext
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
                PDO::ATTR_EMULATE_PREPARES   => false,  // skutočné prepared statements
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

### Kde sa líšia SQLite a MySQL

SQL je na 95% identické. Rozdiely sú izolované do pár miest:

| Funkcia | SQLite | MySQL/MariaDB |
|---|---|---|
| Auto-increment PK | `INTEGER PRIMARY KEY` | `INT AUTO_INCREMENT PRIMARY KEY` |
| Full-text search | FTS5 virtual table | `FULLTEXT INDEX` + `MATCH...AGAINST` |
| JSON funkcie | `json_extract()` | `JSON_EXTRACT()` (rovnaké!) |
| Current timestamp | `datetime('now')` | `NOW()` |
| UPSERT | `ON CONFLICT(sku) DO UPDATE` | `ON DUPLICATE KEY UPDATE` |
| Boolean | INTEGER 0/1 | TINYINT 0/1 |
| Concurrent writes | WAL mode (1 writer) | InnoDB (full MVCC) |

### Search.php — abstrakcia pre FTS

```php
class Search {
    public static function query(string $q, int $page = 1, int $perPage = 24): array {
        $db = Db::get();
        $offset = ($page - 1) * $perPage;

        if (Db::isSqlite()) {
            // FTS5 — BM25 ranking, diakritika, prefix match
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

    // Sanitizácia FTS query — ochrana proti injection do FTS5 syntaxe
    private static function sanitizeFtsQuery(string $q): string {
        // Odstráň FTS5 operátory (AND, OR, NOT, NEAR, špeciálne znaky)
        $q = preg_replace('/["\*\(\)\{\}\[\]:^~]/', ' ', $q);
        $q = preg_replace('/\b(AND|OR|NOT|NEAR)\b/i', '', $q);
        $q = trim(preg_replace('/\s+/', ' ', $q));
        if ($q === '') return '""';
        // Prefix match pre posledné slovo (autocomplete efekt)
        $words = explode(' ', $q);
        $last = array_pop($words);
        $words[] = '"' . $last . '" *';
        return implode(' ', $words);
    }
}
```

### UPSERT abstrakcia

```php
// V Import.php — funguje na oboch databázach
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

### Kedy použiť čo

| Scenár | Odporúčanie |
|---|---|
| Malý shop, shared hosting, <100k produktov | **SQLite** — nulová konfigurácia, 1 súbor, záloha = kópia |
| Existujúci server s MariaDB/MySQL | **MySQL** — využi čo už beží |
| >100k produktov, viacero concurrent writers | **MySQL** — lepší MVCC, connection pooling |
| VPS/dedicated, chceš maximálnu jednoduchosť | **SQLite** — jednoduchý read-heavy profil; 1M výkon nie je kvalifikovaný |
| Viac PHP procesov zapisuje naraz (high traffic admin) | **MySQL** — SQLite WAL zvláda 1 writera |

---

## Databázová schéma

Schéma je takmer identická. Nižšie je **spoločná logika**, rozdiely sú označené komentármi.

```sql
-- ============================================================
-- KATEGÓRIE — stromová štruktúra s materialized path
-- ============================================================
CREATE TABLE categories (
    id            INTEGER PRIMARY KEY,
    slug          TEXT UNIQUE NOT NULL,
    name          TEXT NOT NULL,
    parent_id     INTEGER,
    path          TEXT NOT NULL DEFAULT '',     -- "electronics/phones/android"
    depth         INTEGER NOT NULL DEFAULT 0,
    sort_order    INTEGER DEFAULT 0,
    product_count INTEGER DEFAULT 0,           -- pre-computed, aktualizuje sa pri importe
    meta          TEXT,                        -- JSON: description, image, SEO
    FOREIGN KEY (parent_id) REFERENCES categories(id)
);
CREATE INDEX idx_cat_parent ON categories(parent_id);
CREATE INDEX idx_cat_path ON categories(path);

-- ============================================================
-- PRODUKTY — jadro katalógu
-- ============================================================
CREATE TABLE products (
    id            INTEGER PRIMARY KEY,
    sku           TEXT UNIQUE NOT NULL,
    slug          TEXT UNIQUE NOT NULL,
    name          TEXT NOT NULL,
    category_id   INTEGER NOT NULL,
    price         INTEGER NOT NULL,            -- v centoch (1990 = €19.90)
    compare_price INTEGER,                     -- pôvodná cena (pre zľavy)
    currency      TEXT NOT NULL DEFAULT 'eur',
    stock         INTEGER NOT NULL DEFAULT 0,
    status        TEXT NOT NULL DEFAULT 'active', -- active | draft | archived
    images        TEXT DEFAULT '[]',           -- JSON array názvov súborov
    summary       TEXT DEFAULT '',             -- krátky popis (listing)
    description   TEXT DEFAULT '',             -- plný popis (HTML, detail)
    attrs         TEXT DEFAULT '{}',           -- JSON: voľné atribúty
    tags          TEXT DEFAULT '[]',           -- JSON array tagov
    weight_g      INTEGER DEFAULT 0,           -- hmotnosť v gramoch (doprava)
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

-- === SQLite: FTS5 virtual table + sync triggery ===
-- (v schema-sqlite.sql)
CREATE VIRTUAL TABLE products_fts USING fts5(
    name, sku, summary, tags,
    content=products, content_rowid=id,
    tokenize='unicode61 remove_diacritics 2'  -- slovenčina/čeština friendly
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

-- === MySQL/MariaDB: FULLTEXT index priamo na tabuľke ===
-- (v schema-mysql.sql — nie je treba triggery ani extra tabuľku)
-- ALTER TABLE products ADD FULLTEXT idx_ft_products (name, sku, summary, tags);

-- ============================================================
-- FILTROVATEĽNÉ ATRIBÚTY — faceted search bez Elasticsearch
-- ============================================================
-- Denormalizovaná tabuľka pre rýchle filtrovanie
-- 1M produktov × ~10 atribútov = 10M riadkov, stále <10ms s indexom
CREATE TABLE product_attrs (
    product_id  INTEGER NOT NULL,
    attr_name   TEXT NOT NULL,       -- "color", "size", "brand", "material"
    attr_value  TEXT NOT NULL,       -- "black", "XL", "Nike", "cotton"
    numeric_val REAL,                -- pre range filtre (hmotnosť, šírka...)
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
CREATE INDEX idx_pa_lookup ON product_attrs(attr_name, attr_value, product_id);
CREATE INDEX idx_pa_product ON product_attrs(product_id);
CREATE INDEX idx_pa_numeric ON product_attrs(attr_name, numeric_val)
    WHERE numeric_val IS NOT NULL;

-- ============================================================
-- VARIANTY — veľkosť/farba kombinácie
-- ============================================================
CREATE TABLE variants (
    id          INTEGER PRIMARY KEY,
    product_id  INTEGER NOT NULL,
    sku         TEXT UNIQUE NOT NULL,
    name        TEXT NOT NULL DEFAULT '',       -- "XL / Black"
    attrs       TEXT NOT NULL DEFAULT '{}',     -- {"size": "XL", "color": "black"}
    price_delta INTEGER NOT NULL DEFAULT 0,    -- +/- v centoch voči base price
    price_absolute INTEGER,                   -- absolútna cena (ak je set, ignoruje base+delta)
    stock       INTEGER NOT NULL DEFAULT 0,
    sort_order  INTEGER DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
CREATE INDEX idx_var_product ON variants(product_id);

-- ============================================================
-- OBJEDNÁVKY
-- ============================================================
CREATE TABLE orders (
    id             INTEGER PRIMARY KEY,
    order_number   TEXT UNIQUE NOT NULL,        -- "ORD-20260312-XXXX"
    status         TEXT NOT NULL DEFAULT 'pending', -- pending|paid|shipped|delivered|cancelled
    customer_name  TEXT NOT NULL,
    customer_email TEXT NOT NULL,
    customer_phone TEXT DEFAULT '',
    shipping_addr  TEXT DEFAULT '{}',           -- JSON objekt adresy
    payment_method TEXT NOT NULL,
    payment_id     TEXT DEFAULT '',             -- Stripe payment ID
    subtotal       INTEGER NOT NULL,            -- v centoch
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
    name        TEXT NOT NULL,                  -- snapshot názvu v čase objednávky
    quantity    INTEGER NOT NULL,
    unit_price  INTEGER NOT NULL,               -- snapshot ceny v čase objednávky
    total       INTEGER NOT NULL,
    attrs       TEXT DEFAULT '{}',              -- snapshot variantu
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
);
CREATE INDEX idx_oi_order ON order_items(order_id);
```

---

## API Endpointy

Všetko cez `api.php`, routované cez `$_GET['action']`.

| Endpoint | Metóda | Popis | Cache |
|---|---|---|---|
| `?action=categories` | GET | Strom kategórií | `cache/categories.json`, TTL 1h |
| `?action=products&cat={slug}&page={n}&sort={field}&filters={json}` | GET | Stránkovaný listing | `cache/cat/{slug}/page-{n}.json` |
| `?action=product&slug={slug}` | GET | Detail produktu + varianty | `cache/product/{slug}.json` |
| `?action=search&q={query}&page={n}` | GET | FTS5 vyhľadávanie | Nekešované (rýchle aj bez) |
| `?action=filters&cat={slug}` | GET | Dostupné filtre pre kategóriu | `cache/cat/{slug}/filters.json` |
| `?action=cart` | GET | Aktuálny košík | Session |
| `?action=cart_add` | POST | Pridať do košíka | — |
| `?action=cart_update` | POST | Zmeniť množstvo / odstrániť | — |

Checkout ide cez BareBonesForms (`forms/checkout.json` → `submit.php`).

---

## Cache stratégia — tri úrovne

```
Úroveň 0: Apache/Nginx (najrýchlejšia)
  .htaccess: ak existuje cache/cat/{slug}/page-1.json → servuj priamo
  PHP sa vôbec nespustí. Čistý filesystem read.

Úroveň 1: PHP flat-file cache
  api.php skontroluje filemtime() cache súboru
  Ak < TTL → readfile() a exit
  Ak expired → query SQLite → zapíš cache → respond

Úroveň 2: SQLite (vždy rýchle)
  Indexed queries: <5ms pre 1M riadkov
  FTS5 search: <20ms
  Fallback ak cache neexistuje
```

### Cache invalidácia — Stale-While-Revalidate

Nikdy nezmažeme cache súbor priamo. Vždy atomic swap: nový `.tmp` → `rename()`.
Useri nikdy nepocítia "studenú cache".

```php
class Cache {
    // Atomic write — zapíš do .tmp, potom rename (atómový na POSIX)
    public static function write(string $path, string $data): void {
        $file = self::path($path);
        $tmp = $file . '.tmp.' . getmypid();
        $dir = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        file_put_contents($tmp, $data, LOCK_EX);
        rename($tmp, $file);  // atomický swap — starý súbor sa prepíše okamžite
    }

    // Invalidácia = naplánuj rebuild, ale NEMAZ existujúci súbor
    public static function invalidate(string $path): void {
        JobQueue::push('cache_rebuild', ['path' => $path], priority: 1);
    }

    // Bulk invalidácia — rebuild na pozadí, existujúca cache servuje ďalej
    public static function invalidatePrefix(string $prefix): void {
        JobQueue::push('cache_rebuild', ['prefix' => $prefix], priority: 2);
    }

    // Flush = označ všetko ako stale, rebuild lazy
    // Nezmažeme nič — staré súbory servujú kým sa nevygenerujú nové
    public static function flush(): void {
        file_put_contents(self::path('.flush_marker'), time());
        JobQueue::push('cache_rebuild', ['scope' => 'full'], priority: 3);
    }
}
```

### .htaccess pravidlá pre Level 0 cache

```apache
# Ak existuje cache súbor, servuj ho priamo (bypass PHP)
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

## Produktový JSON formát

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

    // Alternatíva pre elektroniku — absolútna cena namiesto delta:
    // {"sku": "IPHONE-128", "attrs": {"storage": "128GB"}, "price_absolute": 99900, "stock": 20}
    // {"sku": "IPHONE-256", "attrs": {"storage": "256GB"}, "price_absolute": 119900, "stock": 15}
    // Logika: COALESCE(price_absolute, base_price + price_delta)
}
```

---

## Vyhľadávanie — FTS5 (SQLite) / FULLTEXT (MySQL)

### Prečo nepotrebujeme Elasticsearch

| Funkcia | Elasticsearch | SQLite FTS5 | MySQL FULLTEXT |
|---|---|---|---|
| Full-text search | Áno | Áno | Áno |
| Diakritika (č, š, ž) | Plugin | `remove_diacritics 2` | Collation dependent |
| Ranking (relevancia) | BM25 | BM25 (built-in) | TF-IDF |
| Prefix match ("tri*") | Áno | Áno | Áno (BOOLEAN mode) |
| Phrase match | Áno | Áno | Áno |
| Highlight snippets | Áno | `highlight()` | Manuálne |
| 1M dokumentov, <50ms | Áno | Áno | Áno (InnoDB) |
| Infraštruktúra | JVM + cluster | Nulová | Už beží |
| RAM požiadavky | 2-8 GB | ~50 MB | Existujúci server |

### SQLite FTS5

```sql
-- Základné vyhľadávanie s BM25 rankingom
SELECT p.*, rank
FROM products_fts fts
JOIN products p ON p.id = fts.rowid
WHERE products_fts MATCH :query
  AND p.status = 'active'
ORDER BY rank
LIMIT 20 OFFSET :offset;

-- S highlight snippetmi
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
-- Setup: FULLTEXT index na products tabuľke (v schema-mysql.sql)
ALTER TABLE products ADD FULLTEXT idx_ft_products (name, summary, tags);

-- Natural language search s relevanciou
SELECT p.*, MATCH(name, summary, tags) AGAINST(:query) AS relevance
FROM products p
WHERE MATCH(name, summary, tags) AGAINST(:query IN NATURAL LANGUAGE MODE)
  AND p.status = 'active'
ORDER BY relevance DESC
LIMIT 20 OFFSET :offset;

-- Boolean mode pre pokročilé query (+povinné, -vylúčené, "fráza")
WHERE MATCH(name, summary, tags)
    AGAINST('+čierne +tričko -detské' IN BOOLEAN MODE)

-- Prefix match (autocomplete)
WHERE MATCH(name, summary, tags) AGAINST('tri*' IN BOOLEAN MODE)
```

### MySQL full-text pre slovenčinu/češtinu

```sql
-- MariaDB 10.4+ má dobré InnoDB FULLTEXT s unicode podporou
-- Pre lepšie výsledky s diakritikou:
-- 1. Tabuľka musí byť utf8mb4_unicode_ci collation
-- 2. Zníženie minimálnej dĺžky slova pre krátke slovenské slová:
--    SET GLOBAL innodb_ft_min_token_size = 2;  (default je 3)
-- 3. Restart MySQL po zmene
```

---

## Faceted filtering — bez Elasticsearch

Kľúčový trik: `product_attrs` tabuľka + set intersection cez SQL.

### Získanie dostupných filtrov pre kategóriu

```sql
-- Aké filtre a hodnoty sú k dispozícii v kategórii "tričká"?
SELECT pa.attr_name, pa.attr_value, COUNT(*) as cnt
FROM product_attrs pa
JOIN products p ON p.id = pa.product_id
WHERE p.category_id = :cat_id AND p.status = 'active'
GROUP BY pa.attr_name, pa.attr_value
ORDER BY pa.attr_name, cnt DESC;

-- Výsledok:
-- brand    | BasicWear  | 45
-- brand    | Nike       | 32
-- color    | čierna     | 67
-- color    | biela      | 54
-- material | bavlna     | 89
-- size     | M          | 71
-- size     | L          | 68
```

### Aplikovanie viacerých filtrov

```sql
-- Produkty v kategórii "tričká", farba=čierna, veľkosť=L
-- Technika: COUNT(DISTINCT) = počet aktívnych filtrov
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
HAVING COUNT(DISTINCT pa.attr_name) = 2  -- musí matchnúť oba filtre
ORDER BY p.price ASC
LIMIT 24 OFFSET :offset;
```

### Range filter (cena, hmotnosť)

```sql
-- Produkty s cenou €10-€50
WHERE p.price BETWEEN 1000 AND 5000

-- Kombinácia s atribútmi
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

### Výkonnostná optimalizácia pre 1M+ produktov

`GROUP BY + HAVING COUNT` vytvára temporary table, čo pri veľkých kategóriách (50k+ produktov)
môže trvať 100-300ms.

**POZOR: `LIKE '%..%'` je full table scan!** Ak podmienka `LIKE` začína percentom, B-tree index
sa NEDÁ použiť. Pri 1M produktoch to = čítanie milióna riadkov. Nepoužiteľné.

**Riešenie: Faceted filtering cez FTS index.** Keďže už máme FTS5/FULLTEXT, pridáme filter
atribúty priamo do FTS indexu ako tokeny. Invertovaný index = O(1) lookup.

```sql
-- Formát: atribúty ako FTS-friendly tokeny
-- Namiesto: "|color:black|size:L|"
-- Generuj:  "attr_color_black attr_size_l attr_brand_nike"

-- SQLite FTS5 — filter_tags ako ďalší stĺpec vo FTS tabuľke
CREATE VIRTUAL TABLE products_fts USING fts5(
    name, sku, summary, tags,
    filter_tags,                              -- ← nový stĺpec
    content=products, content_rowid=id,
    tokenize='unicode61 remove_diacritics 2'
);

-- Faceted filter cez FTS (bleskový, aj pri 10M produktoch):
SELECT p.*
FROM products_fts fts
JOIN products p ON p.id = fts.rowid
WHERE products_fts MATCH 'attr_color_black AND attr_size_l'
  AND p.category_id = :cat_id
  AND p.status = 'active'
ORDER BY p.price ASC
LIMIT 24 OFFSET :offset;
-- Výkon: O(1) invertovaný index lookup, <5ms

-- MySQL/MariaDB — filter_tags ako FULLTEXT stĺpec:
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
// filter_tags sa generuje pri importe / product update:
public static function buildFilterTags(int $productId): string {
    $attrs = Db::get()->prepare("SELECT attr_name, attr_value FROM product_attrs WHERE product_id = :id");
    $attrs->execute([':id' => $productId]);
    $tags = '';
    foreach ($attrs->fetchAll() as $a) {
        // Normalizuj: lowercase, bez diakritiky, podčiarkovník namiesto medzier
        $name = self::normalizeToken($a['attr_name']);
        $value = self::normalizeToken($a['attr_value']);
        $tags .= "attr_{$name}_{$value} ";
    }
    return trim($tags);
    // Výstup: "attr_color_black attr_size_l attr_brand_nike attr_material_cotton"
}

private static function normalizeToken(string $s): string {
    $s = mb_strtolower($s);
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);  // diakritika → ASCII
    $s = preg_replace('/[^a-z0-9]/', '_', $s);
    return trim($s, '_');
}
```

Filter counters (počty pre sidebar) sa počítajú asynchrónne a kešujú v `cache/filters/{category}.json`. Refresh pri importe alebo stock update.

---

## Košík

**Dual persistence:** PHP session + localStorage zrkadlo. Session expiruje (24min default) — localStorage nie. Keď sa zákazník vráti (mobile, na druhý deň), JS obnoví košík z localStorage do session. Žiadny stratený košík = žiadna stratená konverzia.

### Frontend — localStorage zrkadlo (shop.js)

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

    // Pri page load: ak PHP session je prázdna ale localStorage má dáta → obnov
    async restore() {
        const serverCart = await fetch('api.php?action=cart').then(r => r.json());
        if (serverCart.count > 0) {
            this.save(serverCart); // sync server → local
            return serverCart;
        }
        const local = this.load();
        if (local && local.items?.length > 0) {
            // Session vypršala, ale localStorage má košík → obnov na server
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

Košík je primárne session-based, ale s restore endpointom pre localStorage recovery:

```php
// Cart.php — jednoduchá session implementácia
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
        // Načítaj aktuálne ceny a stock z DB (nie z cache — chceme real-time)
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

            $qty = min($item['qty'], $row['stock']); // cap na stock
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

    // Obnoví košík z localStorage dát (keď session expirovala)
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

    // Cart abandonment: uloží email pri prvom kroku checkoutu
    // Ak košík > 24h a má email → job: poslať pripomienku
    public static function setEmail(string $email): void {
        $_SESSION['cart_email'] = $email;
        $_SESSION['cart_email_at'] = time();
    }
}
```

---

## Checkout — integrácia s BareBonesForms

Checkout formulár je štandardný BBF form (`forms/checkout.json`).
Objednávkový flow:

```
1. User klikne "Objednať" v košíku
2. JS naplní hidden fields (items JSON, subtotal, shipping)
3. BBF renderuje checkout form (adresa, platba, GDPR)
4. BBF submit → submit.php
5. Custom handler v on_submit:
   a) Validácia stock (atomická — SELECT + UPDATE v transakcii)
   b) Vytvorenie objednávky v orders tabuľke
   c) Dekrementácia stocku
   d) Stripe platba (existujúca BBF integrácia)
   e) Confirmation email (existujúca BBF integrácia)
   f) Admin notifikácia
   g) Vyčistenie košíka
   h) Invalidácia cache pre dotknuté produkty
```

### Atomická stock rezervácia

```sql
-- V jednej transakcii:
BEGIN;

-- Skontroluj a dekrementuj stock (atomicky)
UPDATE variants SET stock = stock - :qty
WHERE id = :variant_id AND stock >= :qty;

-- Ak rows_affected = 0 → produkt nie je na sklade → ROLLBACK
-- Ak OK → INSERT objednávku → COMMIT

COMMIT;
```

### Race condition UX — keď sa produkt vypredá počas checkoutu

Zákazník A a B majú posledný kus v košíku. Obaja kliknú "Zaplatiť" v rovnakej sekunde.
A dostane lock → UPDATE prejde. B príde o sekundu → `stock >= :qty` zlyhá → 0 rows affected.

**Kritické:** Nesmieme naúčtovať Stripe pred úspešnou stock rezerváciou!

```php
// V checkout handleri (submit.php):
$db->beginTransaction();

$outOfStock = [];
foreach ($cartItems as $item) {
    $stmt = $db->prepare("UPDATE variants SET stock = stock - :qty
        WHERE id = :vid AND stock >= :qty");
    $stmt->execute([':qty' => $item['qty'], ':vid' => $item['variant_id']]);

    if ($stmt->rowCount() === 0) {
        // Tento produkt sa práve vypredal
        $outOfStock[] = $item['name'] . ($item['variant_name'] ? ' — ' . $item['variant_name'] : '');
    }
}

if (!empty($outOfStock)) {
    $db->rollBack();
    // Aktualizuj košík — odstráň/zníž nedostupné položky
    Cart::removeUnavailable($outOfStock);

    // Vráť priateľskú chybu — BBF error format
    echo json_encode([
        'success' => false,
        'error'   => I18n::t('stock_changed'),
        'details' => $outOfStock,
        // "Ľutujeme, tieto položky sa medzičasom vypredali: Čierne tričko L.
        //  Váš košík bol aktualizovaný."
    ]);
    exit;
}

// Všetok stock OK → vytvor objednávku → POTOM až Stripe
$orderId = Orders::create($cartItems, $customerData);
$db->commit();

// Stripe charge AŽ po úspešnom commit-e
$payment = Adapters::payment();
$session = $payment->createSession(Orders::byId($orderId));
```

---

## Import — CSV a JSON

### CSV formát (jednoduchý, Excel-friendly)

```csv
sku,name,category,price,stock,summary,image,attr:color,attr:size,attr:brand
TSHIRT-BLK-S,Čierne tričko S,oblecenie/tricka,1990,85,Klasické čierne tričko,tshirt-blk.jpg,čierna,S,BasicWear
TSHIRT-BLK-M,Čierne tričko M,oblecenie/tricka,1990,120,Klasické čierne tričko,tshirt-blk.jpg,čierna,M,BasicWear
```

### Import pipeline

```
CSV/JSON súbor
    → Validácia (formát, povinné polia, typy)
    → Deduplikácia (UPSERT cez SKU)
    → Kategórie: auto-create z path (oblecenie/tricka → 2 kategórie)
    → product_attrs: auto-populate z attr:* stĺpcov
    → FTS5: automaticky cez triggery
    → product_count: recount per kategória
    → Cache: invalidácia dotknutých kategórií
```

### Výkonnosť importu

Predchádzajúce seed a query čísla boli návrhové odhady, nie merania. Reprodukovateľný
runner je `cli/benchmark.php`; vytvára iba označený disposable runtime mimo repozitára
a odmietne bežnú konfiguráciu alebo neprázdny dataset.

```bash
# Spustiť z čerstvého disposable checkoutu bez config.php:
php cli/benchmark.php --prepare --runtime=/absolute/path/barebones-benchmark-run \
  --dataset=100000 --iterations=100 --concurrency=4

# Recovery iba po prerušenom read profile s presne rovnakým dokončeným datasetom:
php cli/benchmark.php --reuse --runtime=/absolute/path/barebones-benchmark-run \
  --dataset=100000 --iterations=20 --concurrency=4
```

---

## Nameraný performance a failure baseline (SQLite, 100k produktov)

Meranie z 2026-08-02 na base committe `8a364e9` s G13 working-tree zmenami: Windows 10 AMD64, 24 logical CPU,
PHP 8.5.1, SQLite WAL, PHP memory limit 128 MB, databáza 76 271 616 B. Dataset má
100 000 deterministických produktov, 20 kategórií a dva atribúty na produkt; dáta ani
JSON report sa necommitujú. Každá percentile operácia mala 5 warmupov a 20 meraní;
p50/p95/p99 je nearest-rank. HTTP TTFB meria skutočný SSR response cez loopback
`php -S`, nie produkčný webserver ani sieť.

| Operácia | p50 | p95 | p99 | Interpretácia |
|---|---:|---:|---:|---|
| Product by slug (indexed) | 0,036 ms | 0,038 ms | 0,038 ms | B-tree lookup je pod 1 ms v tomto profile. |
| Category listing, 24, price asc | 40,797 ms | 44,314 ms | 45,087 ms | Pôvodný cieľ 2–5 ms nebol potvrdený. |
| Selektívny FTS5 lookup podľa SKU | 0,420 ms | 0,432 ms | 0,453 ms | FTS-first count plán odstránil 100k scan. |
| Broad FTS5 term, 100k matches | 126,567 ms | 129,186 ms | 135,722 ms | Saturačný profil, nie bežný selektívny lookup. |
| Faceted filter, 2 atribúty | 27,325 ms | 28,218 ms | 28,630 ms | Pôvodný cieľ 5–15 ms nebol potvrdený. |
| Price range | 27,355 ms | 27,899 ms | 27,904 ms | Pôvodný cieľ 2–5 ms nebol potvrdený. |
| Storefront SSR TTFB | 6,152 ms | 7,835 ms | 8,220 ms | Single-process PHP dev server, warm local cache. |
| Product SSR TTFB | 6,521 ms | 7,341 ms | 7,425 ms | Single-process PHP dev server, warm local cache. |

Pôvodný SQLite planner pri `COUNT(*)` začínal store/status indexom a spúšťal FTS
probe pre všetkých 100 000 produktov. FTS-first `CROSS JOIN` plán znížil selektívny
p95 z 4 091,096 ms na 0,432 ms; broad term, ktorý matchuje celý dataset, má p95
129,186 ms. 1M profil nebol spustený, takže tvrdenia o 1M výkone zostávajú
**neoverené**. Pre väčšie alebo search-heavy katalógy treba samostatnú kvalifikáciu
alebo deklarovaný `SearchAdapter`.

### G4 full category a API profil

G4 rozšíril rovnaký disposable 100k profil na 2 020 kategórií a 400 000
`product_specifications`. Meria total response time aj TTFB pre skutočný category SSR
a `parametric_search` API, vždy guest/customer a cold/warm; zároveň zapisuje query count.
Cold profil pred každou požiadavkou vyčistí application cache, warm profil používa päť
warmupov. Query budget je autoritatívnejší než prenositeľnosť lokálneho wall-clock času.

| Meraná cesta | Pred p95 / queries | Po p95 / queries | Rozhodnutie |
|---|---:|---:|---|
| TecDoc facety bez filtra | 364,124 ms / 5 | 24,106 ms / 1 | Kritériá sa agregujú jedným batch dotazom. |
| TecDoc facety, 1 aktívny filter | 372,684 ms / 5 | 56,891 ms / 2 | Jeden spoločný subset + jeden self-exclusion dotaz. |
| Category tree, 2 020 uzlov | 91,556 ms / 1 | 4,733 ms / 1 | O(n) parent index nahradil opakovaný scan všetkých rows. |
| Related, kategória s 5 000 produktmi | 1,032 ms / 2 | 1,059 ms / 2 | `RANDOM/RAND` sa nemenil: meranie nepotvrdilo hotspot. |

Optimalizovaný full SSR mal p95 43,533–62,049 ms pri 35 guest a 38 customer
dotazoch; API p95 38,388–48,660 ms pri 14 guest a 17 customer dotazoch. Runner
failne nad query budgetmi 36/39 pre SSR a 15/18 pre API. Časové CI stropy sú
zámerne konzervatívne (500 ms pre HTTP, 150/250 ms pre facety, 25 ms pre strom
a related), aby zachytili rádovú regresiu bez predstierania univerzálnej latencie.
Raw JSON sa zapisuje iba do označeného runtime a necommitne sa.

### Concurrency a poruchové invarianty

Sériový `Orders::create()` control aj 4/4 SQLite checkout workers prešli. Workers
štartovali s explicitným 25 ms rampom, stále sa časovo prekrývali, a dokončili za
167,537 ms wall time na odlišných SKU. SQLite checkout používa file lock pri databáze,
`BEGIN IMMEDIATE`, 5 s bounded wait a neprekladá `PDOException` na falošný out-of-stock.
Je to bezpečná serializácia jedného SQLite writera, nie dôkaz paralelného write
throughputu; pre vysoký počet concurrent writers zostáva odporúčanie MySQL/MariaDB.

Na rovnakom disposable profile prešlo 6/6 fault invariantov:

- payment timeout nevytvoril falošný initiated transaction,
- duplicate payment webhook zmenil stav iba raz,
- expired database-queue lease bol reclaimnutý a starý owner ho nemohol dokončiť,
- mail outage zostal auditovaný ako `failed`, nie `sent`,
- carrier outage nevytvoril phantom shipping label,
- blokovaný backup cieľ zlyhal fail-closed bez čiastočného `.db` súboru.

Resource envelope a všetky raw percentile/failure dáta zapisuje runner do
`<runtime>/reports/benchmark-100000.json`. Simulácia backup failure používa blokovaný
cieľový path; úmyselne nezapĺňa reálny disk.

### SQLite PRAGMA optimalizácie

```sql
PRAGMA journal_mode = WAL;          -- concurrent reads počas write
PRAGMA synchronous = NORMAL;        -- bezpečné, ale rýchlejšie ako FULL
PRAGMA cache_size = -64000;         -- 64MB cache (default je 2MB)
PRAGMA temp_store = MEMORY;         -- temp tabuľky v RAM
PRAGMA mmap_size = 268435456;       -- 256MB memory-mapped I/O
PRAGMA page_size = 4096;            -- optimálne pre väčšinu OS
```

---

## Bezpečnosť — do hĺbky

### Prehľad vektorov

| Vektor | Ochrana | Implementácia |
|---|---|---|
| SQL injection | Výhradne prepared statements | PDO s `EMULATE_PREPARES=false` |
| XSS | Output escaping + CSP | `esc()` helper + Content-Security-Policy header |
| CSRF | Token v session | Rovnaký mechanizmus ako BBF |
| Rate limiting | IP-based counter | Flat file, konfigurovateľné limity |
| Directory traversal | Whitelist pattern | `[a-z0-9-]` only pre slugy |
| File upload | Reprocess + rename | GD/Imagick rewrite, random názov |
| Brute force (admin) | Progressive delay + lockout | Exponenciálny backoff po 3 pokusoch |
| Stock manipulation | Atomické transakcie | Server-side cena, nikdy z clienta |
| Session fixation | Regenerácia ID | `session_regenerate_id(true)` po login |
| Clickjacking | X-Frame-Options | `DENY` alebo `SAMEORIGIN` |
| MIME sniffing | X-Content-Type-Options | `nosniff` |
| Open redirect | Whitelist domén | Žiadne redirect na user-supplied URL |

### Security.php — centralizovaná bezpečnosť

```php
class Security {
    // ============================================================
    // HTTP hlavičky — volať raz na začiatku každého requestu
    // ============================================================
    public static function headers(): void {
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: DENY");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
        // CSP — prísny, ale praktický
        header("Content-Security-Policy: "
            . "default-src 'self'; "
            . "script-src 'self' https://js.stripe.com; "   // Stripe JS
            . "style-src 'self' 'unsafe-inline'; "           // inline štýly pre dynamický UI
            . "img-src 'self' data: https:; "                // obrázky z CDN
            . "connect-src 'self' https://api.stripe.com; "  // API + Stripe
            . "frame-src https://js.stripe.com; "            // Stripe iframe
            . "object-src 'none'; "
            . "base-uri 'self';"
        );
    }

    // ============================================================
    // CSRF — token generovanie a validácia
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
    // Rate limiting — dedicated SQLite (nie flat files)
    // ============================================================
    // Flat-file rate limiting sa pri L7 DDoS zadusí na disk I/O.
    // Separátna SQLite DB s PRAGMA synchronous=OFF je 100x rýchlejšia.
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
            // Cleanup staré záznamy (>5 min) — pri každom otvorení
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

        // Zaznamenaj hit
        $db->prepare("INSERT INTO hits (key, ts) VALUES (:key, :ts)")
           ->execute([':key' => $key, ':ts' => $now]);

        // Počítaj hity v okne
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
    // Input sanitizácia
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
    // IP adresa — za proxy/load balancer
    // ============================================================
    public static function clientIp(): string {
        // Dôveruj X-Forwarded-For iba ak je definovaný trusted proxy
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
    // Admin autentifikácia — s brute-force ochranou
    // ============================================================
    public static function adminAuth(): bool {
        $cfg = require __DIR__ . '/../config.php';
        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW'] ?? '';

        // Kontrola lockout
        $ip = self::clientIp();
        $lockFile = __DIR__ . '/../cache/rate/admin_' . md5($ip);
        $lockData = file_exists($lockFile) ? json_decode(file_get_contents($lockFile), true) : null;

        if ($lockData && $lockData['failures'] >= 5) {
            $lockoutSec = min(300, pow(2, $lockData['failures'] - 3)); // exponenciálny backoff
            if (time() - $lockData['last_attempt'] < $lockoutSec) {
                header('HTTP/1.1 429 Too Many Requests');
                header('Retry-After: ' . $lockoutSec);
                exit('Too many login attempts. Try again later.');
            }
        }

        if ($user === $cfg['admin_user'] && password_verify($pass, $cfg['admin_password'])) {
            // Reset lockout pri úspešnom logine
            if (file_exists($lockFile)) unlink($lockFile);
            return true;
        }

        // Zaznamenaj neúspešný pokus
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
// Helper funkcia — globálna, krátka, používaná všade
// ============================================================
function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
```

### Bezpečný checkout flow

```
Browser                      Server
  │                             │
  │  POST /api/cart_add         │
  │  + CSRF token               │──→ Validuj CSRF
  │  + product_id, variant_id   │──→ Validuj ID (int), stock > 0
  │  + qty                      │──→ Cap qty na stock, max 99
  │                             │
  │  GET checkout form (BBF)    │
  │                             │──→ Renderuj s CSRF token
  │                             │──→ Ceny a totals = SERVER-SIDE
  │                             │    (nikdy z hidden fields)
  │                             │
  │  POST submit (BBF)          │
  │  + form data                │──→ Validuj vstupy (BBF validation)
  │  + CSRF token               │──→ Validuj CSRF
  │                             │──→ Rate limit: 10/min checkout
  │                             │──→ BEGIN TRANSACTION
  │                             │──→   Recalculate total server-side
  │                             │──→   Check stock atomically
  │                             │──→   UPDATE stock (WHERE stock >= qty)
  │                             │──→   INSERT order + order_items
  │                             │──→ COMMIT (alebo ROLLBACK)
  │                             │──→ Stripe charge (so server-side sumou)
  │                             │──→ Email potvrdenie
  │                             │
  │  ← Redirect na thank you    │
```

### Čo NIKDY nerobiť

```
✗ Ceny z hidden fields alebo JavaScript → VŽDY prepočítaj na serveri
✗ Stock z clienta → VŽDY skontroluj v DB v transakcii
✗ SQL cez string concatenation → VŽDY prepared statements
✗ Trust X-Forwarded-For bez trusted proxy → spoofable
✗ Slug z user inputu bez sanitizácie → directory traversal
✗ Admin bez rate limiting → brute force
✗ Obrázok bez reprocessu → PHP shell upload
✗ Session token v URL → session fixation cez referer
```

---

## Admin panel

Minimalistický, bez frameworku. Legacy HTTP Basic auth ostáva iba pre inštalácie bez
DB identity. Po vytvorení prvého `admin_accounts` záznamu sa používa formulárové
prihlásenie s password hashom, šifrovaným TOTP alebo jednorazovým recovery kódom,
server-side session a revokovateľným device záznamom. Rola aj store permission grant
musia povoliť akciu; účet bez grantu do aktuálneho store zlyhá closed.
TOTP kód platí len raz: `admin_accounts.totp_last_counter` atomicky prijme iba novší
časový krok, takže replay v ±30 s okne zlyhá.

Storefront session beží so `session.use_strict_mode`, prihlásenie zákazníka rotuje
CSRF token a throttling prihlásenia je per účet + IP (cudzie pokusy nezamknú vlastníka).
Session ID sa do DB (wishlist `s:<…>`, abandoned carts, cookie consent) ukladá iba ako
`Security::sessionKey()` = SHA-256; migrácia `20261012200000` prehashuje staré hodnoty.

Stránka **Admin users** (`admin.php?p=admin_users`, oprávnenie `admins.manage`, owner ho má
cez `*`) nahrádza CLI pre bežnú správu: vytvorenie účtu, blokovanie/odblokovanie, nové heslo,
TOTP enroll/reset (secret a recovery kódy sa zobrazia raz; reset cudzieho účtu zruší jeho
sessions), odhlásenie všade a store granty pre aktívny obchod. Ne-owner s delegovaným
`admins.manage` nesmie vytvoriť ani meniť owner účet; nikto nezablokuje sám seba ani
posledného aktívneho ownera. Každá zmena ide do `audit_log` (entity `admin_account`).

Overenie e-mailu zákazníka: registrácia pošle odkaz `account.php?p=verify_email&token=…`
(tabuľka `customer_email_verifications`, uložený iba SHA-256, platnosť 24 h, jednorazový,
viazaný na adresu a obchod). Dashboard ponúka opätovné odoslanie, max. 3 za hodinu na účet;
nový odkaz zneplatní starý. `verify_url` sa do `email_log` neukladá.

### Funkcie

1. **Dashboard** — objednávky dnes, tržby, low stock alert
2. **Produkty** — CRUD, inline edit cena/stock, bulk actions
3. **Objednávky** — zoznam, zmena statusu, detail
4. **Import** — CSV upload, preview, potvrdenie
5. **Cache** — manuálny flush, rebuild

### Bulk operácie

```
- Zmena ceny pre kategóriu (+10%, -€5, nastaviť na X)
- Zmena statusu (activate/archive/draft)
- Export CSV (filtrovaný)
- Stock update z CSV (sku + qty)
```

---

## Frontend — vanilla JS

### Katalóg (shop.js)

```javascript
// Štruktúra - žiadny framework, čistý DOM
const Shop = {
    state: {
        category: null,
        page: 1,
        sort: 'price_asc',
        filters: {},
        cart: { items: [], count: 0, subtotal: 0 },
    },

    // Načítanie produktov (cache-through)
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

    // Infinite scroll ALEBO klasická paginácia (konfigurovateľné)
    // Faceted filtre v sidebar
    // Sort: cena ↑↓, názov, najnovšie, najpredávanejšie
    // Lazy loading obrázkov (native loading="lazy")
    // Košík widget v headeri (count badge)
};
```

### SEO — Server-side rendering

`product.php` renderuje plný HTML pre crawlery:

```php
// product.php — SSR pre SEO
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

## 5 Adapter Interfaces — vymeniteľné rozhrania

Jadro je bare bones. Ale na 5 kľúčových miestach má jasné rozhranie, ktoré sa dá vymeniť bez zmeny core kódu. Default implementácia funguje z krabice. Kto potrebuje viac, napíše adaptér.

### Princíp

```php
// Každý adapter implementuje interface
// Config.php určuje, ktorý adaptér sa použije
// Core kód volá len interface metódy — nikdy konkrétnu implementáciu

// config.php
return [
    'adapters' => [
        'search'       => 'FtsSearch',       // default: SQLite FTS5 / MySQL FULLTEXT
        'queue'        => 'FileQueue',        // default: jobs/ adresár
        'payment'      => 'StripePayment',    // default: Stripe Checkout
        'storage'      => 'LocalStorage',     // default: uploads/ adresár
        'integration'  => null,               // default: žiadna (standalone)
    ],
];
```

### 1. SearchAdapter — vyhľadávanie

```php
interface SearchAdapter {
    public function index(array $product): void;           // pridaj/updatuj v indexe
    public function remove(int $productId): void;          // vymaž z indexu
    public function search(string $query, array $opts): SearchResult;
    public function suggest(string $prefix, int $limit): array;  // autocomplete
    public function reindexAll(): void;                    // full rebuild
}

// Default: FtsSearch (SQLite FTS5 / MySQL FULLTEXT)
// Voliteľné: MeilisearchAdapter, TypesenseAdapter, AlgoliaAdapter
// Nikdy: ElasticsearchAdapter
```

**Prečo:** FTS5/FULLTEXT zvládne 99% shopov. Ale ak niekto raz potrebuje synonymá, typo tolerance, merchandising rules — napíše adaptér pre Meilisearch (lightweight, Rust, single binary, nie JVM monštrum).

### 2. QueueAdapter — async operácie

```php
interface QueueAdapter {
    public function push(string $type, array $payload, int $priority = 5): string; // → job ID
    public function pull(int $limit = 10): array;         // vezmi joby na spracovanie
    public function complete(string $jobId): void;
    public function fail(string $jobId, string $error): void;
    public function retry(string $jobId): void;
    public function stats(): array;                       // pending/processing/failed counts
}

// Default: FileQueue (jobs/ adresár — flat JSON files)
// Voliteľné: DbQueue (jobs tabuľka v DB — lepšie pre multi-node)
// Voliteľné: RedisQueue (ak už Redis beží)
```

**Prečo:** File queue je perfektný na single-node. DbQueue pridáš, keď máš 2+ servery. Redis ak ho už máš. Ale core kód sa nemení.

### 3. PaymentAdapter — platby

```php
interface PaymentAdapter {
    public function createSession(Order $order): PaymentSession;  // → redirect URL / client secret
    public function handleWebhook(array $payload): WebhookResult; // spracuj callback
    public function refund(string $paymentId, int $amount): RefundResult;
    public function getStatus(string $paymentId): string;
}

// Default: StripePayment (Stripe Checkout — hosted flow)
// Dodané: PayUPayment, CashOnDelivery, MockPayment; bankový prevod je vstavaná offline metóda.
// Ďalšie brány = nová trieda implementujúca PaymentAdapter.
```

**Prečo:** Platby sú najcitlivejšia časť. Provider-hosted flow (redirect na Stripe/PayPal) je najľahší na implementáciu a najťažší na pokašľanie. Webhooky idú cez rovnaké rozhranie.

### 4. StorageAdapter — médiá a súbory

```php
interface StorageAdapter {
    public function store(string $path, string $contents): string;  // → public URL
    public function delete(string $path): void;
    public function url(string $path, string $variant = 'medium'): string;  // thumb/medium/full
    public function resize(string $path, int $width, int $height): string;
}

// Default: LocalStorage (uploads/ adresár + GD resize)
// Voliteľné: S3Storage, CloudflareR2Storage, FtpStorage
```

**Prečo:** Lokálny filesystem stačí pre 90% shopov. Ale ak máš CDN alebo multi-node deploy, vymeníš za S3 bez zmeny kódu.

### 5. IntegrationAdapter — ERP, CRM, účtovníctvo

```php
interface IntegrationAdapter {
    // Sync produktov
    public function pullProducts(?\DateTimeInterface $since = null): iterable;
    public function pushProduct(array $product): void;

    // Sync objednávok
    public function pushOrder(Order $order): string;     // → externé ID
    public function pullOrderStatus(string $externalId): string;

    // Sync skladových zásob
    public function pullStock(): array;                  // [{sku, qty}, ...]

    // Sync zákazníkov (voliteľné)
    public function pushCustomer(array $customer): string;

    // Zdravie spojenia
    public function healthCheck(): bool;
}

// Default: null (standalone shop, žiadna integrácia)
// Voliteľné: MoneyErpAdapter, PohodaAdapter, FlexiBeeAdapter
//            WooCommerceAdapter (import z existujúceho shopu)
//            CsvFileAdapter (import/export cez CSV)
```

### Príklad: MoneyErpAdapter

```php
// adapters/MoneyErpAdapter.php
class MoneyErpAdapter implements IntegrationAdapter {
    private PDO $erp;  // priame DB spojenie na Money S3/S4/S5

    public function __construct(array $config) {
        // Money ERP používa Firebird / MS SQL / MySQL
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
                'price'       => (int)($row['price'] * 100),  // na centy
                'stock'       => (int)$row['stock'],
                'category'    => $row['category'] ?? 'uncategorized',
                'external_id' => $row['ID'],
            ];
        }
    }

    public function pushOrder(Order $order): string {
        // Vytvorí objednávku/faktúru v Money
        $this->erp->beginTransaction();
        // INSERT do sObjednavkyPrijate + sObjednavkyPrijatePolozky
        // Mapovanie: order.items → Money položky cez SKU
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

### Sync orchestrácia

```php
// cli/sync.php — cron job: */15 * * * * php cli/sync.php
class Sync {
    public static function run(): void {
        $adapter = self::getAdapter();
        if (!$adapter || !$adapter->healthCheck()) return;

        // 1. Pull produktov (delta sync od posledného behu)
        $lastSync = Cache::get('last_erp_sync');
        $since = $lastSync ? new \DateTime($lastSync) : null;

        $updated = 0;
        foreach ($adapter->pullProducts($since) as $product) {
            Import::upsertProduct($product);
            $updated++;
        }

        // 2. Pull stocku
        $stockData = $adapter->pullStock();
        Products::bulkUpdateStock($stockData);

        // 3. Push nových objednávok do ERP
        $orders = Orders::unsyncedForErp();
        foreach ($orders as $order) {
            try {
                $externalId = $adapter->pushOrder($order);
                Orders::markSynced($order['id'], $externalId);
            } catch (\Throwable $e) {
                Orders::markSyncFailed($order['id'], $e->getMessage());
            }
        }

        // 4. Pull stavov objednávok z ERP
        $pendingSync = Orders::pendingStatusSync();
        foreach ($pendingSync as $order) {
            $status = $adapter->pullOrderStatus($order['external_id']);
            if ($status !== $order['erp_status']) {
                Orders::updateErpStatus($order['id'], $status);
            }
        }

        Cache::set('last_erp_sync', date('c'));

        // 5. Invalidácia cache pre zmenené produkty
        if ($updated > 0) {
            JobQueue::push('cache_rebuild', ['scope' => 'changed', 'since' => $since]);
        }
    }
}
```

---

## Ťažké časti e-shopu — to, kde sa rozhoduje kvalita

### Order Lifecycle — stavový automat

```
                      ┌──────────────┐
                      │   pending    │ ← objednávka vytvorená
                      └──────┬───────┘
                             │ platba prijatá (webhook)
                      ┌──────▼───────┐
                      │     paid     │
                      └──────┬───────┘
                             │ admin/ERP potvrdí
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
                     │ zákazník chce vrátiť
               ┌─────▼──────┐
               │  returned   │
               └─────┬──────┘
                     │ spracované
               ┌─────▼──────┐
               │  refunded   │
               └─────────────┘
```

```php
// Povolené prechody — nikdy neklikneš na zlý stav
class OrderWorkflow {
    private const TRANSITIONS = [
        'pending'    => ['paid', 'cancelled'],
        'paid'       => ['processing', 'shipped', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped'    => ['delivered', 'returned'],
        'delivered'  => ['returned'],
        'returned'   => ['refunded'],
        'cancelled'  => ['refunded'],
        'refunded'   => [],  // konečný stav
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
        // Vrátiť stock
        foreach (Orders::items($order['id']) as $item) {
            Products::incrementStock($item['variant_id'] ?? $item['product_id'], $item['quantity']);
        }
        // Ak bola platba, initiovať refund
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
        // Sync do ERP
        if (Adapters::integration()) {
            JobQueue::push('erp_order_update', ['order_id' => $order['id'], 'status' => 'shipped']);
        }
    }
}
```

### Audit Log — kto čo kedy

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

### Idempotencia — webhook safe, import safe, order safe

```php
class Idempotency {
    // Webhook od Stripe môže prísť 2x, 5x, 10x — musí byť idempotentný
    public static function webhookProcessed(string $eventId): bool {
        $db = Db::get();
        // Pokús sa vložiť — ak už existuje, vráť true (už spracované)
        try {
            $stmt = $db->prepare("INSERT INTO processed_events (event_id, created_at) VALUES (:id, datetime('now'))");
            $stmt->execute([':id' => $eventId]);
            return false; // nový event, treba spracovať
        } catch (\PDOException $e) {
            return true;  // duplikát, preskočiť
        }
    }

    // Import rovnakého CSV 2x → UPSERT, nie duplikáty
    // → riešené cez ON CONFLICT(sku) v Import.php

    // Objednávka vytvorená, ale platba ešte neprišla → idempotentný stav
    // → riešené cez OrderWorkflow::canTransition()
}
```

```sql
CREATE TABLE processed_events (
    event_id   TEXT PRIMARY KEY,     -- Stripe event ID, webhook delivery ID
    created_at TEXT DEFAULT (datetime('now'))
);
-- Cleanup: DELETE FROM processed_events WHERE created_at < datetime('now', '-7 days')
```

### Payment Webhooky — správne ošetrenie

```php
// api.php?action=webhook_stripe
public static function handleStripeWebhook(): void {
    // 1. Verifikácia podpisu (Stripe signing secret)
    $payload = file_get_contents('php://input');
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $secret = $GLOBALS['config']['stripe_webhook_secret'];

    if (!self::verifyStripeSignature($payload, $sigHeader, $secret)) {
        http_response_code(400);
        exit('Invalid signature');
    }

    $event = json_decode($payload, true);

    // 2. Idempotencia — už sme to spracovali?
    if (Idempotency::webhookProcessed($event['id'])) {
        http_response_code(200);
        exit('Already processed');
    }

    // 3. Spracovanie podľa typu eventu
    match($event['type']) {
        'checkout.session.completed' => self::onPaymentCompleted($event['data']['object']),
        'charge.refunded'            => self::onRefund($event['data']['object']),
        'charge.dispute.created'     => self::onDispute($event['data']['object']),
        default => null,  // ignoruj neznáme eventy
    };

    // 4. Vždy vráť 200 (aj keď spracovanie zlyhá — Stripe by retryoval)
    http_response_code(200);
}

private static function onPaymentCompleted(array $session): void {
    $orderId = $session['metadata']['order_id'] ?? null;
    if (!$orderId) return;

    $order = Orders::byId((int)$orderId);
    if (!$order || $order['status'] !== 'pending') return;

    OrderWorkflow::transition((int)$orderId, 'paid', 'Stripe webhook: ' . $session['id']);
    AuditLog::log('order', (int)$orderId, 'pending', 'paid', 'Payment: ' . $session['payment_intent']);

    // Async: emaily, ERP sync
    JobQueue::push('email_send', ['template' => 'order-confirmation', 'order_id' => $orderId]);
    JobQueue::push('email_send', ['template' => 'admin-notification', 'order_id' => $orderId]);
    if (Adapters::integration()) {
        JobQueue::push('erp_order_push', ['order_id' => $orderId]);
    }
}
```

### Admin oprávnenia — role-based

```json
// V config.php alebo admin-roles.json
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

Peniaze majú vlastné oprávnenia, ktoré `products.edit` ani `orders.edit` nezahŕňajú:
`orders.payments` (ručná zmena/potvrdenie platby), `orders.refund` (refund, stav `refunded`, RMA → dobropis),
`payments.manage` a `shipping.manage` (metódy a poplatky), `rma.manage` (reklamácie). `orders.*` zahŕňa
`orders.payments` aj `orders.refund`; vlastné roly v `admin-roles.json` ich musia uviesť výslovne.

---

## Prečo nie Magento / WooCommerce / Elasticsearch

### Útočná plocha porovnanie

| Komponent | Magento | BareBones Shop |
|---|---|---|
| PHP framework | Zend/Laminas (~500k LoC) | Žiadny (2k LoC vlastný kód) |
| JavaScript | RequireJS + KnockoutJS + jQuery | Vanilla JS (~600 riadkov) |
| Databáza | MySQL + Redis + Elasticsearch | SQLite alebo MySQL (1 služba) |
| Služby navyše | Varnish, RabbitMQ, Elasticsearch | Žiadne |
| Admin panel | Obrovský, stovky routov | 5 stránok, HTTP Basic |
| Composer závislosti | 200+ balíkov | 0 |
| Cron joby | Desiatky | 0-1 (voliteľný cache rebuild) |
| Známe CVE (2020-2025) | 50+ kritických | N/A (žiadny framework = žiadne CVE cudzieho kódu) |

### Elasticsearch — typický vektor útoku

Elasticsearch v Magento je notoricky problematický:

1. **Defaultne bez autentifikácie** — beží na porte 9200, často prístupný z internetu
2. **Remote Code Execution** — CVE-2021-22145, CVE-2021-44228 (Log4Shell), CVE-2023-31419
3. **Data exfiltration** — útočník číta celý produktový index + potenciálne zákaznícke dáta
4. **Resource exhaustion** — crafted queries spôsobia OOM, DoS celého servera
5. **Žiadna izolácia** — Magento + ES bežia pod rovnakým userom, kompromitácia ES = kompromitácia shopu

**BareBones Shop toto eliminuje úplne.** Full-text search je built-in funkcia databázy (FTS5/FULLTEXT), nie externá služba s vlastným network portom.

### Filozofický rozdiel

```
Magento:   1 vec robí 50 služieb → každá je útočná plocha
BareBones: 1 vec robí 1 PHP + 1 databáza → 2 útočné plochy

Menej kódu = menej bugov = menej zraniteľností = menej údržby.
```

---

## Obrázky — WebP/AVIF, lazy loading, responsive

### Automatická konverzia pri uploade

```php
// V StorageAdapter::store() alebo ako async job
// POZOR: Beží v CLI workeri, nie v web requeste!
class ImageProcessor {
    // iPhone foto 4000x3000 = 48MB RAM po dekompresii (4000*3000*4 bytes RGBA)
    // Moderné DSLR 8000x6000 = 192MB RAM
    private const MAX_DIMENSION = 8000;   // odmietni väčšie
    private const MAX_FILESIZE = 20_000_000; // 20MB

    public static function process(string $sourcePath, string $filename): array {
        // Memory guard — len pre CLI worker, nie pre web
        if (php_sapi_name() === 'cli') {
            ini_set('memory_limit', '512M');
        }

        // Veľkostný guard
        if (filesize($sourcePath) > self::MAX_FILESIZE) {
            throw new \RuntimeException('Image too large: ' . filesize($sourcePath) . ' bytes');
        }
        [$origW, $origH] = getimagesize($sourcePath);
        if ($origW > self::MAX_DIMENSION || $origH > self::MAX_DIMENSION) {
            throw new \RuntimeException("Image dimensions {$origW}x{$origH} exceed limit");
        }

        $variants = [];
        $sizes = ['thumb' => 150, 'medium' => 600, 'full' => 1200];
        $formats = ['webp', 'jpg']; // WebP primárne, JPG fallback

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

### Frontend — `<picture>` element s fallbackom

```html
<!-- V product-card.html a product-gallery.html -->
<picture>
    <source srcset="/uploads/medium/{{image_webp}}" type="image/webp">
    <img src="/uploads/medium/{{image_jpg}}" alt="{{name}}" loading="lazy"
         width="600" height="600" decoding="async">
</picture>
```

### Soft Deletes pre produkty

Produkt sa nikdy nemaže z DB. Status `archived` = neviditeľný v shope, ale URL vracia alternatívy.

```php
// product.php — archived produkt → 410 Gone + related products
$product = Products::bySlug($slug);
if (!$product) { http_response_code(404); exit; }
if ($product['status'] === 'archived') {
    http_response_code(410); // Gone — signál pre Google, že stránka bola odstránená
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
// Použitie: php cli/hash-password.php "mojeheslo"
$password = $argv[1] ?? null;
if (!$password) { echo "Usage: php hash-password.php <password>\n"; exit(1); }
echo password_hash($password, PASSWORD_ARGON2ID) . "\n";
```

---

## Čo je v jadre vs čo sú moduly

Všetko z feature matice je zahrnuté. Rozdelenie na jadro a voliteľné moduly:

### Jadro (vždy prítomné)

- Multi-store, multi-jazyk, multi-currency
- Product types cez catalog.json
- Varianty (configurable products)
- Kategórie (strom, neobmedzená hĺbka)
- Full-text search (FTS5/FULLTEXT)
- Faceted filtering
- Košík + checkout (BBF)
- Objednávky + status workflow
- Cenové pravidlá (catalog + cart rules)
- Dane per krajina
- Doprava (metódy + zóny)
- Async job queue
- Import/Export CSV
- Admin panel (BBF-driven)
- SEO (SSR, JSON-LD, sitemap, slugy per locale)
- Security (CSRF, rate limit, CSP, brute-force protection)

### Voliteľné moduly (zapínajú sa v config.php)

| Modul | Stav | Poznámka |
|---|---|---|
| Wishlist | V2 | localStorage + session sync |
| Reviews/hodnotenia | V2 | Moderácia cez admin |
| Customer accounts | V2 | Login, história objednávok, uložené adresy |
| B2B tier pricing | V1 | Už v pricing.json |
| Kupóny | V1 | Už v pricing.json cart_rules |
| Image CDN | V2 | S3/Cloudflare adapter |
| API auth (JWT/API key) | V2 | Pre mobilnú app / headless |
| Webhooky | V2 | Event → HTTP POST na externý systém |
| Elasticsearch | **Nikdy** | FTS5/FULLTEXT stačí. Žiadny extra port, žiadna útočná plocha. |

---

## Spustenie (build sequence)

Multi-lang a multi-store sú v každej fáze. Nie sú to features — sú to parametre,
cez ktoré preteká všetko od začiatku.

```
Phase 1: Základ + schéma
  [ ] config.php — DB, stores, jazyky
  [ ] catalog.json — product types + atribúty
  [ ] stores.json — stores + jazyky + meny
  [ ] layouts.json — page layouts + bloky
  [ ] cli/schema-build.php — JSON → SQL + admin forms + import templates
  [ ] Db.php, Security.php, I18n.php — databáza, bezpečnosť, jazyky
  [ ] Import.php — nahraj test CSV (multi-lang)
  [ ] lang/*.php — UI preklady (reuse z BBF)

Phase 2: Storefront (multi-lang od prvého requestu)
  [ ] Template.php — engine + blocks/ adresár
  [ ] api.php — products, categories, search, cart
  [ ] shop.js — katalóg, filtre, košík, locale switcher
  [ ] product.php — SSR detail s JSON-LD (per locale)
  [ ] Cache.php — flat cache per locale, stale-while-revalidate
  [ ] .htaccess — rewrite + Level 0 cache

Phase 3: Košík + platby
  [ ] Cart.php — session + localStorage dual persistence
  [ ] forms/checkout.json — BBF checkout form
  [ ] Orders.php + OrderWorkflow.php — objednávky, stock, audit
  [ ] Stripe (reuse z BBF) + webhook handler + idempotencia
  [ ] Email templates (reuse z BBF)

Phase 4: Admin + worker
  [ ] admin/ — CRUD (auto-gen BBF forms), objednávky, role-based auth
  [ ] JobQueue.php + cli/worker.php — cache rebuild, emaily, image resize
  [ ] AuditLog.php — kto čo kedy

Phase 5: Benchmark + hardening
  [ ] seed.php — 1M produktov × 3 jazyky × 2 stores
  [ ] Benchmark: listing, search, filter, checkout (validácia performance cieľov)
  [ ] Security audit: injection, XSS, CSRF, rate limit, webhook replay
```

---

## Faktúry — InvoiceService (Roadmap 1.2)

PDF faktúry pre paid objednávky, právne použiteľné v EÚ (SK/CZ/PL/HU/DE/EN), s atómovým číslovaním, snímkami údajov, podpísaným download tokenom.

### Princíp

- **Imutabilita** — vystavená faktúra sa nikdy nemení; oprava = `cancelled_at` + nová faktúra (stornovaný + nový doklad).
- **Snapshots** — `customer_snapshot` aj `seller_snapshot` sú JSON kópie v čase vystavenia. Zmena adresy zákazníka neovplyvní historickú faktúru.
- **Atómové číslovanie** — `invoice_counters(year, prefix, last_number)` s `UPDATE last_number=last_number+1` v transakcii. Backstop `UNIQUE` constraint na `invoices.number`.
- **Forward-only** — žiadne automatické regenerovanie. Backfill `php cli/invoices-backfill.php --dry-run` pre legacy paid objednávky.

### Schema

```sql
CREATE TABLE invoices (
  id INTEGER PRIMARY KEY,
  order_id INTEGER NOT NULL,
  number TEXT NOT NULL UNIQUE,             -- "20260001" alebo "SK-20260001"
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

### Konfigurácia per-store (`stores.json`)

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

### Tok generovania

```
OrderWorkflow::transition(orderId, 'paid')
  └─ handlePaid(order) → push job invoice_issue priority=3 PO commit-e
       └─ worker handleInvoiceIssue($payload)
            ├─ InvoiceService::issue($orderId)              ← idempotentné
            │    ├─ allocateNumber() v tx (counters → UPDATE)
            │    ├─ snapshots customer + seller z stores.json
            │    ├─ INSERT invoices
            │    ├─ render PDF (MinimalPdf) PO commit-e
            │    └─ return $invoice
            └─ MailService::send('invoice_issued', email, locale, vars,
                                 orderId, [['filename'=>{number}.pdf,
                                            'content_type'=>'application/pdf',
                                            'data'=>$pdfBytes]])
```

### Reverse-charge (B2B EU)

`InvoiceService::isReverseCharge($seller, $customer)` vracia true iff:
- seller.country a customer.country sú obidva v `EU_COUNTRIES`
- ide o rôzne krajiny
- customer má neprázdne `ic_dph`

V1 vykresľuje len textový placeholder ("Daň odvádza zákazník" v správnom locale). Plnú DPH/OSS logiku rieši samostatná misia.

### Customer download (signed token)

```
GET account.php?p=invoice&token=<64-hex>
  ├─ validácia: strlen===64 && ctype_xdigit
  ├─ InvoiceService::byToken(token) → DB lookup
  ├─ ak null alebo cancelled_at !== null → 404
  └─ readfile(pdfAbsolutePath); Content-Disposition: attachment
```

Endpoint je registrovaný **PRED** auth gate v `account.php` — token je credential, login nie je nutný.

### Admin

- `admin.php?p=order&id=N` — sekcia "Faktúry": zoznam, link "PDF", "Resend invoice email", "Vystaviť faktúru" (manuálny trigger ak job zlyhal).
- `admin.php?p=invoice_pdf&id=N` — chránený `orders.view` (aj rola fulfillment), obsluhuje sa pred HTML layoutom adminu, takže odpoveď obsahuje iba PDF bajty; streamuje PDF inline pre náhľad.
- MinimalPdf kóduje text do CP1252 cez iconv (vrátane `€` = 0x80); faktúra s dlhým zoznamom položiek pokračuje na ďalšej strane s opakovanou hlavičkou tabuľky (telo končí na 278 mm, pätička 285 mm).

### Backfill

```
php cli/invoices-backfill.php --dry-run                   # preview
php cli/invoices-backfill.php                             # skutočne vystav
php cli/invoices-backfill.php --status=paid --limit=100   # batch
```

Idempotentné cez `LEFT JOIN invoices i ON i.order_id = o.id WHERE i.id IS NULL`. Cancelled invoices sa rátajú ako "neexistujú" — order môže dostať ďalšiu novú.

### Storage

- `storage/invoices/{YYYY}/{MM}/{number}.pdf` — mimo webroot, gitignored okrem `.gitkeep`.
- PDF write **PO** db commit — orphan súbory akceptovateľné, orphan DB rows nie.
- Nginx/Apache musí blokovať priame požiadavky na `/storage/`.

---

## Tax / VAT compliance — VatService (Roadmap 1.3)

EÚ-compliant VAT engine: per-line snapshot, history-aware sadzby, B2B reverse-charge,
OSS pre cross-border B2C, VIES validácia, OSS report. Branch `feat/tax-vat`.

### Komponenty

| Súbor | Účel |
|---|---|
| `config/vat-rates.json` | 27 EU krajín × tried (standard/reduced/reduced2/zero) s `valid_from`+`history` (SK 23 % od 2025-01-01, history 20 %; CZ 12 %, history 15 %; FI 25.5 %, EE 22 %, LU 17 %, …) |
| `src/VatService.php` | Engine: `rate()`, `resolveScenario()`, `computeLine()`, `buildSnapshot()`, `validateSnapshot()`, `validateVatId()`, `resolveClass()`, `isEuCountry()` |
| `adapters/TaxProvider.php` | Kanonický kontrakt pre sadzbu, scenár a immutable finančný snapshot |
| `adapters/VatIdValidator.php` | Adapter interface (`check(vatId)`) |
| `adapters/contrib/MockVatIdValidator.php` | Deterministický mock pre testy/dev |
| `adapters/contrib/ViesVatIdValidator.php` | VIES SOAP klient (5 s timeout, graceful unverified) |
| `cli/oss-report.php` | Kvartálny OSS report (`--quarter=2026-Q2 --format=text|csv|json`) |
| `cli/vat-backfill.php` | Legacy order backfill (`--dry-run` default, `--apply` s auto pre-backup) |
| `migrations/20260425013405_add_vat_columns.php` | Schema migration (17 stĺpcov + `vat_id_cache` table) |
| `migrations/20260801141000_financial_snapshots.php` | Nullable `orders.financial_snapshot` a `invoices.financial_snapshot` pre nový kanonický tok |

### Schema (migration `add_vat_columns`)

| Tabuľka | Pridané |
|---|---|
| `products` | `vat_class TEXT NOT NULL DEFAULT 'standard'` |
| `order_items` | `vat_class`, `vat_rate REAL`, `vat_amount INTEGER`, `net_amount INTEGER` |
| `orders` | `vat_scenario TEXT`, `vat_total INTEGER`, `net_total INTEGER`, nullable `financial_snapshot TEXT` |
| `customers` | `vat_id TEXT`, `vat_country TEXT`, `vat_validated_at TEXT` |
| `invoices` | `type` (`invoice`\|`credit_note`), `refers_to_invoice_id`, `vat_scenario`, `reverse_charge_note_key`, nullable `financial_snapshot TEXT` |
| `invoice_counters` | `type` (per-typ sequence) |
| `vat_id_cache` (NEW) | `vat_id`, `country`, `status` (`valid`/`invalid`/`unverified`), `trader_name`, `checked_at`, `ttl_seconds` (24h valid/invalid, 1h unverified) |
| `stores.json` | per-store `oss_enabled: false` (opt-in) |

### Reverse-charge resolver (5 scenárov)

`VatService::resolveScenario(ctx)` vracia `{scenario, vat_rate, applies_vat, note_key, rate_country}`:

| Scenario | Podmienka | Rate country | applies_vat | note_key |
|---|---|---|---|---|
| `domestic` | seller_country == customer_country | seller | true | — |
| `reverse_charge` | B2B intra-EU + valid VAT ID | customer | false | `vat.reverse_charge_note` |
| `oss` | cross-border B2C, EU, store `oss_enabled=true` | customer | true | — |
| `domestic_subthreshold` | cross-border B2C, EU, OSS off | seller | true | — |
| `export` | customer mimo EU | customer | false | `vat.export_note` |

### Kanonický finančný snapshot (gross-inclusive)

`VatService::buildSnapshot()` po výbere kupónu, dopravy a platby zmrazí scenár, dátum sadzieb, menu, produkty, deterministicky alokovanú zľavu, dopravu, platobný poplatok, breakdown a totals. Sumy sú integer centy, sadzby integer basis points a rounding je `gross_line_half_up_integer_basis_points`; SHA-256 `fingerprint` chráni celý kanonicky zoradený obsah.

`Orders::create()` overí fingerprint aj zhodu snapshotu s reálne rezervovaným subtotalom, menou, zľavou, dopravou a poplatkom. Faktúra kopíruje presne ten istý JSON a platobný request nesie jeho fingerprint. Nové objednávky sa už downstream neprepočítavajú; legacy riadky s `financial_snapshot IS NULL` zostávajú čitateľné cez pôvodný invoice fallback.

### VIES cache (TTL-aware)

`VatService::validateVatId(vatId, validator)`:
1. Lookup `vat_id_cache` → ak `age < ttl_seconds`, vráť cached (`cached=true`)
2. Inak volaj `validator->check()` → upsert do cache so správnym TTL (`valid`/`invalid`=86400, `unverified`=3600)
3. Service down/timeout → `unverified` s krátkym TTL → retry-friendly

### OSS report

`php cli/oss-report.php --quarter=YYYY-Qn --format=text|csv|json` — group by `(consumption_country, vat_rate, currency)` len pre `vat_scenario=oss` faktúry. Zdrojom je immutable `invoices.financial_snapshot.breakdown` (vrátane alokovanej zľavy a dopravy), pri historickom `NULL` uložený `invoices.vat_breakdown`; report nikdy nepočíta z aktuálnych sadzieb ani zo surových `order_items`. Domestic vylúčené. Deterministický ordering. Empty period → exit 1.

### Backfill (legacy orders)

`php cli/vat-backfill.php` (dry-run default) → `--apply` triggeruje pre-backup cez BackupService, recomputuje VAT z aktuálnych sadzieb a vyplní `orders.vat_scenario`/`vat_total`/`net_total` + `order_items.vat_*` na základe `customers.vat_id` + adresy.

### Gotchas

- **Celý finančný výpočet je snapshot pri vytvorení objednávky** — neskoršia zmena `config/vat-rates.json`, kupónov, dopravy alebo payment fees neovplyvňuje objednávku, platbu, faktúru ani OSS. Nezhoda fingerprintu je hard failure.
- **VAT ID cache TTL**: 24 h pre `valid`/`invalid`, 1 h pre `unverified` — aby sa retry stíhol skôr.
- **VIES timeout = unverified**, nie B2C fallback. Operator si v admin musí overiť manuálne.
- **OSS opt-in per-store** — counter (€10 000 prah) je P1, v P0 si to operator sleduje sám.
- **Reverse-charge potrebuje obe**: `is_b2b=true` AND `customer_vat_id_valid=true`. Single field nestačí (chráni pred lživým B2B).

### Súhrn

| Metrika | Hodnota |
|---|---|
| Počet PHP súborov | ~30 |
| Celkový PHP kód | Odhad ~6,000 riadkov (validovať po Phase 2) |
| Frontend JS | ~800 riadkov |
| HTML šablóny (blocks) | ~30 súborov |
| JSON definície | 6 súborov (catalog, stores, layouts, pricing, shipping, tax) |
| Adapter interfaces | 5 (search, queue, payment, storage, integration) |
| Externé závislosti | 0 (okrem Stripe JS pre platby) |
| Databáza | SQLite (default) alebo MySQL/MariaDB |
| Jazyky | Neobmedzené (BBF lang systém) |
| Product types | Neobmedzené (catalog.json) |
| Max produktov (cieľ) | 1,000,000+ (validovať v Phase 5 benchmarku) |
| Response time (cieľ) | <5ms (cache), <20ms (DB) — benchmarkovať pred commitom |
| Útočná plocha | PHP + 1 databáza. Žiadny Elasticsearch, Redis, Varnish, RabbitMQ. |
| Scope | Core B2C commerce (katalóg, košík, checkout, objednávky, multi-lang) |
| Magento kód | ~2,000,000 riadkov |
| **BareBones kód** | **~6,000 riadkov** |
| Extension points | 5 adapter interfaces, vymeniteľné bez zmeny core |
| ERP integrácia | Adapter pattern (Money, Pohoda, FlexiBee, custom) |
| Deploy | Skopíruj adresár + config.php + schema:build. Hotovo. |
