# GDPR Compliance — Design Document (Phase 1)

**Branch:** `feat/gdpr` · **Roadmap:** 1.4 + 6.5 + 6.6 (P0)
**Legal scope:** EU 2016/679 (GDPR) + SK Act 18/2018 + ePrivacy 2002/58 (cookies).
**Authoritative statement:** any field/table not explicitly listed below is treated as non-PII; any change requires updating this doc.

---

## 1. PII inventory

Mapping of every column carrying personal data, plus its **legal basis** (Art. 6 GDPR) and **retention reason**.

| Table | Column | PII type | Legal basis | Retention | Erasure strategy |
|---|---|---|---|---|---|
| `customers` | `email` | direct id | **6(1)(b)** contract — login credentials | account lifetime + 90d after erasure request | replace with `deleted-{id}@anonymized.local` |
| `customers` | `password_hash` | secret | 6(1)(b) | account lifetime | NULL on erasure |
| `customers` | `name` | direct id | 6(1)(b) | account lifetime | NULL on erasure |
| `customers` | `phone` | contact | 6(1)(b) | account lifetime | NULL on erasure |
| `customers` | `vat_id`, `vat_country` | tax id | 6(1)(c) legal obligation (invoicing) | tied to invoice retention (10y) | keep until last invoice expires, then NULL |
| `customers` | `last_login_at` | activity | 6(1)(f) legitimate (security) | 24 months sliding | NULL on erasure |
| `customer_addresses` | all `name`,`company`,`vat_id`,`street`,`city`,`zip`,`country`,`phone` | direct id + address | 6(1)(b) | account lifetime | DELETE on erasure (no legal retention — order address is in `orders.shipping_addr` snapshot) |
| `orders` | `customer_name`,`customer_email`,`customer_phone`,`shipping_addr` (JSON) | direct id + address | 6(1)(c) accounting + 6(1)(b) contract | **10 years** (SK Act 431/2002 §35) | KEEP until retention expires (legal obligation overrides erasure — Art. 17(3)(b)) |
| `orders` | `ip_address` | online id | 6(1)(f) fraud prevention | 12 months | NULL after 12 months via GC |
| `orders` | `notes` | free text | 6(1)(b) | tied to order | KEEP until order purged |
| `invoices` | `customer_snapshot`,`seller_snapshot` (JSON) | direct id + address | 6(1)(c) accounting | **10 years** (immutable per design) | KEEP — invoice immutability is mandatory |
| `payment_transactions` | `provider_ref`,`raw_response` (JSON; may contain last4 / billing_country) | financial id | 6(1)(b) + PCI scope | **10 years** (anti-fraud + accounting) | KEEP; document that we never store full PAN/CVV — only Stripe/PayU token references |
| `email_log` | `recipient`,`subject`,`payload` (JSON, may contain order snapshot) | direct id + content | 6(1)(f) ops audit | **90 days** (GC) | hard DELETE after 90d; on erasure delete by recipient match |
| `password_reset_tokens` | `token`,`customer_id` | secret | 6(1)(b) | 1 hour TTL | already auto-expired; GC removes >24h |
| `vat_id_cache` | `vat_id`,`trader_name`,`trader_addr`,`raw_response` | business id | 6(1)(c) (VAT validation evidence) | 24h TTL | already self-expiring |
| `abandoned_carts` | `customer_email`,`cart_data` (JSON), `session_id` | direct id | 6(1)(a) consent: the optional checkout consent `cart_reminder` (`checkout_consents`; its version, text and time are stored on the cart) | 30 days | hard DELETE after 30d via GC; unticking the consent withdraws it (no cart kept, no reminder); every reminder has a one-click unsubscribe |
| `audit_log` | `actor` (admin email/id), `ip_address`, `old_value`/`new_value` (may contain PII pre/post change) | direct id + activity | 6(1)(f) security audit | 24 months | scrub PII fields after 24m, keep IDs+actions |
| `coupon_uses` | `customer_email`,`order_id` | direct id | 6(1)(f) fraud prevention | 24 months post-order | hash email to SHA-256 after 24m (preserve dedup, anonymise) |
| `shipping_labels` | `awb`,`label_data` (JSON, contains name/address) | direct id + address | 6(1)(b) shipping contract | 18 months (carrier dispute window) | scrub `label_data` after 18m, keep AWB |

**No special categories** (Art. 9) processed. **No children** (Art. 8): TOS will require ≥16. **No automated decision-making** (Art. 22).

---

## 2. Cookie consent banner

### Design
- Bottom sticky banner (CSS-only, no popups blocking UI), shown **on first visit per session+device** until choice is made.
- Three categories with separate toggles:
  - **necessary** — always on (session, CSRF, cart). No consent required (Art. 5(3) ePrivacy: "strictly necessary" exception).
  - **analytics** — opt-in, defaults OFF.
  - **marketing** — opt-in, defaults OFF.
- Buttons: **Accept all** / **Necessary only** / **Customize** (expands toggles) → **Save**.

### Persistence
- Choice stored in cookie `cc_consent` (12 months expiry per CNIL guidance) **and** in DB row `cookie_consent` (per `customer_id` if logged in, else by anonymized `consent_id` UUIDv4).
- Withdrawal: `/account?p=privacy` → "Manage cookies" re-opens banner; new save wipes any non-necessary cookies via JS `document.cookie='…; Max-Age=0'` for all known names + dispatches `cookie-consent-changed` event so analytics SDKs can `gtag('consent','update',…)` etc.

### DB schema
```sql
CREATE TABLE cookie_consent (
  id INTEGER PRIMARY KEY,
  consent_id TEXT NOT NULL UNIQUE,    -- UUIDv4, also stored in cookie
  customer_id INTEGER NULL,           -- linked when user logs in
  necessary INTEGER NOT NULL DEFAULT 1,
  analytics INTEGER NOT NULL DEFAULT 0,
  marketing INTEGER NOT NULL DEFAULT 0,
  ip_hash TEXT,                       -- SHA-256(ip+salt) for proof-of-consent
  user_agent TEXT,                    -- for proof-of-consent
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
```

### Integration
- `index.php` layout — include banner partial **before `</body>`** (line 910). Consent state read into `$_GDPR_CONSENT` global, passed to template scope.
- No 3rd-party scripts loaded server-side until consent. Pattern: `<script type="text/plain" data-cookieconsent="analytics">…</script>` activated by JS only after consent.

---

## 3. Subject Access Request (SAR)

### Customer-facing
- `account.php?p=data-export` — logged-in only.
- Button "Download my data" (Slovak UI: `Stiahnuť moje údaje`) → POST → server runs `GdprService::exportCustomer($customerId)` → returns JSON download (`gdpr-export-{customerId}-{date}.json`).
- Rate limit: 1 export per 24 h (per Art. 12(5) — "manifestly excessive").
- Audit row in `gdpr_events` (action=`export`).

### Admin-facing
- `admin.php?p=gdpr` — RBAC `gdpr.view`.
- Search by email → list of matched customers + guest-orders.
- Button "Export" → identical JSON, downloaded by admin (for postal SAR fulfillment within 30 days per Art. 12(3)).

### Export shape
```json
{
  "exported_at": "2026-04-25T10:00:00Z",
  "customer": { ... full row, password_hash REDACTED ... },
  "addresses": [ ... ],
  "orders": [ { ..., "items": [...] , "invoices": [...], "payments_redacted": [...] } ],
  "email_log": [ ... last 90d ... ],
  "consent_history": [ ... cookie_consent + gdpr_events ... ]
}
```
Payment data: only provider+ref+amount+status (no `raw_response` — may leak gateway internals).

---

## 4. Right to erasure

Two-tier strategy because of Art. 17(3)(b) (legal retention overrides erasure):

### Tier A — soft anonymization (DEFAULT)
Triggered by `account.php` "Delete my account" (Slovak UI: `Zmazať môj účet`) or `admin.php` "Anonymize". Effect:

| Table | Action |
|---|---|
| `customers` | `email = 'deleted-{id}@anonymized.local'`, `password_hash = NULL`, `name = NULL`, `phone = NULL`, `vat_id = NULL`, `last_login_at = NULL`, `status = 'erased'`. `customer_id` references in `orders` STAY (preserves analytics + accounting). |
| `customer_addresses` | DELETE (no legal need; order has snapshot) |
| `password_reset_tokens` | DELETE all rows |
| `abandoned_carts` | DELETE rows matching email |
| `email_log` | scrub `recipient`, `payload` → `'[erased]'`; keep ID + template + timestamp |
| `coupon_uses` | hash email → `sha256:abcd...` |
| `audit_log` | scrub `actor`/`ip_address` for entries authored by this customer |
| `cookie_consent` | DELETE rows by `customer_id` |
| `orders.customer_*` | **KEEP** for 10y (legal). After 10y a separate retention job NULLs them. |
| `invoices.customer_snapshot` | **KEEP** for 10y (immutable, legal). |

### Tier B — hard delete
Only via admin CLI `cli/gdpr-purge.php --customer-id=N --confirm` after legal retention expires (10y for orders/invoices). Logs to `gdpr_events`. Confirmation required + `--confirm` flag.

### Invoice-PDF question (operator's open Q)
**Decision:** PDF files **kept as-is** for legal retention. Tax authorities require original-form invoice (SK Act 431/2002). Customer name in PDF is mandatory for invoice validity. **Anonymisation of PDF would void it as accounting evidence**. Privacy policy will explicitly state "We keep invoices containing your name and address for 10 years under the Accounting Act, even after an erasure request".

---

## 5. Data retention

Single source of truth in `config/data-retention.json`:

```json
{
  "tables": {
    "abandoned_carts":         { "max_age_days": 30,    "field": "created_at",  "action": "delete" },
    "email_log":               { "max_age_days": 90,    "field": "created_at",  "action": "delete" },
    "password_reset_tokens":   { "max_age_days": 1,     "field": "created_at",  "action": "delete" },
    "vat_id_cache":            { "ttl_via_column": true },
    "audit_log":               { "max_age_days": 730,   "field": "created_at",  "action": "scrub_pii" },
    "coupon_uses":             { "max_age_days": 730,   "field": "created_at",  "action": "hash_email" },
    "shipping_labels":         { "max_age_days": 540,   "field": "created_at",  "action": "scrub_label_data" },
    "orders.ip_address":       { "max_age_days": 365,   "field": "created_at",  "action": "null_field", "column": "ip_address" },
    "orders":                  { "max_age_days": 3650,  "field": "created_at",  "action": "null_pii_columns" },
    "invoices":                { "max_age_days": 3650,  "field": "issued_at",   "action": "null_pii_columns" },
    "cookie_consent":          { "max_age_days": 365,   "field": "updated_at",  "action": "delete" },
    "gdpr_events":             { "max_age_days": 1825,  "field": "created_at",  "action": "delete" }
  }
}
```

CLI: `cli/gdpr-gc.php` — daily cron. `--dry-run` default, `--apply` writes. Each pass logs to `logs/gdpr-gc.log` and `gdpr_events`.

---

## 6. GDPR audit log — `gdpr_events`

Append-only by application convention (no UPDATE/DELETE in service layer; trigger blocks UPDATE).

```sql
CREATE TABLE gdpr_events (
  id INTEGER PRIMARY KEY,
  action TEXT NOT NULL,            -- export | erase | consent_change | retention_apply | admin_anonymize
  customer_id INTEGER,             -- NULL for guest / system events
  email_hash TEXT,                 -- SHA-256(lower(email)+salt) — searchable without storing email
  actor TEXT,                      -- 'self' | admin email | 'system'
  ip_hash TEXT,
  payload_hash TEXT,               -- SHA-256 of any payload, for tamper detection
  payload_summary TEXT,            -- small human-readable note (e.g. "scrubbed 3 addresses")
  created_at TEXT NOT NULL
);
CREATE INDEX idx_gdpr_events_customer ON gdpr_events(customer_id);
CREATE INDEX idx_gdpr_events_email_hash ON gdpr_events(email_hash);
CREATE TRIGGER gdpr_events_no_update BEFORE UPDATE ON gdpr_events
  BEGIN SELECT RAISE(ABORT,'gdpr_events is append-only'); END;
CREATE TRIGGER gdpr_events_no_delete BEFORE DELETE ON gdpr_events
  WHEN NEW.action IS NOT NULL  -- allow GC delete via raw SQL bypass only
  BEGIN SELECT RAISE(ABORT,'gdpr_events is append-only'); END;
```

GC retention (5y) bypasses trigger via `PRAGMA defer_foreign_keys=ON; DROP TRIGGER … ; … ; CREATE TRIGGER …` cycle inside a single transaction in `gdpr-gc.php`.

---

## 7. Privacy / Terms / Cookies pages

`templates/legal/{sk,cs,en,de,pl,hu}/{privacy,terms,cookies}.html` — Markdown-ish HTML with placeholder tokens:

```
{{seller_company}}, {{seller_address}}, {{seller_id_no}}, {{seller_vat_id}},
{{seller_email}}, {{seller_dpo_email}}, {{processors_list}}, {{updated_at}}
```

Renderer = simple `strtr()` over store legal config (`stores.json.legal` already exists from invoices feature).

Routes (added to `index.php` + the `/api/v1` storefront routes):
- `index.php?p=privacy` / `?p=terms` / `?p=cookies`
- pretty-URLs: `/sk/ochrana-sukromia`, `/sk/podmienky`, `/sk/cookies` (per locale)

Footer link block in `index.php` layout.

---

## 8. DPA / Sub-processor inventory

`config/processors.json`:
```json
{
  "processors": [
    { "name": "Stripe Inc.", "purpose": "card payments", "country": "IE/US", "dpa_url": "https://stripe.com/legal/dpa", "dpo_email": "dpo@stripe.com", "categories": ["payment_data"] },
    { "name": "PayU S.A.",    "purpose": "card/instant payments", "country": "PL", "dpa_url": "https://corporate.payu.com/dpa", "categories": ["payment_data"] },
    { "name": "Amazon Web Services (SES)", "purpose": "transactional email", "country": "DE/IE", "dpa_url": "https://aws.amazon.com/compliance/gdpr-center/", "categories": ["email_address","order_summary"] },
    { "name": "European Commission (VIES)", "purpose": "VAT validation", "country": "EU", "dpa_url": "https://ec.europa.eu/taxation_customs/vies/", "categories": ["vat_id"] },
    { "name": "Packeta a.s.", "purpose": "shipping", "country": "CZ", "dpa_url": "https://www.packeta.com/legal", "categories": ["name","address","phone"] },
    { "name": "GLS Slovakia",  "purpose": "shipping", "country": "SK", "dpa_url": "https://gls-group.com/SK/sk/legal", "categories": ["name","address","phone"] }
  ]
}
```

Privacy template renders this list under "Recipients / sub-processors".

---

## 9. PII in logs — audit result

`grep -n error_log src/ admin.php api/` shows **15 sites**. All log only `{$id}` / `$e->getMessage()` / template name. **None log raw email, name, address, phone**. **Already PII-clean.** Doc this as a `lesson` in HMOS so future contributors keep the bar.

Action items:
- Add a one-line policy comment in `src/Security.php` header: `// PII in logs is forbidden — log IDs + safe metadata only.`
- No code changes needed.

---

## 10. Encryption at rest

**Decision: NO application-level encryption in P0.** Reasoning:

- SQLite file lives behind `/storage/` (already mimo webroot, blocked by web server).
- App-level AES on `phone`/`address` would break order search/filtering and admin UX significantly.
- Threat model: attacker with FS access → app secrets are also on disk (`config.php`, `.env`) → encryption key is compromised → no real protection.
- Real protection = disk encryption (LUKS / BitLocker) at infra layer. Document this in `docs/deploy/runbook.md` (separate mission).

Privacy policy will state: *"Data is kept in a relational database protected by operating system access rights and by storage-level encryption of the hosting provider."*

---

## 11. Implementation plan (Phase 2)

Granular commits in this order:

1. **schema** — migration `add_gdpr_tables` (cookie_consent, gdpr_events with triggers) + `data-retention.json` + `processors.json`.
2. **GdprService skeleton** — `exportCustomer`, `anonymizeCustomer`, `recordEvent`, `consentRecord`, `consentRead`.
3. **GdprService::applyRetention** — table-by-table GC, `--dry-run` aware, returns counts.
4. **CLI cli/gdpr-gc.php** — daily cron. Erasure and scrubbing do not reach existing backups and update snapshots: the data persists there until backup GC / `update_snapshots_keep` rotates them out (see OPERATIONS_RUNBOOK "Retention"). `--dry-run` default. Auto pre-run BackupService snapshot when `--apply`; refuses (exit 3) when no SQLite snapshot is possible (backups disabled, MySQL) unless `--skip-backup` confirms an external backup.
5. **CLI cli/gdpr-export.php** — admin-only by email; mirror of customer export for postal SAR. In the web admin the same export is a CSRF-protected POST `admin.php?p=gdpr&action=export` (`gdpr.view`, active store only, audited as `export` with the admin as actor); the admin never links to the CLI script, which is HTTP-denied.
6. **account.php** — Privacy section: data export button, erasure button, manage cookies link. 32 new `gdpr.*` lang keys × 6 locales.
7. **admin.php** — GDPR panel: search by email, anonymize button, audit log viewer. RBAC `gdpr.view` + `gdpr.erase`.
8. **Cookie banner** — partial `templates/cookie-banner.php` + JS in `assets/cookie-consent.js` + integration in `index.php` layout. Necessary/analytics/marketing toggles.
9. **Legal pages** — `templates/legal/{6 locales}/{privacy,terms,cookies}.html` + storefront routes + footer links.
10. **Tests** — ≥12: export shape, erasure preserves orders, retention GC dry-run vs apply, append-only trigger, consent record, hash-email determinism, route renders, email-log scrub, payment redact, audit_log entry on each action, banner JS state machine snapshot, processors.json loader.
11. **Docs + HMOS** — ESHOP-DESIGN.md `## GDPR Compliance` section + L2 GdprService + L3 sub-nodes + L5 warnings.

---

## 12. Open questions (need operator green light before Phase 2)

1. **Email log retention 90d** — OK or change (60 / 180)?
2. **Order PII null after 10y** — auto via GC or manual admin action only?
3. **`coupon_uses` hash-email after 24m** — OK, or hard-delete row? (Hash preserves analytics; delete is cleaner.)
4. **Cookie consent default categories** — analytics+marketing OFF (CNIL strict) or ON-by-default (German "Berechtigtes Interesse" gray-zone)? Default: **OFF**.
5. **Privacy policy maintenance** — generated text references `{{seller_company}}` etc. from `stores.json.legal`. Translation accuracy: is generic translated text acceptable for v1, or should you (operator) review?
6. **Cookie banner design** — minimal (CSS-only, ~80 lines) or styled with site theme (more work)? Default: **minimal**, theme later.
7. **`gdpr_events.email_hash` salt** — store in `config.php` as `gdpr_hash_salt`, generate on first run. OK?

---

## 13. Out-of-scope (P1+)

- DPIA (Data Protection Impact Assessment) document — needed only when high-risk processing introduced.
- DPO appointment — only required when ≥250 employees or core activities = systematic monitoring. Probably not needed; doc this.
- Cross-border transfer SCCs — implicit when using AWS/Stripe; processors.json links cover it.
- Encryption-at-rest with SQLCipher — separate mission if hosting tier requires it.
- Automated breach notification workflow (Art. 33) — manual procedure documented in deploy runbook is enough for v1.
