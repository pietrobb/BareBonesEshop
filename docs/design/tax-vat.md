# Tax / VAT Compliance — Design Document (Roadmap 1.3)

**Status:** Draft (Fáza 1) — awaiting approval before Fáza 2 (implementation)
**Branch:** `feat/tax-vat`
**Target tag:** `v1.8.0`
**Depends on:** Invoices (`v1.6.0`), Customer Accounts (`v1.4.0`), Migrations (`v1.5.0`)

---

## 0. Východiskový stav (čo už máme)

- `src/Tax.php` — rate lookup z `tax.json`, extrakcia VAT z gross ceny, `orderBreakdown()` per rate.
- `tax.json` — flat per-country rates (SK 20, CZ 21, DE 19, AT 20, PL 23, HU 27) + product_tax_class map.
  ⚠️ SK má v `tax.json` `standard: 20` — od 1.1.2025 je SK štandard **23 %**. Treba refresh.
- `invoices` schema — `vat_rate REAL`, `vat_amount INTEGER`, `subtotal_net`, `reverse_charge_text`, `legal_seller_*`, `legal_customer_*`. Reverse-charge text už existuje, no logika je primitívna.
- `stores.json` — per-store `legal.country`, `legal.ic_dph` (VAT ID predajcu), `currency`.
- `customers` table — **nemá** `vat_id` ani `vat_validated_at` (chýba pre B2B).
- `order_lines` (alias `order_items`) — **nemá** `vat_rate` ani `vat_amount` per line.
- Žiadne OSS, žiadne VIES, žiadne credit notes.

---

## 1. Scope (P0)

| Modus | Pokryté v P0 | Pozn. |
|---|---|---|
| **B2C goods, in-country** | ✅ | sadzba krajiny zákazníka, prices include VAT |
| **B2C goods, intra-EU (OSS)** | ✅ | OSS-enabled store → sadzba krajiny zákazníka; OSS-disabled → sadzba krajiny predajcu (sub-threshold) |
| **B2B goods, intra-EU + reverse-charge** | ✅ | validný VAT ID v inej EÚ krajine → 0 % VAT, text "Reverse charge" |
| **B2B goods, in-country (rovnaká krajina)** | ✅ | normálna sadzba (reverse-charge sa neuplatňuje) |
| **B2C/B2B goods, mimo EÚ (export)** | ✅ | 0 % VAT (export), text "Export — VAT exempt" |
| **Digital services (OSS)** | ⚠️ čiastočne | tá istá OSS logika ako goods; products dostávajú flag `is_digital` v P1 (zatial nerozlišujeme miestne pravidlá) |
| **Margin scheme (použité tovary)** | ❌ P2 | mimo scope |
| **Triangular trade** | ❌ P2 | mimo scope |
| **MOSS (legacy)** | ❌ | nahradené OSS od 2021 |

---

## 2. Odpovede na 10 otázok

### 1) VAT modely — pokryté v P0

B2C goods + B2B intra-EU reverse-charge + export 0 %. Digital služby zdieľajú OSS logiku, ale vlastné domain-specific pravidlá (Place of supply, MOSS-style) **nerozlišujeme** v P0 — produkt je goods alebo digital len na účely budúcich rozšírení (`products.is_digital BOOLEAN DEFAULT 0`).

### 2) OSS threshold — **Variant A (per-store flag)**

`stores.json[X].oss_enabled: true|false` (default false → sub-threshold = sadzba krajiny predajcu).
Operator si flag prepne ručne, keď v aktuálnom roku presiahne €10 000 cross-border. **Variant B (auto counter z faktúr)** odmietnutý ako príliš zložitý pre P0 — limity musia byť legálne presné, nie heuristické.

Counter ako **read-only diagnostika** dostupný cez `cli/oss-report.php --threshold-check` (v P1) — informuje, no neprepína flag automaticky.

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
- `EU` — pole ISO kódov, pre detekciu intra-EU.
- `rates` — aktuálne sadzby per krajina × per VAT class (`standard`, `reduced`, `reduced2`, `zero`).
- `history` — voliteľné staré sadzby (lookup podľa `issue_date` faktúry — pre backfill / preprintovanie starých faktúr).
- `default` — fallback ak krajina nie je v `rates`.
- `valid_from` na úrovni krajiny = od kedy platí aktuálna sadzba (default ak nie je `history`).

`tax.json` zostáva (legacy backward-compat shim). `Tax::rate()` deleguje na `VatService::rate()` v P0.

### 4) VAT ID validation — **VIES + adapter pattern, cache 24h**

```
adapters/VatIdValidator.php           // interface
adapters/contrib/ViesValidator.php    // SOAP/REST call (real)
adapters/contrib/MockVatValidator.php // for tests
adapters/contrib/CachedVatValidator.php // wraps another, 24h TTL
```

`vat_id_cache (vat_id PRIMARY KEY, country, valid INTEGER, name TEXT, address TEXT, validated_at TEXT, raw_response TEXT)` tabuľka — single row per VAT ID.

V `cli/test.php` výhradne `MockVatValidator`. V produkcii sa cez config `config/vat.json.validator` zvolí `vies` alebo `mock`. ViesValidator má hard 5 s timeout — pri chybe siete nezamykneme checkout, vrátime "unverified" a B2B sa fallne na B2C sadzbu.

✅ **Súhlasím.**

### 5) Per-line vs per-order — **per-line**

Schema:
```sql
ALTER TABLE order_items ADD COLUMN vat_class      TEXT    DEFAULT 'standard';
ALTER TABLE order_items ADD COLUMN vat_rate       REAL    DEFAULT 0;
ALTER TABLE order_items ADD COLUMN vat_amount     INTEGER DEFAULT 0;  -- cents
ALTER TABLE order_items ADD COLUMN net_amount     INTEGER DEFAULT 0;  -- cents (line total ex VAT)
```

`InvoiceService::issue()` **agreguje** z lines, nie znovu počíta. Single source of truth. Ak existujú legacy lines bez `vat_*`, fallback dopočíta z `Tax::orderBreakdown()` ako dnes (kompatibilita).

✅ **Súhlasím.**

### 6) Storno faktúra (credit note)

Schema:
```sql
ALTER TABLE invoices ADD COLUMN type               TEXT DEFAULT 'invoice';        -- 'invoice' | 'credit_note'
ALTER TABLE invoices ADD COLUMN refers_to_invoice_id INTEGER DEFAULT NULL;        -- FK invoices.id
```

- Originál ostáva immutable (per existing rule).
- Credit note má **opačné znamienka** v `subtotal_net`, `vat_amount`, `total_gross`.
- Číselný rad je zdieľaný (next number, ale `type` je 'credit_note', a number má prefix `CN-` ak `stores.legal.credit_note_prefix` je nastavený, alebo iba poradové číslo). Default — zdieľaný číselný rad bez prefixu.
- PDF využíva rovnaký template, len header label "Dobropis / Credit Note" (multi-locale).

P0 = backend (`InvoiceService::issueCreditNote($invoiceId, $reason)`) + admin tlačítko v order detail. Auto-hook z refund flow je **P1** (cestou cez `OrderWorkflow::handleRefund()`).

✅ **Súhlasím s návrhom.**

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

Logika:
- `SELECT FROM invoices WHERE issue_date BETWEEN Q_start AND Q_end`
- `LEFT JOIN order_items ON oi.order_id = i.order_id`
- iba **OSS-relevant** faktúry (cross-border within EU, store has `oss_enabled=true` v čase issue_date)
- group by `customer_country, vat_rate`

Idempotencia: vstup = quarter string + DB stav → output deterministický (žiadne `now()`, žiadne mutácie).

`--format=json` aj ako alternatíva.
`--out=/path/file.csv` voliteľné, default stdout.

✅ **Súhlasím.**

### 8) Backwards compatibility — **derive + default**

Existujúce orders bez `vat_rate` na lines:
- `vat_amount`/`net_amount`/`vat_rate` na orders aj order_items budú `DEFAULT 0`.
- Migration spustí **backfill**: pre každý existujúci order spočíta `vat_rate` z `Tax::rate(billing_country, default)` a rozdistribuuje VAT z `total_gross` do per-line `vat_amount` proporcionálne k `total` riadku.
- Backfill nie je v hot path migration — nový CLI `cli/vat-backfill.php` (s `--dry-run`), aby sa migration baseline nestala pomalou. Migration len pridá stĺpce.

✅ **Súhlasím.**

### 9) Predmet/produkt mapping — **`products.vat_class`**

```sql
ALTER TABLE products ADD COLUMN vat_class TEXT DEFAULT 'standard';
-- enum-like: 'standard' | 'reduced' | 'reduced2' | 'zero'
```

Default `'standard'`. Per-store admin UI dá dropdown. Existujúce `tax.json.product_tax_class` (`food`, `books`, `digital`, `baby`) ostáva ako **legacy alias** v `Tax::rate()` shim — mapuje sa na nové enum hodnoty.

Per-country mapping je v `vat-rates.json` (knihy môžu byť v SK reduced 5 %, v DE reduced 7 %). To znamená: produkt má `vat_class='reduced'` (abstraktné), country diktuje konkrétne %.

✅ **Súhlasím.**

### 10) Storefront display + reverse-charge text

- B2C / nezalogovaný: **gross** ceny (vrátane VAT) — to je dnešné správanie.
- B2B logged-in s validným VAT ID v inej EÚ krajine: storefront prepne na **net** ceny + indikátor "Excl. VAT — reverse charge applies on invoice". (Per-store flag `b2b_show_net: true` — default false → vždy gross.)
- Per-store config: `prices_include_vat` už neexistuje explicitne — implicitne true (Tax::pricesIncludeTax() default true). Pridá sa `b2b_show_net` ako voliteľný flag.

Reverse-charge na faktúre:
- detection: `customer.vat_id != null && customer_country in EU && customer_country != seller_country && vat_id_valid == true`
- ak true: invoice `vat_rate=0`, `vat_amount=0`, `reverse_charge=true`, text v invoice footer (lokalizovaný cez `lang/*.php` kľúče `invoice.reverse_charge.text`):
  - SK: "Daň odvádza zákazník (§ 69 ods. 1 zákona o DPH)."
  - CS: "Daň odvede zákazník (reverse-charge)."
  - EN: "Reverse charge — VAT to be paid by the recipient."
  - DE: "Steuerschuldnerschaft des Leistungsempfängers (Reverse-Charge-Verfahren)."
  - PL: "Odwrotne obciążenie — VAT rozlicza nabywca."
  - HU: "Fordított adózás — az adót a vevő fizeti meg."

✅ **Súhlasím.**

---

## 3. Architektúra

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
       ┌──────────────────┼──────────────────┐
       ▼                  ▼                  ▼
┌──────────────┐  ┌──────────────┐  ┌──────────────────┐
│ Cart::recalc │  │InvoiceService│  │ cli/oss-report   │
│ (sets line   │  │ (reads from  │  │ (aggregates from │
│  vat fields) │  │  order_items)│  │  invoices joins) │
└──────────────┘  └──────────────┘  └──────────────────┘

┌─────────────────────────────────────────────────────────────┐
│ src/VatIdValidator.php (interface)                          │
│   ViesValidator (SOAP/REST, 5s timeout)                     │
│   MockVatValidator (config-driven response, for tests)      │
│   CachedVatValidator (wraps any, vat_id_cache TTL 24h)      │
└─────────────────────────────────────────────────────────────┘
```

### Reverse-charge resolver (rozhodovacia tabuľka)

| Zákazník v EÚ? | Rovnaká krajina ako predajca? | Má validné VAT ID? | OSS enabled? | Výsledok |
|---|---|---|---|---|
| Nie (export) | — | — | — | `export` (rate 0, no reverse-charge text, "Export" note) |
| Áno | Áno | — | — | `domestic` (rate = sellerCountry × class) |
| Áno | Nie | Áno | — | `reverse_charge` (rate 0, RC text) |
| Áno | Nie | Nie | Áno | `oss` (rate = customerCountry × class) |
| Áno | Nie | Nie | Nie | `domestic_subthreshold` (rate = sellerCountry × class) |

Toto je cleanup od dnešnej "billing_country = customer_country, rate from tax.json" — bez OSS / reverse-charge logiky.

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
ALTER TABLE orders ADD COLUMN vat_customer_id TEXT DEFAULT NULL;        -- snapshot z customer.vat_id

-- Invoices: credit note support
ALTER TABLE invoices ADD COLUMN type TEXT DEFAULT 'invoice';            -- 'invoice'|'credit_note'
ALTER TABLE invoices ADD COLUMN refers_to_invoice_id INTEGER DEFAULT NULL;
```

`stores.json` patch (manual, nie migration):
```json
"sk": { ..., "oss_enabled": true, "b2b_show_net": false }
```

---

## 5. Súbory ktoré pribudnú / sa zmenia

| Cesta | Akcia | Poznámka |
|---|---|---|
| `config/vat-rates.json` | NEW | per-country sadzby + history |
| `migrations/<TS>_vat_compliance.php` | NEW | schema (cca 18 ALTER + 1 CREATE) |
| `src/VatService.php` | NEW | rate, resolveScenario, calcLine, aggregate, ~280 LoC |
| `src/VatIdValidator.php` (interface) | NEW | adapter pattern |
| `adapters/contrib/ViesValidator.php` | NEW | SOAP/REST + 5s timeout |
| `adapters/contrib/MockVatValidator.php` | NEW | config-driven, for tests |
| `adapters/contrib/CachedVatValidator.php` | NEW | wraps another |
| `src/Tax.php` | MODIFY | shim → VatService |
| `src/Cart.php` (alebo `Cart::recalculate()` ak existuje) | MODIFY | per-line vat_* sets |
| `src/InvoiceService.php` | MODIFY | aggregate from lines + credit note + reverse-charge |
| `src/Orders.php` | MODIFY | persist vat_scenario, vat_*_country snapshot |
| `cli/oss-report.php` | NEW | CLI quarterly CSV |
| `cli/vat-backfill.php` | NEW | --dry-run, retro-fill order_items.vat_* |
| `admin.php` | MODIFY | products vat_class dropdown, customer vat_id field + VIES, credit note button |
| `account.php` | MODIFY | vat_id field + verify badge |
| `index.php` | MODIFY | b2b net/gross switch (small) |
| `lang/{6}.php` | MODIFY | `vat.*` keys (~20), reverse_charge text |
| `cli/test.php` | MODIFY | ≥18 nových testov |
| `cli/schema-build.php` | MODIFY | (žiadne — migration framework rieši) |
| `ESHOP-DESIGN.md` | MODIFY | new section "VAT compliance" |

Estimated diff: **~2000 +, ~50 −** across ~22 súborov.

---

## 6. Testy (≥18, target ~22)

1. VatService rate SK 2025: `rate('SK','standard') === 23.0`
2. VatService rate SK 2024 (history): `rate('SK','standard',date('2024-06-01')) === 20.0`
3. VatService rate fallback (default): `rate('XX','standard') === 20.0`
4. resolveScenario domestic: SK seller, SK customer, no VAT ID → `domestic`, rate=23
5. resolveScenario reverse-charge: SK seller, DE customer, valid DE VAT ID → `reverse_charge`, rate=0
6. resolveScenario OSS: SK seller, DE customer, no VAT ID, OSS=true → `oss`, rate=19 (DE std)
7. resolveScenario sub-threshold: SK seller, DE customer, no VAT ID, OSS=false → `domestic_subthreshold`, rate=23
8. resolveScenario export: SK seller, US customer → `export`, rate=0, no RC text
9. calcLine reduced class: book in PL, `vat_class='reduced'` → 8 %
10. aggregate multi-rate cart: 2 books (8%) + 1 electronics (23%) → breakdown má 2 entries
11. Per-line totals match invoice: cart with 3 items → invoice total_net + vat = sum lines
12. VIES MockVatValidator hit: pre-seeded valid → returns `valid=true`
13. VIES MockVatValidator miss: unknown VAT ID → returns `valid=false`
14. CachedVatValidator cache hit (no second call to underlying)
15. CachedVatValidator cache expiry > 24h → re-validates
16. Credit note: issue → opposite signs, `type='credit_note'`, `refers_to_invoice_id` set
17. Credit note: original invoice unchanged (immutable)
18. OSS report aggregation: 3 DE invoices, 2 AT invoices → CSV grouping correct
19. OSS report idempotent: re-run → same hash
20. VAT backfill dry-run: counts but mutates nothing
21. VAT backfill apply: lines get vat_rate from country/default
22. Reverse-charge invoice text rendered in PDF (sk/en) — placeholder check

Pre-existing 588 tests must remain green. Goal: **610/610**.

---

## 7. Granulárne commity (plán dodávky)

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

Po finálnom reporte → operator merge + tag `v1.8.0` + push autonómne.

---

## 8. Otvorené body / explicitné ne-decisions

- **MOSS vs OSS:** používame OSS (post-2021). Žiadny legacy mode.
- **VIES SOAP:** ak Nimbus/Soa zlyhá — fallback na REST endpoint VIES (od 2024). Pri chybe oboch: `valid=false, unverified=true`, B2B sa fallne na B2C správanie (defenzívne — radšej zaplatí VAT, ako by sme mu nesprávne dali reverse-charge).
- **Currency conversion v OSS reporte:** v P0 reportujeme **v mene faktúry** (EUR pre SK store, CZK pre CZ store). Konverzia na EUR pre OSS deklaráciu (povinný formát) = P1.
- **Ireland +0 % na e-knihy, France +5,5 %** atď. — `vat_class='reduced2'` v `vat-rates.json` rieši, ale per-product mapping (akú knihu kde priradiť) je manuálna admin starosť.
- **Distance selling pre digital pred 2021:** ignorujeme.
- **B2B v rovnakej krajine (SK→SK s VAT ID):** normálna sadzba, reverse-charge sa neuplatňuje (správne, podľa § 69 zákona o DPH SK; pravidlo je "odberateľ v inom členskom štáte").

---

## 9. Riziká

| Riziko | Mitigácia |
|---|---|
| Backfill source-truth ambiguity (existujúce orders môžu mať wrong country) | `vat-backfill.php --dry-run` + audit log |
| VIES rate-limiting (real prod) | CachedVatValidator 24h TTL + 5s timeout + graceful fallback |
| Sadzby sa menia (SK 2025 +3 pp) | `valid_from`/`history` v `vat-rates.json` |
| Nesprávny scenario resolution (false reverse-charge) | hard test scenarios per row v rozhodovacej tabuľke |
| Credit note "porušenie" pôvodnej faktúry | originál nikdy nemodifikovaný; FK constraint `refers_to_invoice_id` |

---

**End of design — awaiting green light pred Fázou 2.**
