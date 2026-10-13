# Changelog

## Unreleased

### Added
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

### Changed
- `cli/worker.php` `handleStockAlert` now delegates to `StockAlerts::process()`; the old
  `products.stock` scan that only wrote `logs/stock-alerts.log` is removed. `Products::lowStockSummary()`
  is replaced by `StockAlerts::summary()`.
- Shop-owned URL aliases (config `url_aliases`, `UrlAliases`) brought over from `aeon/candidate`
  (bb758f1, 7d2c940): inbound dispatch in `redirect.php`, outbound links, 301 from core routes.

### Fixed
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
