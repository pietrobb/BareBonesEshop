# Changelog

## Unreleased

### Upgrade notes (operator-facing and breaking changes from PRs #10-#14 and the follow-up audit)

Read before updating a live shop; run `php cli/migrate.php` after the files are in place.

- **Backup / restore / update CLI**
  - `cli/restore.php` restores MySQL/MariaDB `.sql` backups now, but only with `--i-stopped-writers`
    (stop web traffic, workers and cron first; there is no lock that proves nobody writes). The dump
    is imported into a scratch database first (`backup_mysql_restore_scratch_database`, default
    `<db_name>_restore_scratch`, must not exist; `db_user` needs `ALL PRIVILEGES` on it and on
    `db_name`) and verified before `db_name` is dropped and re-imported.
  - `--skip-snapshot` is refused for MySQL/MariaDB restores (SQLite only): the pre-restore snapshot is
    the rollback copy. If a restore fails after `db_name` was dropped, the pre-restore dump is kept
    (0600) in `backup_recovery_dir` and its path is printed.
  - New `--files` (database, then the files archive) and `--files-only` (`--force` overwrites live
    files that differ) restore invoices, uploads and the GDPR salt from `<backup>.files.tar`.
  - `cli/update.php rollback` that restores the database requires `--accept-data-loss`; an automatic or
    manual rollback reports the orders/payments written after the snapshot (`discarded_writes`,
    critical incident `update.rollback_discarded_writes`).
  - `cli/update.php doctor --skip-backup-probe` skips the doctor's real backup/restore probe.
  - `cli/migrate.php` fails closed when the pre-migration backup cannot be initialised (it used to
    continue without a backup); pass `--skip-backup` (or disable `auto_pre_migration`) knowingly.
  - Files backups skip unreadable files inside a readable source (listed as `files_skipped`); an
    unreadable configured source still fails the backup. `config/backup.json` `include_files`
    (default `true`) turns the files archive off.
- **New config keys** (all optional; see `config.example.php` and `config/config.schema.json`, which
  now lists every key the code reads): `backup_recovery_dir` (default
  `<private_runtime_dir>/restore-recovery`), `backup_mysql_restore_scratch_database`,
  `update_mysql_dump_binary` / `update_mysql_client_binary` (defaults `mysqldump` / `mysql`, falling
  back to `mariadb-dump` / `mariadb` when only those are on PATH),
  `monitoring.csp_form_action_reports_critical` (default 5), `uploads_dir` / `uploads_url`
  (documented; default `<shop>/uploads`, `/uploads`), `payu_*`. `cli/staging-preflight.php` now also
  requires `backup_recovery_dir` and `update_storage_dir` outside the application root.
- **Migrations**: `20261015120000_processed_events_claim_state` (webhook claim `state`/`claimed_at`),
  `20261016120000_csp_reporter_hash`, `20261016130000_erp_status_checked_at`,
  `20261016140000_csp_request_id`, `20261016150000_normalize_coupon_use_emails` (all idempotent; the
  code degrades instead of failing between deploying the files and running them).
  `20261015120000_processed_events_claim_state` gained a missing-table guard after release; its old
  checksum is accepted (`Migrator::LEGACY_CHECKSUMS`).
- **Daily `cleanup` worker job** (queued at most once a day by the worker): deletes processed webhook
  events after 7 days, replayable API idempotency responses after 30 days, delivered outbox events
  and their delivery attempts after 30 days, completed queue jobs and `jobs/completed/*.json` after
  7 days, and CSP violation reports after 14 days. Dead/failed rows stay for the operator.
- **Admin users**: a delegated (non-owner) `admins.manage` holder may only create or manage accounts
  whose role permissions it holds itself, in every store the target is granted; the create form lists
  only those roles, and a non-owner cannot change its own grants, role or status (it may still reset
  its own password/MFA and revoke its own sessions). Owners are unchanged.
- **Payment webhooks**: a webhook event is claimed as `processing` and marked `done` only after its
  handler finished; a claim older than the 300 s lease (`Idempotency::LEASE_SECONDS`) is taken over
  by a provider retry, and a timed-out handler can no longer delete the takeover's claim. Signature
  checks moved to the capability interface `SignedWebhookPaymentAdapter`: **extension payment
  adapters that receive signed webhooks must implement it**, otherwise their webhooks are rejected as
  unverified (fail closed).
- **Payments after cancellation**: money received for a cancelled/expired order (gateway webhook,
  offline transfer confirm, admin "mark paid") is recorded, the order stays cancelled, a refund is
  flagged (provider refund, or `refund_manual_required` for offline/manual payments) and the critical
  incident `payment.captured_after_cancel` is raised. Cancelling a paid order without `payment_id`
  (bank transfer/COD) raises `payment.order_refund_manual`; a second capture not matching the recorded
  payment raises `payment.duplicate_capture`. A payment can be set to cancelled only on a cancelled order.
- **CSP alarm thresholds**: `csp_form_action_violation` (critical) fires when blocked form-action
  reports come from `csp_form_action_critical` (default 2) distinct reporters (salted IP hashes), or -
  only while `trusted_proxies` is empty and from at least two separate report requests - when the
  raw report count reaches `csp_form_action_reports_critical` (default 5). One client can raise that
  fallback; configure `trusted_proxies` behind a proxy/CDN to rely on distinct reporters only.
- **Inventory feeds**: `Warehouse::bulkSetStock` applies the valid rows and skips invalid ones (empty
  SKU, non-integer or negative count), reporting them (incident `inventory.feed_invalid_rows`)
  instead of rejecting the whole feed.
- **Storefront files**: the account portal moved from `account.php` into
  `src/Entrypoints/Storefront/Account.php` (URLs and forms unchanged; customisations of `account.php`
  must move). `page.php` renders CMS pages in the theme shell (`Storefront/Layout.php`, theme assets,
  SEO head; a missing slug is a 404 inside the shell). The legacy `shop.js` / `shop.css` and the
  `base_css` / `shop_js` document slots are removed: themes that referenced them must ship their own assets.
- **E-mail normalisation**: customer and coupon-use e-mails are stored and compared as
  `Str::normalizeEmail()` (trim + ASCII lowercase) without SQL `LOWER()`. Coupon per-customer limits
  previously lowercased non-ASCII letters too; such addresses now count separately.

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
- Payment webhook signatures are a capability: `SignedWebhookPaymentAdapter::verifySignature()`
  (implemented by Stripe, PayU, GoPay, Comgate, ThePay, Mock). `PaymentAdapter` is unchanged, so
  extension adapters without `verifySignature()` still load; a webhook for a gateway without the
  capability is rejected as unverified (fail closed). Extension adapters that receive signed
  webhooks must add `implements SignedWebhookPaymentAdapter`.
- Storage adapters advertise pre-generated image variants with `ImageVariantStorageAdapter`;
  custom adapters that only define a public `generateVariants()` method keep working.

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
