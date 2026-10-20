# Production qualification evidence

**Qualified source SHA:** `88160b8caa1479edce1db046fead275ea52d00cb`
**Qualification date:** 2026-08-02 UTC
**Scope:** G1–G7 repository changes and disposable SQLite/MySQL/MariaDB/browser environments. Live merchant, provider, legal, and infrastructure acceptance remains operator-owned.

## Result

The repository qualification is **PASS**. No confirmed open P0/P1 security, financial-correctness, data-isolation, or performance-regression finding remains in the reviewed scope.

The adversarial pass found one process defect before sign-off: the completed G4 category benchmark diff had remained uncommitted in its retained worktree while the milestone was reported as shipped. G7 recovered that exact work, adapted its assertions to the split scenario harness, reran every local gate and the 100k qualification, committed it separately as `88160b8`, and verified the full remote CI matrix. No history was rewritten.

## Checkout critical-path release gate (since 2026-10-13)

From 1.9.0 (2026-10-08) card checkout was dead in production for five days while every PHP and browser test was green: the CSP `form-action 'self'` made browsers drop the 302 to `checkout.stripe.com`, and the only browser payment test called the API with a same-origin mock gateway. Green tests do not prove that customers can pay.

A release that touches the checkout critical path — `src/Security.php` (CSP/headers), `src/UrlAliases.php`, `redirect.php`, `index.php`, `.htaccess`, the storefront checkout entrypoints and templates, `src/CheckoutService.php`, `src/CheckoutSession.php`, `src/PaymentService.php`, `src/Http/ApiHandlers.php`, payment adapters, or `src/Updater.php` — is qualified only when **all four layers** are green:

| Layer | Proof | Where |
|---|---|---|
| 1. Contract | every hosted-checkout adapter declares its redirect origins and the CSP `form-action` allows those of each gateway an active online payment method uses (and no other); gateway `success_url`/`cancel_url` are real pages; resubmit continues the pending payment | `cli/tests/scenarios/CheckoutPaymentRedirectRegression.php` |
| 2. Browser | a real click on the order button follows the redirect to a gateway on **another origin** in Chromium, WebKit (iPhone) and Firefox and fails on any CSP violation on a **shop** document (counted by the document's origin, not by the blocked URL; the gateway's own page enforces its own policy); the same specs fail on the 1.9.0 code, and `csp-guard.spec.js` proves the guard still catches a `form-action 'self'` drop of the Stripe redirect in Chromium and WebKit (Firefox does not apply `form-action` to redirects) | `tests/e2e/commerce.spec.js`, `tests/e2e/csp-guard.spec.js`, CI job *Browser, axe & Lighthouse* |
| 3. Real gateway | a real Stripe **test-mode** Checkout session opened by the browser: every engine must land on `https://checkout.stripe.com/c/pay/cs_test_…` with no shop CSP violation; Stripe's page must render the amount in Chromium and Firefox (Playwright's WebKit build trips over Stripe's own CSP, so WebKit asserts the redirect only) | `tests/e2e/stripe-test.spec.js`, workflow *Payment gateway (Stripe test mode)* (nightly and on critical-path changes; needs secret `STRIPE_TEST_SECRET_KEY` with a test-mode key: `sk_test_…` or a restricted `rk_test_…` with write access to Checkout Sessions (`POST /v1/checkout/sessions`); live keys are refused) |
| 4. Production | `php cli/smoke-checkout.php` right after deploy (synthetic order, closed immediately), CSP violation reports, and the payment funnel alarm in `php cli/monitor.php` | `docs/OPERATIONS_RUNBOOK.md` → *Post-deploy checkout smoke* |

A missing layer is a release blocker, not an observation. Record the smoke output and the first `monitor.php` snapshot after deploy with the release evidence.

## G1–G7 release chain

| Goal | SHA | Evidence |
|---|---|---|
| G1 checkout convergence | `19d34900f25a60d55bfd43e801105bf82b3d2bc9` | HTML and JSON delegate to one checkout application path; persisted snapshot/VAT/net/invoice and opaque confirmation-token regressions |
| G2 admin forms | `6586e5f3726053982cf2ede94c8116434fe9baf5` | Valid product form ownership plus real-browser export/bulk/CSRF coverage |
| G3 batch enrichment | `18448a117113b395a0d90f2c597ac902b6970396` | Guest/customer/wishlist query counts reduced from 32/48/41 to 4/5/5 for eight cards with payload and pricing parity |
| G4 category benchmark | `88160b8caa1479edce1db046fead275ea52d00cb` | Full category SSR/API guest/customer cold/warm profiles, query counter, facet batching, O(n) category tree, encoded budgets |
| G5 entrypoint boundaries | `dd6940eed657e2d54aa4668be43e1f20e4e06c6c` | Storefront/Admin split into explicit bounded-context modules with size/function/coupling/forbidden-dependency gates |
| G6 test scenarios | `7a40b1fe5e5a2d17df3f118ce67f93401fca6156`, `62dfa3d0f3ddcd5e1414c6bd9233a449ff8f93da` | Six scenario files, 60-line canonical runner, unique fixture DB, unexpected PHP signals fail, deterministic expected-log capture |
| G7 final qualification | containing commit | This adversarial review and audit evidence |

All production commits were integrated by fast-forward. G4 was recovered as a new commit rather than amending or rewriting published history.

## Automated evidence

- Canonical local PHP suite at the qualified source: **1929/1929 passed** with no unexpected warning, deprecation, notice, fatal, or uncaptured expected failure log.
- Static analysis: **212 PHP files**, **10 stable domain events**, **4 thin entrypoints**, **11 bounded-context modules**; architecture budgets passed.
- i18n parity: **335 keys across 7 locales**.
- Source quality gates: WCAG 2.2 AA invariants, consent defaults, feed routes, and asset budgets passed.
- Fresh disposable SQLite Playwright/axe qualification: **13/13 passed**, including HTML checkout, authenticated ownership, signed payment webhook, admin MFA, valid bulk/export controls, fulfillment, RMA/credit/refund, updater smoke, keyboard/focus/reflow, and serious/critical axe checks.
- Remote CI run **30749548970** at `88160b8`: all six jobs passed:
  - Lint & Test — PHP 8.1
  - Lint & Test — PHP 8.2
  - Lint & Test — PHP 8.3
  - Browser, axe & Lighthouse
  - Application & updater — MySQL 8.0
  - Application & updater — MariaDB 11.4
- Local Lighthouse 12.6 with host Chrome 150 stopped in its own Lantern implementation (`FCP/LCP All Frames not implemented`) before application assertions. The clean Linux CI browser job used its pinned environment and passed all rendered Lighthouse budgets; this is classified as a local-tool compatibility observation, not a product failure.

## 100k performance qualification

Fresh disposable command evidence used fixture-v2 with 100,000 products, 2,020 categories, 400,000 specifications, 20 measured iterations, four checkout workers, total response time plus TTFB, and per-request query counts. The raw report was written outside the repository under the marked disposable runtime.

| Profile | p50 | p95 | p99 | Queries |
|---|---:|---:|---:|---:|
| Category SSR guest cold | 39.573 ms | 56.351 ms | 59.990 ms | 35 |
| Category SSR guest warm | 39.456 ms | 41.278 ms | 50.049 ms | 35 |
| Category SSR customer cold | 39.055 ms | 41.151 ms | 61.830 ms | 38 |
| Category SSR customer warm | 39.032 ms | 39.788 ms | 39.832 ms | 38 |
| Category API guest cold | 37.755 ms | 40.720 ms | 50.703 ms | 14 |
| Category API guest warm | 37.648 ms | 40.422 ms | 54.344 ms | 14 |
| Category API customer cold | 37.794 ms | 39.414 ms | 41.031 ms | 17 |
| Category API customer warm | 36.156 ms | 39.640 ms | 47.572 ms | 17 |
| Facets without active filter | 19.529 ms | 19.872 ms | 20.849 ms | 1 |
| Facets with one active filter | 49.242 ms | 49.849 ms | 49.981 ms | 2 |
| Category tree, 2,020 nodes | 3.760 ms | 4.656 ms | 4.657 ms | 1 |
| Related from 5,000 products | 1.000 ms | 1.011 ms | 1.021 ms | 2 |

All encoded latency and query budgets passed. Concurrent checkout qualification passed **4/4** and all **6/6** failure-injection invariants passed. `Products::related()` remains unchanged because the measured path was not a hotspot.

Numbers describe this Windows/PHP 8.5 disposable SQLite environment and prove regression direction, payload validity, and query bounds; they are not universal production latency or throughput guarantees. The HTTP profile uses the single-process PHP development server.

## Final adversarial review

Reviewed boundaries included:

- canonical HTML/JSON checkout mapping and the sole current public `Orders::create()` call site;
- immutable financial snapshot fingerprint, integer-cent money, mixed VAT, coupon/shipping/payment-fee allocation, `net + VAT = gross`, invoice/OSS/credit-note copy semantics;
- stock reservation atomicity, restore idempotency, payment/webhook idempotency and replay protection;
- CSRF, session rotation, customer/admin auth, MFA, opaque browser confirmation ownership, CSP/XSS, host handling, and store scoping;
- SQLite/MySQL/MariaDB SQL paths and native database CI;
- listing/wishlist payload, pricing, promotions, ordering, customer-group, and query-count parity;
- benchmark response-contract validation, fixture cardinality, cold/warm definitions, budgets, and failure injection;
- Storefront/Admin bounded contexts, architecture gates, scenario isolation, warning/deprecation failure semantics, and expected-log capture;
- diff hygiene and secret-pattern review of the recovered G4 files.

The current HTML transport delegates to the same checkout application path as JSON, and the only live public `Orders::create()` call is in that canonical handler. Regressions validate persisted snapshot gross/VAT/net against the order, exact snapshot copy into invoice and OSS/credit documents, session-bound confirmation, store isolation, stock and idempotency boundaries, and real-browser behavior.

**Conclusion:** no confirmed open P0/P1 finding.

## Explicit non-blocking observations

1. **P2 compatibility boundary — direct legacy order callers.** `Orders::create()` retains a snapshotless compatibility path for direct historical imports/integrations, while both current public checkout transports always provide and validate the canonical snapshot. Removing that branch requires an inventory and migration of external callers and is operator-owned for a breaking release; it is not reachable through the qualified HTML/JSON checkout routes.
2. **P3 local qualification tooling.** Lighthouse 12.6 cannot complete against the host's Chrome 150 Lantern all-frames path. The pinned Linux CI job passed; update the local Chrome/Lighthouse pairing before relying on local Lighthouse evidence alone.
3. **P3 CSP/metadata hardening.** Storefront inline CSS still requires `style-src 'unsafe-inline'`, and public metadata URL generation should ultimately prefer the configured canonical URL over request-host input. Existing controls prevent these observations from crossing a script-execution, authentication, payment, secret, or order-integrity boundary.
4. **Historical release payloads.** The repository tracks no release payloads (`releases/` holds only `update-public.key`; `cli/quality-gates.php` fails on any `releases/*/payload`). A production rollout must build and sign a fresh release from the qualified SHA.

## Operator-owned live acceptance

Before a real production release, the operator must record the checks under **Required live operator checks** in `docs/OPERATIONS_RUNBOOK.md`, including:

- production DNS/TLS/proxy host allowlist and private filesystem permissions;
- real off-site retention/encryption and a timed restore within the declared RTO;
- external SLO calculation and verified paging destination;
- real payment/refund/signed-webhook, mail/bounce, carrier, ERP/PIM/WMS, and tax-provider test facilities;
- merchant identity, registrations, tax rates, invoice wording/numbering, and accounting reconciliation;
- manual production-browser keyboard, zoom/reflow, visible-focus, and provider redirect review;
- a newly built and signed release package from the qualified source.

Repository qualification must be repeated for code changes after the qualified source SHA. Live acceptance must be repeated when provider, tax, legal, infrastructure, or operational policy changes.
