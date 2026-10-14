# Changelog

## Unreleased

### Added
- **Shipments and tracking in the admin order detail** (A12.8). Migration `20261014090000_shipment_tracking_url`.
  - Per-warehouse partial shipments limited to the not-yet-shipped remainder; ship now or plan,
    cancel a planned shipment; carrier, tracking number and http(s) tracking URL, correctable later.
  - Permission `orders.ship`, CSRF, store scope; every change is written to the audit log.
  - `shipments.tracking_url` is exposed in API v1 (`Shipment` schema in `docs/openapi.yaml`).
- **Merchant low / critical stock e-mail** (`StockAlerts`, worker job `stock_alert`).
  - Two thresholds `low` / `critical` (inclusive; 0 = always critical/sold out). Global default in
    config `stock_alerts`, per-product override in the admin product form and in CSV import/export
    (`stock_low_threshold`, `stock_critical_threshold`; empty = config default).
  - Source of truth is `warehouse_stock` (available = qty − reserved over the store's warehouses),
    not `products.stock`. Only active physical products with a warehouse row are tracked.
  - Every ledger movement (checkout reservation, shipment, cancellation/release, return, receipt,
    manual edit, CSV import, feed sync) marks the SKU; one `stock_alert` job per request is queued
    at shutdown, so checkout never waits for or fails on e-mail.
  - E-mail only on a downward crossing (ok → low → critical → 0), state per SKU + store in
    `stock_alert_state`; re-arms after restock above `low`. All SKUs crossing in one worker run go
    into one e-mail. Failed delivery rolls the state back and the job retries.
  - Optional daily digest of everything at/below `low` (`digest_hour`, default off).
  - Template `merchant_stock_low` in sk, cs, en, de, hu, pl, es. Recipients: `stock_alerts.recipients`,
    default `merchant_notifications.recipients`, then `admin_email`.
  - Admin dashboard and the new product-list filter "Low stock" use the same thresholds and
    availability (no hardcoded `< 10`), coloured low / critical.
- Admin product stock edits and CSV `stock` are booked on the default warehouse as ledger
  adjustments (`Warehouse::setAvailable`), keeping `products.stock` in sync.
- **Checkout critical-path monitoring** (after the 1.9.0 card-payment outage; nothing changes for
  customers). Migration `20261013120000_payment_monitoring`.
  - `cli/smoke-checkout.php`: post-deploy smoke that walks the HTML checkout to the gateway redirect
    and requires its origin in the checkout page's CSP `form-action`, the return pages to exist and
    the gateway to answer. Its request carries `X-BareBones-Synthetic` (`monitoring.synthetic_token`,
    ≥ 32 chars): the order is flagged `orders.is_synthetic`, sends no mail, emits no ERP/webhook events,
    refuses offline payment, and is cancelled with stock released right after the gateway session.
  - CSP `report-uri` + `report-to` (`Reporting-Endpoints`) → `api.php?action=csp_report` → table
    `csp_violations` (directive, blocked origin, document path; 14 days; rate limited).
  - `OperationalMetrics` / `cli/monitor.php`: payment funnel (`payment_online_started/redirected/paid`)
    with critical `payment_session_failing` / `payment_funnel_stalled`, and CSP metrics with critical
    `csp_form_action_violation`.
  - Real Stripe test-mode browser checkout (`tests/e2e/stripe-test.spec.js`, `E2E_STRIPE_TEST_SECRET_KEY`)
    and workflow `payment-gateway.yml` (nightly + on critical-path file changes).
  - Release gate in `docs/PRODUCTION_QUALIFICATION.md`, smoke and alarms in `docs/OPERATIONS_RUNBOOK.md`.

### Changed
- `cli/worker.php` `handleStockAlert` now delegates to `StockAlerts::process()`; the old
  `products.stock` scan that only wrote `logs/stock-alerts.log` is removed. `Products::lowStockSummary()`
  is replaced by `StockAlerts::summary()`.
- Shop-owned URL aliases (config `url_aliases`, `UrlAliases`) brought over from `aeon/candidate`
  (bb758f1, 7d2c940): inbound dispatch in `redirect.php`, outbound links, 301 from core routes.

### Fixed
- Changing an order to `shipped` after a partial shipment failed with "Shipment exceeds reserved
  allocation"; it now ships only the remainder that is not yet in any shipment.
- **P0: card payment from the HTML checkout was dead since 1.9.0.** CSP `form-action 'self'`
  also applies to the redirect after a form submit, so the browser silently dropped the 302 to
  `checkout.stripe.com` ("nothing happens", second click → "cart is empty", pending orders, no payment).
  - Payment adapters that redirect off-site implement `HostedCheckoutAdapter::redirectOrigins()`
    (Stripe, PayU sandbox/production, Mock with `mock_checkout_url`); `Security` builds `form-action`
    from the configured adapters, never hard-coded. A gateway URL outside `form-action` is answered
    with a clickable payment link instead of a redirect the browser would drop (and logged).
  - The created-but-unpaid order is remembered in the session (`CheckoutSession::rememberPendingPayment`,
    23 h): a resubmit continues the same payment (no second order), the empty checkout and the
    gateway cancel page show "order X awaits payment" + "Pay now" (`storefront/checkout-pending`),
    never "cart is empty". A declined payment can be retried from `order-done`.
  - Gateway `cancel_url` is now `/?page=checkout-cancel&order=…`; the old `/checkout/cancel` (in sessions
    created by earlier versions) is routed to the same page instead of 404.
  - The submit button is disabled after the first click with "Redirecting to payment…" and re-enabled
    on `pageshow` (back/forward cache).
  - Tests: `CheckoutPaymentRedirectRegression` (every hosted adapter origin ∈ form-action; real HTTP
    checkout → 302 allowed by the page CSP; resubmit/back/cancel/success pages over the fallback router).
    Browser E2E now clicks the real form and the mock gateway runs on a second origin
    (`tests/e2e/mock-gateway.php`); a global fixture (`tests/e2e/support.js`) fails any spec on a CSP
    violation; projects Chromium, WebKit iPhone 13 and Firefox.
- `redirect.php` answered **405** (`Allow: GET, HEAD`) to every POST on a public alias
  (`/kosik`, `/pokladna`…), because the method check ran before `UrlAliases::dispatch()`. Themes
  whose cart uses a plain `<form method="post" action="{{cart_url}}">` showed a bare 405 page.
  Aliases are now dispatched first and the entry script handles its own methods; 405 stays only
  for admin redirects and 404s. Regression test drives the fallback router over real HTTP
  (`php -S`): POST `cart_action=update` with CSRF on `/kosik` → 302 back to the cart with the new
  quantity; POST on an unknown path → 405.

### Migration
- `20261008090000_stock_alerts` (idempotent): `products.stock_low_threshold`,
  `products.stock_critical_threshold`, tables `stock_alert_state`, `stock_alert_digests`.
