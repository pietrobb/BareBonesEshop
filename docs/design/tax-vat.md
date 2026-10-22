# Tax / VAT Compliance — Design Document (Roadmap 1.3)

**Status:** Draft (Phase 1) — awaiting approval before Phase 2 (implementation)
**Branch:** `feat/tax-vat`
**Target tag:** `v1.8.0`
**Depends on:** Invoices (`v1.6.0`), Customer Accounts (`v1.4.0`), Migrations (`v1.5.0`)

---

## 0. Starting point (what we already have)

- `src/Tax.php` — rate lookup from `tax.json`, VAT extraction from a gross price, `orderBreakdown()` per rate.
- `tax.json` — flat per-country rates (SK 20, CZ 21, DE 19, AT 20, PL 23, HU 27) + product_tax_class map.
  ⚠️ SK has `standard: 20` in `tax.json` — since 1 Jan 2025 the SK standard rate is **23 %**. Needs a refresh.
  (Round 8, 2026-10: `src/Tax.php` and `tax.json` are removed — rates come from `VatService`; the only flag still
  read, `prices_include_tax`, is a `config.php` key.)
- `invoices` schema — `vat_rate REAL`, `vat_amount INTEGER`, `subtotal_net`, `reverse_charge_text`, `legal_seller_*`, `legal_customer_*`. A reverse-charge text already exists, but the logic is primitive.
- `stores.json` — per-store `legal.country`, `legal.ic_dph` (the seller's VAT ID), `currency`.
- `customers` table — **has no** `vat_id` or `vat_validated_at` (missing for B2B).
- `order_lines` (alias `order_items`) — **has no** `vat_rate` or `vat_amount` per line.
- No OSS, no VIES, no credit notes.

---

## 1. Scope (P0)

| Mode | Covered in P0 | Note |
|---|---|---|
| **B2C goods, in-country** | ✅ | the customer's country rate, prices include VAT |
| **B2C goods, intra-EU (OSS)** | ✅ | OSS-enabled store → the customer's country rate; OSS-disabled → the seller's country rate (sub-threshold) |
| **B2B goods, intra-EU + reverse charge** | ✅ | a valid VAT ID in another EU country → 0 % VAT, text "Reverse charge" |
| **B2B goods, in-country (same country)** | ✅ | normal rate (reverse charge does not apply) |
| **B2C/B2B goods, outside the EU (export)** | ✅ | 0 % VAT (export), text "Export — VAT exempt" |
| **Digital services (OSS)** | ⚠️ partially | the same OSS logic as goods; products get an `is_digital` flag in P1 (we do not distinguish local rules yet) |
| **Margin scheme (used goods)** | ❌ P2 | out of scope |
| **Triangular trade** | ❌ P2 | out of scope |
| **MOSS (legacy)** | ❌ | replaced by OSS since 2021 |

---

## 2. Answers to 10 questions

### 1) VAT models — covered in P0

B2C goods + B2B intra-EU reverse charge + export 0 %. Digital services share the OSS logic, but their own domain-specific rules (place of supply, MOSS-style) are **not distinguished** in P0 — a product is goods or digital only for future extensions (`products.is_digital BOOLEAN DEFAULT 0`).

### 2) OSS threshold — **Variant A (per-store flag)**

`stores.json[X].oss_enabled: true|false` (default false → sub-threshold = the seller's country rate).
The operator switches the flag manually once cross-border sales in the current year exceed €10,000. **Variant B (an automatic counter from invoices)** was rejected as too complex for P0 — the limits must be legally exact, not heuristic.

A counter as **read-only diagnostics** is available through `cli/oss-report.php --threshold-check` (in P1) — it informs, but does not switch the flag automatically.

### 3) VAT rates table — **`config/vat-rates.json`**

```json
{
  "EU": ["AT","BE","BG","CY","CZ","DE","DK","EE","ES","FI","FR","GR","HR","HU","IE","IT","LT","LU","LV","MT","NL","PL","PT","RO","SE","SI","SK"],
  "rates": {
    "SK": {
      "standard": 23,
      "reduced": 19,
      "reduced2": 5,
      "zero": 0,
      "valid_from": "2025-01-01"
    },
    "CZ": { "standard": 21, "reduced": 12,  "zero": 0, "valid_from": "2024-01-01" },
    "DE": { "standard": 19, "reduced": 7,   "zero": 0, "valid_from": "2007-01-01" },
    "AT": { "standard": 20, "reduced": 10,  "reduced2": 13, "zero": 0, "valid_from": "2016-01-01" },
    "PL": { "standard": 23, "reduced": 8,   "reduced2": 5,  "zero": 0, "valid_from": "2011-01-01" },
    "HU": { "standard": 27, "reduced": 18,  "reduced2": 5,  "zero": 0, "valid_from": "2012-01-01" }
  },
  "history": {
    "SK": [
      {"standard": 20, "reduced": 10, "valid_from": "2011-01-01", "valid_to": "2024-12-31"}
    ]
  },
  "default": { "standard": 20, "reduced": 10, "zero": 0 }
}
```

Schema:
- `EU` — an array of ISO codes, for intra-EU detection.
- `rates` — current rates per country × per VAT class (`standard`, `reduced`, `reduced2`, `zero`).
- `history` — optional old rates (lookup by the invoice `issue_date` — for backfill / reprinting old invoices).
- `default` — fallback if the country is not in `rates`.
- `valid_from` at the country level = since when the current rate applies (the default if there is no `history`).

`tax.json` stays (a legacy backward-compat shim). `Tax::rate()` delegates to `VatService::rate()` in P0.

### 4) VAT ID validation — **VIES + adapter pattern, 24h cache**

```
adapters/VatIdValidator.php           // interface
adapters/contrib/ViesValidator.php    // SOAP/REST call (real)
adapters/contrib/MockVatValidator.php // for tests
adapters/contrib/CachedVatValidator.php // wraps another, 24h TTL
```

The `vat_id_cache (vat_id PRIMARY KEY, country, valid INTEGER, name TEXT, address TEXT, validated_at TEXT, raw_response TEXT)` table — a single row per VAT ID.

`cli/test.php` uses `MockVatValidator` only. In production the config `config/vat.json.validator` selects `vies` or `mock`. ViesValidator has a hard 5 s timeout — on a network error we do not lock the checkout, we return "unverified" and B2B falls back to the B2C rate.

✅ **Agreed.**

### 5) Per-line vs per-order — **per-line**

Schema:
```sql
ALTER TABLE order_items ADD COLUMN vat_class      TEXT    DEFAULT 'standard';
ALTER TABLE order_items ADD COLUMN vat_rate       REAL    DEFAULT 0;
ALTER TABLE order_items ADD COLUMN vat_amount     INTEGER DEFAULT 0;  -- cents
ALTER TABLE order_items ADD COLUMN net_amount     INTEGER DEFAULT 0;  -- cents (line total ex VAT)
```

`InvoiceService::issue()` **aggregates** from the lines, it does not recompute. A single source of truth. If legacy lines without `vat_*` exist, the fallback computes them from `Tax::orderBreakdown()` as today (compatibility).

✅ **Agreed.**

### 6) Cancellation invoice (credit note)

Schema:
```sql
ALTER TABLE invoices ADD COLUMN type               TEXT DEFAULT 'invoice';        -- 'invoice' | 'credit_note'
ALTER TABLE invoices ADD COLUMN refers_to_invoice_id INTEGER DEFAULT NULL;        -- FK invoices.id
```

- The original stays immutable (per the existing rule).
- A credit note has **opposite signs** in `subtotal_net`, `vat_amount`, `total_gross`.
- Every seller numbers each document type in its own gapless series per year and prefix (`invoice_number_counters`, keyed by seller, type, year, prefix). Credit notes use `stores.legal.credit_note_prefix`, by default `<invoice_prefix>CN-` (`CN-` for a seller without an invoice prefix).
- Who is a seller (`InvoiceService::sellerCode()`): a store with its own non-empty `legal` block. A store **without** a `legal` block is no seller of its own and invoices as the seller selling in the order's currency, else as the default store's seller (as before per-seller numbering) — it shares that seller's series and counter and prints its legal details. Storefronts of one company can share one series explicitly: `"legal": {"seller": "default"}` makes the store invoice in the `default` store's series (its other `legal` fields only override the printed seller details; its own prefixes are ignored).
- Two sellers' series may never share a prefix. `InvoiceService::numberingConflicts()` lists the series that are refused; a shared prefix belongs to the default store's seller (its `''` / `CN-` series keep issuing), the other sellers sharing it are refused, as is a `legal.seller` that names no seller. `php cli/setup.php doctor` (`stores.invoice_numbering`) and `php cli/staging-preflight.php` (`invoice_numbering`) report them before launch; at issue time a refused series fails the job with the configuration error and an `invoice.numbering_conflict` operational incident. A prefix another seller already numbers in that year is refused as well.
- Credit notes continue the series of their source invoice's seller, not of the order's current store: invoices record `seller_code` (migration `20261016161000`); for invoices issued before, the seller is inferred from the invoice number's prefix, else from the seller snapshot (ico + name), else the order's store.
- The legacy shared `invoice_counters` rows were given one owner each by migration `20261016140500_invoice_number_counters`: the seller owning the prefix by configuration (so `''` and `CN-` go to the default store's seller even when another store issued most documents under them); stores that historically issued under it and invoice as that seller continue in the same counter, so no number is issued twice. Until that migration runs, numbers come from `invoice_counters` (the migration carries them over).
- The PDF uses the same template, only the header label is "Credit Note" (multi-locale; the Slovak label is `Dobropis`).

P0 = the backend (`InvoiceService::issueCreditNote($invoiceId, $reason)`) + an admin button in the order detail. An automatic hook from the refund flow is **P1** (through `OrderWorkflow::handleRefund()`).

✅ **Agree with the proposal.**

### 7) OSS report — **CLI quarterly CSV**

```
php cli/oss-report.php --quarter=2026-Q2 --format=csv
```

Output:
```csv
country,vat_rate,net_total,vat_total,gross_total,invoice_count
DE,19.00,1234.56,234.57,1469.13,12
DE,7.00,89.10,6.24,95.34,3
AT,20.00,567.89,113.58,681.47,8
...
```

Logic:
- `SELECT FROM invoices WHERE issue_date BETWEEN Q_start AND Q_end`
- `LEFT JOIN order_items ON oi.order_id = i.order_id`
- only **OSS-relevant** invoices (cross-border within the EU, the store has `oss_enabled=true` at the issue_date)
- group by `customer_country, vat_rate`

Idempotency: input = the quarter string + DB state → deterministic output (no `now()`, no mutations).

`--format=json` as an alternative too.
`--out=/path/file.csv` optional, stdout by default.

✅ **Agreed.**

### 8) Backwards compatibility — **derive + default**

Existing orders without `vat_rate` on the lines:
- `vat_amount`/`net_amount`/`vat_rate` on orders and order_items will be `DEFAULT 0`.
- The migration runs a **backfill**: for every existing order it computes `vat_rate` from `Tax::rate(billing_country, default)` and distributes VAT from `total_gross` into per-line `vat_amount` proportionally to the line `total`.
- The backfill is not in the hot path of the migration — a new CLI `cli/vat-backfill.php` (with `--dry-run`), so the migration baseline does not become slow. The migration only adds columns.

✅ **Agreed.**

### 9) Subject/product mapping — **`products.vat_class`**

```sql
ALTER TABLE products ADD COLUMN vat_class TEXT DEFAULT 'standard';
-- enum-like: 'standard' | 'reduced' | 'reduced2' | 'zero'
```

Default `'standard'`. The per-store admin UI offers a dropdown. The existing `tax.json.product_tax_class` (`food`, `books`, `digital`, `baby`) stays as a **legacy alias** in the `Tax::rate()` shim — it maps onto the new enum values.

The per-country mapping is in `vat-rates.json` (books may be reduced 5 % in SK and reduced 7 % in DE). That means: a product has `vat_class='reduced'` (abstract), the country dictates the concrete %.

✅ **Agreed.**

### 10) Storefront display + reverse-charge text

- B2C / not logged in: **gross** prices (including VAT) — that is today's behavior.
- B2B logged in with a valid VAT ID in another EU country: the storefront switches to **net** prices + the indicator "Excl. VAT — reverse charge applies on invoice". (Per-store flag `b2b_show_net: true` — default false → always gross.)
- Per-store config: `prices_include_vat` no longer exists explicitly — implicitly true (`Tax::pricesIncludeTax()` defaults to true). `b2b_show_net` is added as an optional flag.

Reverse charge on the invoice:
- detection: `customer.vat_id != null && customer_country in EU && customer_country != seller_country && vat_id_valid == true`
- if true: invoice `vat_rate=0`, `vat_amount=0`, `reverse_charge=true`, a text in the invoice footer (localized through the `lang/*.php` key `invoice.reverse_charge.text`):
  - SK: `Daň odvádza zákazník (§ 69 ods. 1 zákona o DPH).`
  - CS: `Daň odvede zákazník (reverse-charge).`
  - EN: `Reverse charge — VAT to be paid by the recipient.`
  - DE: `Steuerschuldnerschaft des Leistungsempfängers (Reverse-Charge-Verfahren).`
  - PL: `Odwrotne obciążenie — VAT rozlicza nabywca.`
  - HU: `Fordított adózás — az adót a vevő fizeti meg.`

✅ **Agreed.**

---

## 3. Architecture

```
┌─────────────────────────────────────────────────────────────┐
│ config/vat-rates.json (per-country × class rates + history)│
└─────────────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│ src/VatService.php                                          │
│ ─ rate(country, class, date=now)                            │
│ ─ resolveScenario(seller, customer, hasValidVatId, oss)     │
│      → {applicable_country, rate, reverse_charge, export}   │
│ ─ calcLine(net|gross, country, class, scenario)             │
│ ─ aggregate(lines) → {breakdown[rate], total_net, total_vat}│
└─────────────────────────────────────────────────────────────┘
                          │
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│ buildSnapshot(items, context, discount/shipping/payment)    │
│ integer cents + basis points + deterministic rounding       │
│ canonical SHA-256 fingerprint                               │
└─────────────────────────────────────────────────────────────┘
                          │
       ┌──────────────────┼──────────────────┐
       ▼                  ▼                  ▼
┌──────────────┐  ┌──────────────┐  ┌──────────────────┐
│ Orders       │  │InvoiceService│  │ cli/oss-report   │
│ validates +  │  │ copies exact │  │ reads invoice    │
│ persists     │  │ snapshot     │  │ snapshot         │
└──────────────┘  └──────────────┘  └──────────────────┘

┌─────────────────────────────────────────────────────────────┐
│ src/VatIdValidator.php (interface)                          │
│   ViesValidator (SOAP/REST, 5s timeout)                     │
│   MockVatValidator (config-driven response, for tests)      │
│   CachedVatValidator (wraps any, vat_id_cache TTL 24h)      │
└─────────────────────────────────────────────────────────────┘
```

### Financial snapshot contract

`adapters/TaxProvider.php` defines the single contract and `VatService::buildSnapshot()` is its production implementation. Snapshot version 1 contains `effective_date`, `scenario`, `rate_country`, `components`, `breakdown`, `totals`, the rounding identifier and a SHA-256 `fingerprint`. A buy-X-get-Y discount stays on the free product's lines (`line_discounts`); the rest of the discount (coupon, other promotions) is split proportionally with the largest-remainder method. Shipping and the payment (e.g. cash-on-delivery) fee are part of the consideration for the goods and use the highest product rate. Product categories (`products.vat_class` such as `books`, `medicines`) may have their own rate per country and period in `config/vat-rates.json` `categories`; rates and wording are to be confirmed by the merchant's accountant.

The new flow is `checkout quote → Orders::create validation/persistence → payment fingerprint → InvoiceService exact copy → OSS invoice snapshot aggregation`. Only historical rows with `financial_snapshot IS NULL` use the original invoice fallback; their meaning is never changed retroactively.

### Reverse-charge resolver (decision table)

| Customer in the EU? | Same country as the seller? | Has a valid VAT ID? | OSS enabled? | Result |
|---|---|---|---|---|
| No (export) | — | — | — | `export` (rate 0, no reverse-charge text, "Export" note) |
| Yes | Yes | — | — | `domestic` (rate = sellerCountry × class) |
| Yes | No | Yes | — | `reverse_charge` (rate 0, RC text) |
| Yes | No | No | Yes | `oss` (rate = customerCountry × class) |
| Yes | No | No | No | `domestic_subthreshold` (rate = sellerCountry × class) |

This is a cleanup of today's "billing_country = customer_country, rate from tax.json" — without OSS / reverse-charge logic.

---

## 4. Schema changes (1 migration)

`migrations/<TS>_vat_compliance.php`:

```sql
-- Products
ALTER TABLE products ADD COLUMN vat_class TEXT DEFAULT 'standard';
ALTER TABLE products ADD COLUMN is_digital INTEGER DEFAULT 0;

-- Customers
ALTER TABLE customers ADD COLUMN vat_id TEXT DEFAULT NULL;
ALTER TABLE customers ADD COLUMN vat_id_country TEXT DEFAULT NULL;
ALTER TABLE customers ADD COLUMN vat_validated_at TEXT DEFAULT NULL;
ALTER TABLE customers ADD COLUMN vat_valid INTEGER DEFAULT 0;

-- VAT validation cache (shared across customers)
CREATE TABLE vat_id_cache (
  vat_id        TEXT PRIMARY KEY,
  country       TEXT NOT NULL,
  valid         INTEGER NOT NULL DEFAULT 0,
  name          TEXT,
  address       TEXT,
  validated_at  TEXT NOT NULL,
  raw_response  TEXT
);

-- Order lines: per-line VAT
ALTER TABLE order_items ADD COLUMN vat_class   TEXT    DEFAULT 'standard';
ALTER TABLE order_items ADD COLUMN vat_rate    REAL    DEFAULT 0;
ALTER TABLE order_items ADD COLUMN vat_amount  INTEGER DEFAULT 0;
ALTER TABLE order_items ADD COLUMN net_amount  INTEGER DEFAULT 0;

-- Orders: snapshot for invoice / OSS
ALTER TABLE orders ADD COLUMN vat_scenario   TEXT DEFAULT 'domestic';   -- 'domestic'|'reverse_charge'|'oss'|'export'|'domestic_subthreshold'
ALTER TABLE orders ADD COLUMN vat_seller_country TEXT DEFAULT NULL;
ALTER TABLE orders ADD COLUMN vat_customer_country TEXT DEFAULT NULL;
ALTER TABLE orders ADD COLUMN vat_customer_id TEXT DEFAULT NULL;        -- snapshot of customer.vat_id
ALTER TABLE orders ADD COLUMN financial_snapshot TEXT DEFAULT NULL;     -- versioned canonical JSON

-- Invoices: credit note support
ALTER TABLE invoices ADD COLUMN type TEXT DEFAULT 'invoice';            -- 'invoice'|'credit_note'
ALTER TABLE invoices ADD COLUMN refers_to_invoice_id INTEGER DEFAULT NULL;
ALTER TABLE invoices ADD COLUMN financial_snapshot TEXT DEFAULT NULL;   -- exact order snapshot copy
```

`stores.json` patch (manual, not a migration):
```json
"sk": { ..., "oss_enabled": true, "b2b_show_net": false }
```

---

## 5. Files that are added / changed

| Path | Action | Note |
|---|---|---|
| `config/vat-rates.json` | NEW | per-country rates + history |
| `migrations/<TS>_vat_compliance.php` | NEW | schema (about 18 ALTER + 1 CREATE) |
| `src/VatService.php` | NEW | rate, resolveScenario, calcLine, aggregate, ~280 LoC |
| `src/VatIdValidator.php` (interface) | NEW | adapter pattern |
| `adapters/contrib/ViesValidator.php` | NEW | SOAP/REST + 5s timeout |
| `adapters/contrib/MockVatValidator.php` | NEW | config-driven, for tests |
| `adapters/contrib/CachedVatValidator.php` | NEW | wraps another |
| `src/Tax.php` | MODIFY | shim → VatService |
| `src/Cart.php` (or `Cart::recalculate()` if it exists) | MODIFY | per-line vat_* sets |
| `src/InvoiceService.php` | MODIFY | aggregate from lines + credit note + reverse charge |
| `src/Orders.php` | MODIFY | persist vat_scenario, vat_*_country snapshot |
| `cli/oss-report.php` | NEW | CLI quarterly CSV |
| `cli/vat-backfill.php` | NEW | --dry-run, retro-fill order_items.vat_* |
| `admin.php` | MODIFY | products vat_class dropdown, customer vat_id field + VIES, credit note button |
| `account.php` | MODIFY | vat_id field + verify badge |
| `index.php` | MODIFY | b2b net/gross switch (small) |
| `lang/{6}.php` | MODIFY | `vat.*` keys (~20), reverse_charge text |
| `cli/test.php` | MODIFY | ≥18 new tests |
| `cli/schema-build.php` | MODIFY | (none — the migration framework handles it) |
| `ESHOP-DESIGN.md` | MODIFY | new section "VAT compliance" |

Estimated diff: **~2000 +, ~50 −** across ~22 files.

---

## 6. Tests (≥18, target ~22)

1. VatService rate SK 2025: `rate('SK','standard') === 23.0`
2. VatService rate SK 2024 (history): `rate('SK','standard',date('2024-06-01')) === 20.0`
3. VatService rate fallback (default): `rate('XX','standard') === 20.0`
4. resolveScenario domestic: SK seller, SK customer, no VAT ID → `domestic`, rate=23
5. resolveScenario reverse charge: SK seller, DE customer, valid DE VAT ID → `reverse_charge`, rate=0
6. resolveScenario OSS: SK seller, DE customer, no VAT ID, OSS=true → `oss`, rate=19 (DE std)
7. resolveScenario sub-threshold: SK seller, DE customer, no VAT ID, OSS=false → `domestic_subthreshold`, rate=23
8. resolveScenario export: SK seller, US customer → `export`, rate=0, no RC text
9. calcLine reduced class: book in PL, `vat_class='reduced'` → 8 %
10. aggregate multi-rate cart: 2 books (8%) + 1 electronics (23%) → the breakdown has 2 entries
11. Per-line totals match invoice: cart with 3 items → invoice total_net + vat = sum of the lines
12. VIES MockVatValidator hit: pre-seeded valid → returns `valid=true`
13. VIES MockVatValidator miss: unknown VAT ID → returns `valid=false`
14. CachedVatValidator cache hit (no second call to the underlying validator)
15. CachedVatValidator cache expiry > 24h → re-validates
16. Credit note: issue → opposite signs, `type='credit_note'`, `refers_to_invoice_id` set
17. Credit note: the original invoice is unchanged (immutable)
18. OSS report aggregation: 3 DE invoices, 2 AT invoices → CSV grouping correct
19. OSS report idempotent: re-run → same hash
20. VAT backfill dry-run: counts but mutates nothing
21. VAT backfill apply: lines get vat_rate from the country/default
22. Reverse-charge invoice text rendered in the PDF (sk/en) — placeholder check

The pre-existing 588 tests must remain green. Goal: **610/610**.

---

## 7. Granular commits (delivery plan)

1. `feat(vat): config/vat-rates.json + VatService skeleton`
2. `feat(vat): VatService rate/resolveScenario/calcLine/aggregate`
3. `feat(vat): VatIdValidator interface + Mock + Cached + ViesValidator`
4. `feat(vat): migration vat_compliance (products/customers/order_items/orders/invoices)`
5. `feat(vat): Cart per-line vat_* persistence`
6. `feat(vat): InvoiceService aggregates from lines + credit note support`
7. `feat(vat): cli/oss-report.php (CSV/JSON, quarterly)`
8. `feat(vat): cli/vat-backfill.php (--dry-run + apply)`
9. `feat(vat): admin UI vat_class dropdown + customer vat_id + credit-note button`
10. `feat(vat): account.php vat_id field + reverse-charge text in invoice (multi-locale)`
11. `feat(vat): Tax.php shim → VatService delegation`
12. `test(vat): 22 tests`
13. `docs(vat): ESHOP-DESIGN.md + HMOS nodes`

After the final report → the operator merges + tags `v1.8.0` + pushes autonomously.

---

## 8. Open points / explicit non-decisions

- **MOSS vs OSS:** we use OSS (post-2021). No legacy mode.
- **VIES SOAP:** if Nimbus/Soa fails — fall back to the VIES REST endpoint (since 2024). If both fail: `valid=false, unverified=true`, B2B falls back to B2C behavior (defensive — better that the customer pays VAT than that we wrongly grant reverse charge).
- **Currency conversion in the OSS report:** in P0 we report **in the invoice currency** (EUR for the SK store, CZK for the CZ store). Conversion to EUR for the OSS return (a mandatory format) = P1.
- **Ireland +0 % on e-books, France +5.5 %** etc. — `vat_class='reduced2'` in `vat-rates.json` handles it, but the per-product mapping (which book goes where) is a manual admin concern.
- **Distance selling for digital before 2021:** ignored.
- **B2B in the same country (SK→SK with a VAT ID):** normal rate, reverse charge does not apply (correct, under § 69 of the SK VAT Act; the rule is "a customer in another member state").

---

## 9. Risks

| Risk | Mitigation |
|---|---|
| Backfill source-of-truth ambiguity (existing orders may have a wrong country) | `vat-backfill.php --dry-run` + audit log |
| VIES rate limiting (real prod) | CachedVatValidator 24h TTL + 5s timeout + graceful fallback |
| Rates change (SK 2025 +3 pp) | `valid_from`/`history` in `vat-rates.json` |
| Wrong scenario resolution (false reverse charge) | hard test scenarios per row of the decision table |
| A credit note "breaking" the original invoice | the original is never modified; FK constraint `refers_to_invoice_id` |

---

**End of design — awaiting the green light before Phase 2.**
