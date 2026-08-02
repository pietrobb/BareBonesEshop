# Production qualification evidence

**Qualified source SHA:** `7849a49e038caa7a994a4b131002aaad7456f181`
**Qualification date:** 2026-08-02 UTC
**Scope:** repository code and disposable SQLite/MySQL/MariaDB/browser environments. Live merchant/provider/infrastructure checks remain operator-owned and are listed below.

## Result

The repository qualification is **PASS**. No confirmed open P0/P1 security or correctness finding remains in the reviewed scope. This is not a claim that live DNS, TLS, payment, mail, carrier, ERP/PIM/WMS, off-site mount, paging, legal identity, or accounting configuration has been validated.

## Release chain

| SHA | Evidence |
|---|---|
| `f2184b7f633c3da283e4908f6c870684f3c0fa2f` | Storage-neutral backup/restore, fail-closed mounted off-site adapter, disposable restore drill |
| `d7865ae3253539372de7b27c827055d46f877384` | Optional metrics/alert contract, private JSONL sink, readiness/queue/incident/backup-age signals, SLO/RPO/RTO runbook |
| `7849a49e038caa7a994a4b131002aaad7456f181` | Checkout programmatic labels/autocomplete, contrast correction, rendered checkout axe coverage |

The final code SHA was fast-forward pushed to `origin/main`. Before this evidence record, local `HEAD` and `origin/main` both resolved to `7849a49e038caa7a994a4b131002aaad7456f181` and the tracked working tree was clean.

## Automated evidence

- Local PHP regression suite: **1904/1904 passed**.
- PHP lint passed for every modified PHP file.
- Dev static analysis: **192 PHP files**, **10 stable domain events**, **4 thin entrypoints**.
- Source quality gates: WCAG 2.2 AA invariants, locale parity, consent defaults, feed routes, and asset budgets passed.
- Rendered Playwright profile: **12/12 passed**; its axe/WCAG subset was **5/5 passed** for storefront, cart, account login, checkout, keyboard bypass/focus, and 200% zoom/reflow.
- Final code CI run `30739164391`: all six jobs passed:
  - Lint & Test — PHP 8.1
  - Lint & Test — PHP 8.2
  - Lint & Test — PHP 8.3
  - Browser, axe & Lighthouse
  - Application & updater — MySQL 8.0
  - Application & updater — MariaDB 11.4
- Metrics/alert tests proved healthy and RPO-breached states, stable alert codes, secret redaction, private path enforcement, and optional-by-default behavior.
- Restore tests proved remote-storage-neutral download, metadata identity, size/SHA-256 verification, tamper rejection, disposable database replacement, and SQLite integrity check.
- Previously qualified performance evidence remains in `ESHOP-DESIGN.md` and `cli/benchmark.php`, including dataset, p50/p95/p99, concurrency, resource envelope, and failure injection.

## Final adversarial review

Reviewed boundaries: public/CLI routing, session/authentication, CSRF/CSP/XSS, payment and shipping webhook signatures/replay, checkout idempotency and financial snapshots, SQL/path traversal, invoices, stock reservation/restore, backup/restore, updater signatures/rollback, metrics/log redaction, private runtime paths, and shipped mock-provider gates.

Evidence included the dedicated security audit block plus exact P0/P1 regressions for HTTP tax checkout, mock webhook fail-closed behavior, atomic warehouse reservation, return-vs-reservation isolation, stock double-restore prevention, invoice uniqueness, updater package signatures/tamper detection, and private file paths. Static review found no `script-src 'unsafe-inline'`, runtime `eval`/`unserialize`, or user-controlled process execution in public application paths; the existing `style-src 'unsafe-inline'` remains a documented CSS hardening opportunity. The fixed-command `shell_exec` calls are confined to the development-only benchmark metadata collector.

**Conclusion:** no confirmed open P0/P1 finding. Two non-blocking hardening observations remain: the storefront's inline CSS requires `style-src 'unsafe-inline'`, and `robots.php`/`sitemap.php` construct public metadata URLs from `HTTP_HOST`. Neither crosses a script-execution, authentication, payment, secret, or data-integrity boundary; future hardening should externalize the CSS, constrain accepted hosts, and use the configured canonical `app_url`.

## Manual browser/WCAG review

A disposable SQLite fixture at the qualified source was inspected in a visible browser.

- Skip link was the first keyboard target and `Enter` moved navigation to `#main-content`.
- At a 640 px viewport (representative of 200% desktop reflow), all interactive controls remained exposed with no occlusion and the catalog reflowed from four to two columns.
- Storefront, checkout, account, header/main/footer landmarks, image names, buttons, radio groups, and order submission remained available through the accessibility tree.
- The review found unnamed checkout fields and sub-threshold small-text contrast. They were fixed in `7849a49`; the final snapshot exposed exact names for Full Name, E-mail, Phone, Street, City, ZIP Code, Country, and Order Notes.
- The rendered checkout axe test then passed with no critical/serious WCAG violation.

## Tax and invoice invariant retest

The final 1904-test run reconfirmed:

- current and historical rates, domestic, OSS, sub-threshold, export, reverse-charge, and non-VAT scenarios;
- deterministic mixed-rate integer-cent snapshot, shipping/coupon/fee allocation, fingerprint tamper rejection, and `net + VAT = gross`;
- cart and order VAT persistence, per-line net/rate/amount, and zero-VAT reverse-charge behavior;
- invoice gross equals order total, VAT/net breakdown reconciliation, immutable order snapshot copy, monotonic unique numbering, tokenized access, private PDF storage, idempotent issuance, and reverse-charge invoice output;
- credit-note source snapshot immutability, quantity limits, exact negative-cent reconciliation, and refund audit.

## Operator-owned live acceptance

Before a real production release, the operator must record the items under **Required live operator checks** in `docs/OPERATIONS_RUNBOOK.md`, especially:

- production DNS/TLS/proxy host allowlist and private filesystem permissions;
- real off-site mount retention/encryption and a timed restore within the declared 60-minute RTO;
- external 30-day readiness SLO calculation and verified paging destination;
- live-provider test facilities for payment/refund/signed webhook, mail/bounce, carrier, and configured ERP/PIM/WMS adapters;
- manual production-browser keyboard/zoom/visible-focus pass;
- merchant identity, registrations, rates, invoice wording/numbering, and accounting reconciliation.

Repository qualification must be repeated for any code change after the qualified SHA; live acceptance must be repeated when provider, tax, legal, infrastructure, or operational policy changes.
