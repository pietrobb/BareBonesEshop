# Changelog

## Unreleased

### Upgrade notes (operator-facing and breaking changes from PRs #10-#14 and the follow-up audits)

Read before updating a live shop; run `php cli/migrate.php` after the files are in place, then
`php cli/setup.php doctor` (and `php cli/staging-preflight.php` on staging), which warn about the
settings below that silently change behaviour (http webhooks, cart reminders without consent, the
nginx PHP fallback when `operations.nginx_config` is set).

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
  (documented; default `<shop>/uploads`, `/uploads`), `payu_*`, `operations.nginx_config` (optional path
  of the live nginx site config for the doctor), `outbox_sweep_budget_seconds` (default 20),
  `shipping_webhook_require_timestamp` (default false), `digital_download_resume_minutes` (default 30),
  `queue.redis.completed_ttl_seconds` (default 7 days), `integrations.http_shipping.rates_timeout`
  (default 5 s), `outbound_webhooks.<code>.allow_insecure_http`, `checkout_consents.cart_reminder`. `cli/staging-preflight.php` now also
  requires `backup_recovery_dir` and `update_storage_dir` outside the application root.
- **Migrations**: `20261015120000_processed_events_claim_state` (webhook claim `state`/`claimed_at`),
  `20261016120000_csp_reporter_hash`, `20261016130000_erp_status_checked_at`,
  `20261016140000_csp_request_id` (CSP report `request_id`),
  `20261016140500_invoice_number_counters` (per-seller document series, see Invoices below),
  `20261016141000_slug_registry_product_store` (translated product slugs of non-default catalogs),
  `20261016142000_cart_reminder_consent_download_resume` (cart reminder consent columns on
  `abandoned_carts`, `digital_entitlements.last_download_at`),
  `20261016150000_normalize_coupon_use_emails`,
  `20261016161000_invoice_seller_download_resume_bound` (`invoices.seller_code`, so credit notes follow
  their invoice's series; `digital_entitlements.last_download_offset`/`resume_count` bounding free download
  resumes) (all idempotent; the code degrades instead of failing between deploying the files and running them).
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
  `Str::normalizeEmail()` (trim + UTF-8 `mb_strtolower`) without SQL `LOWER()`, so `Éva@x` and
  `éva@x` share one per-customer coupon limit on SQLite and MySQL alike. Migration
  `20261016150000_normalize_coupon_use_emails` rewrites existing `coupon_uses` rows the same way.
- **nginx (store-prefixed scripts)**: `deploy/nginx.conf` now ends its PHP location with
  `location ~ \.php$ { try_files $uri @redirect; ... }`. Store-prefixed scripts such as `/cs/account.php`
  and `/cs/wishlist.php` do not exist on disk; without that line nginx answers 404 for them (account
  portal and wishlist of a prefixed store). The store-prefixed API (`/cs/api/v1/…`) reaches `@redirect`
  through `location /`. Copy the block into your site config and reload nginx. Apache (`.htaccess`) and
  `router.php` need no change. Set `operations.nginx_config` to the live file and `php cli/setup.php doctor` /
  `cli/staging-preflight.php` warn when the line is missing.
- **Outbound webhooks**: endpoints must use `https://`; a plain `http://` URL is refused (failed
  delivery audit row, warning incident `webhook.endpoint_misconfigured`, `provider.webhook_config`
  doctor failure) unless the endpoint sets `'allow_insecure_http' => true` (only for a receiver on a
  trusted local network). Secrets must be 32+ bytes and endpoint codes `[a-z0-9_-]`. An endpoint that
  times out twice in one sweep is skipped for the rest of it (`outbox_sweep_budget_seconds`, default
  20, time-boxes the sweep); an event whose only failures are such skips is released without using up
  a delivery attempt.
- **Cart reminders are opt-in and need a consent**: the feature is off by default, and a cart (and its
  e-mail) is only kept for a reminder when the shopper ticked the checkout consent `cart_reminder`.
  An existing `config.php` with `'cart_reminder' => ['enabled' => true, ...]` but no such consent
  **silently stops sending reminders**. Configure it, e.g.
  `'checkout_consents' => ['cart_reminder' => ['version' => '2026-10-16', 'required' => false, 'text' => 'Send me one e-mail reminder if I do not finish this order.']]`
  (optional, never `required`; bump `version` when the text changes). Carts stored before the upgrade
  have no consent and are not reminded. Reminders carry `List-Unsubscribe` / one-click
  `List-Unsubscribe-Post`. Doctor/preflight warn about `enabled` without the consent.
- **Invoices and credit notes**: an invoice can only be issued for a paid order
  (paid/processing/shipped/delivered, payment neither failed nor cancelled); the admin button and
  `InvoiceService::issue()` refuse unpaid orders. A **seller** is a store with its own `legal` block
  in `stores.json`; a store without one invoices as the first seller selling in the order's currency,
  else as the default store (as before), and shares that seller's series. Two storefronts of one
  company can share a series explicitly with `legal.seller: "<store code>"`. Numbers are counted per
  seller + document type + year + prefix (`invoice_number_counters`); a legacy shared
  `invoice_counters` row goes to the seller that owns the prefix by configuration (the default store
  for `''`/`CN-`) and keeps its last number. Invoices record `seller_code`; a credit note follows its
  invoice's seller and series (legacy invoices: from the number prefix or seller snapshot). The default
  credit-note prefix is now `<invoice_prefix>CN-` (unchanged `CN-` for a seller without an invoice
  prefix): **a separate seller that relied on the shared `CN-` series starts a new series mid-year**
  unless you set `legal.credit_note_prefix`. Two real sellers sharing a prefix are a conflict: `cli/setup.php
  doctor` (`stores.invoice_numbering`) and `cli/staging-preflight.php` report it, and issuing for the
  non-owning seller is refused with an `invoice.numbering_conflict` incident; the owning seller keeps issuing.
- **Extension API actions**: every non-GET/HEAD request to an extension action must carry the session
  CSRF token (`X-CSRF-Token` or `csrf_token`, HTTP 403 otherwise); actions can be limited with
  `'method' => 'GET'|'POST'` (405 otherwise). **Server-to-server POSTs to an extension action break**
  unless the action declares `'csrf' => false` (then authenticate it yourself, e.g. a signature).
  Extension listener endpoint keys are now `ext:<code>:<name>`; a duplicate fails the extension's load.
- **Supplier feeds**: feeds are store-scoped (a feed belongs to its warehouse's store). A feed whose
  stored `import_mode` is not one of `upsert`, `price_stock`, `stock_only` now ends with
  `error: bad import mode` instead of running a full upsert; fix the mode in the admin feed form.
- **Digital asset paths**: new and re-saved digital assets are stored under
  `<digital_assets_base_dir>/<catalog_id>/`; traversal and symlink escapes are refused. Existing rows
  keep their base-relative path and keep downloading. Downloads support HTTP ranges; a resume within
  `digital_download_resume_minutes` (default 30) is not counted again when it continues the counted
  transfer (starts at most 16 MiB below the bytes it served, at most 5 free resumes); any other range is a
  new download, so `Range: bytes=1-` is no free copy once the limit is spent.
- **Storefront routing**: an unknown `?page=` value (and an unknown category) now answers 404 instead
  of the homepage with 200; `?page=home` and no page still render home. The footer About/Contact links
  point to `/page/about` and `/page/contact` and are shown only when those CMS pages exist and are
  active (create them to keep the links).
- **Carrier tracking webhooks**: only the statuses `created`, `in_transit`, `out_for_delivery`,
  `ready_for_pickup`, `failed`, `delivered` are accepted (others answer `unknown_status`); a label never
  moves back to a lower status and a cancelled label is not revived. Carriers may sign
  `X-Shipping-Timestamp` (HMAC of `"<ts>.<body>"`, ±300 s); `shipping_webhook_require_timestamp => true`
  makes it mandatory. Carrier codes may contain `-` (secret env `SHIP_HTTP_CARRIER_WEBHOOK_SECRET`); a
  missing/weak secret answers 503 with the critical incident `shipping.webhook_misconfigured`.
- **Shipping quotes outside EUR**: Packeta and GLS built-in tiers, COD fees and free-shipping thresholds
  are EUR amounts and are only quoted in EUR stores; `shipping.json` declares `"currency": "EUR"` and is
  not quoted to other currencies. **A non-EUR store gets no Packeta/GLS/shipping.json quote until it has
  its own `shipping_rates` rows** (admin shipping rates, scoped to the store). A failing carrier adapter
  is skipped (warning incident `shipping.rates_adapter_failed`) instead of failing all rates.
- **Checkout destinations**: physical orders to a country that is not in the offered destination
  countries (the zones of `shipping.json` and the store's shipping zones) are refused; `Shipping::calculate` returns -1
  (unavailable) for unknown methods and countries instead of free shipping.
- **Mail**: `SmtpMail` refuses AUTH over an unencrypted connection unless `SMTP_ALLOW_PLAINTEXT_AUTH=1`
  (use `SMTP_ENCRYPTION=tls|ssl`); EHLO uses `SMTP_HELO` / the host name. `SesMail` sends through
  `SendRawEmail` (attachments and custom headers are kept; the IAM policy needs `ses:SendRawEmail`).
  `MailService::send()` can return the new status `rate_limited` (order mail is limited per order;
  the worker re-queues the job after the window, at most 3 times) - custom callers that treated only
  `sent`/`failed`/`skipped` must handle it.
- **Uploads**: without `uploads_dir` in `config.php`, `LocalStorage` now writes to the application's
  `uploads/` (served as `/uploads`) instead of `adapters/uploads/`; images uploaded by such installs
  before the upgrade are in `adapters/uploads/` and must be moved to `uploads/`.
- **Cart prices**: a line whose effective unit price is negative (e.g. a variant `price_delta` that
  outgrew a lowered base price) is no longer clamped to a free item: it stays in the cart as
  unavailable (`unavailable_reason: price_invalid`), checkout skips it and the incident
  `catalog.negative_price` is raised. Fix the variant price.
- **Reviews**: one review per product per customer, for guests per browser session plus at most
  `Reviews::GUEST_IP_CAP` (5) per client IP, within 24 hours; a refused attempt no longer extends the block.
- **CSV exports**: the product and order exports stream from the database. A failure mid-stream
  (e.g. MySQL `net_write_timeout` on a slow download) is logged (`export.truncated`) and raises the
  incident `export.truncated`; when nothing was sent yet the download answers 500, otherwise the file
  is incomplete - check the incidents before importing an export elsewhere.

### Changed (breaking) — one JSON API under `/api/v1`
- `api.php` and the `/api/<action>` rewrite are removed. Its actions moved to route groups of the
  existing `/api/v1` entrypoint (routes in `src/ApiRoute.php`; handlers and responses unchanged):
  - `api.php?action=<action>` → `/api/v1/storefront/<action>` (also extension actions `<code>.<name>`)
  - `api.php?action=bbf_<name>` → `/api/v1/options/<name>` (forms `options_from`)
  - `api.php?action=webhook_payment&provider=<p>` and `action=webhook_stripe` → `/api/v1/webhooks/payment/<p>`
  - `api.php?action=webhook_shipping&carrier=<c>` → `/api/v1/webhooks/shipping/<c>`
  - `api.php?action=csp_report` → `/api/v1/csp-report`
  - An unknown action answers 404 (was 200) with the same `{"error": "Unknown action"}` body.
  - A store with a `url_prefix` calls `<prefix>/api/v1/storefront/<action>` (e.g. `/cs/api/v1/…`, was
    `/cs/api.php`); the API resolves the store, locale and currency from that path.
- **Upgrade:** when deploying, change the webhook URL in every payment gateway portal at the same
  time: Stripe (Developers → Webhooks) to `<app_url>/api/v1/webhooks/payment/stripe`, Comgate push URL
  to `<app_url>/api/v1/webhooks/payment/comgate`, and likewise any carrier webhooks. GoPay, ThePay and
  PayU get the new notification URL with every new payment; payments created before the deploy keep
  calling the old `api.php` URL they were given. Custom themes that call
  `api.php` must use `window.BareBonesStorefront.apiUrl` + `/<action>`; nginx needs the updated
  `deploy/nginx.conf` (the `/api/(.*)` rewrite is gone, an `api.php` rewrite is added).
- **Legacy routes for one release** (removed in the next one): `/api.php?action=` `webhook_payment`,
  `webhook_stripe`, `webhook_shipping` and `csp_report` are rewritten to the API (`.htaccess`,
  `deploy/nginx.conf`, `router.php`), so notifications of in-flight payments and not yet updated portal
  URLs still arrive; every other `api.php` action answers 404. `/api/v1/storefront?action=<action>` serves
  `storefront.js` cached from before the update (the theme version is now 2.3.0, so fresh pages load the
  new script). Update portal and carrier URLs before the next release.
- Carrier webhook routes accept the same codes as the handler, including hyphens (the default
  `http-carrier`): `/api/v1/webhooks/shipping/http-carrier` answered 404.
- The external API also answers below an install subdirectory and a store prefix (`/shop/api/v1/…`,
  `/cs/api/v1/…`, were 404). A store prefix must name the API key's store, otherwise 403 `forbidden`.

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
  - CSP `report-uri` + `report-to` (`Reporting-Endpoints`) → `/api/v1/csp-report` → table
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
- Review form: a store with a `url_prefix` posts reviews into its own store (was the default store), and
  the form shows the duplicate-review and rate-limit messages instead of the generic error.
- Plain-text e-mail parts derived from HTML keep table cells (tab), rows, paragraphs, list items and
  line breaks apart and show link targets as `text (URL)` (was e.g. "ProduktKsCena", links without URL).
- **Security:** a guest checkout name (or any other top-level mail variable) containing
  `\x00<base64>\x01` was unsealed as raw HTML into transactional e-mails; `{{#each}}` row values are now
  sealed with a per-render random marker. Checkout strips control characters from name, phone, address
  and company at input.
- **Security:** rich-text links (`Security::sanitizeRichHtml`) use a prefix allow-list (`http(s)://`,
  `mailto:`, `tel:`, `/path`, `#`, `?`, relative paths without a scheme); `javascript:80/…` and similar
  targets passed because `parse_url` saw no scheme. Whitespace/control characters are removed first.
- **Security:** `cache/` is denied on the decoded path in `.htaccess` (the `THE_REQUEST` condition
  was bypassed by `/c%61che/…`); `router.php` no longer evaluates `THE_REQUEST` conditions.
- **Security:** `validate_coupon` has its own throttle (10 attempts / 10 minutes per client IP and
  per session, then 429 `Too many attempts`) and answers `Invalid coupon` for unknown, expired, used-up
  and already-used codes alike; `Minimum order not met` is reported only for an otherwise usable code
  (pricing.json coupons check usage limits before the minimum order now, as admin coupons did).

### Migration
- `20261008090000_stock_alerts` (idempotent): `products.stock_low_threshold`,
  `products.stock_critical_threshold`, tables `stock_alert_state`, `stock_alert_digests`.
