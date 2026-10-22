# Changelog

## Unreleased

### Upgrade notes (operator-facing and breaking changes from PRs #10-#14 and the follow-up audits)

Read before updating a live shop; run `php cli/migrate.php` after the files are in place, then
`php cli/setup.php doctor` (and `php cli/staging-preflight.php` on staging), which warn about the
settings below that silently change behaviour (http webhooks, cart reminders without consent, the
nginx PHP fallback when `operations.nginx_config` is set).

- **Upgrading a released shop** (`docs/AI_UPDATE_RUNBOOK.md`)
  - v1.8.0: the trusted new release adopts the shop with `cli/update.php ... --installed=<shop root>`
    (no v1.9.0 package exists). Save the git-tracked v1.x `config.php` first, and move `db/`, `jobs/`
    and `cache/` outside the shop. `update.php doctor` (check `config`) and `setup.php doctor` name
    every private path still inside the application root.
  - v2.0.0 e-mail templates edited in `templates/emails/` become store overrides with
    `php cli/email-templates.php import --custom=<saved copy> --base=<v2.0.0 files>`. An
    order-confirmation override must keep `seller_name`, `seller_address`, `withdrawal_url` and
    `terms_url`.
  - `update.php rollback` now counts and reports the orders/payments it discards; `doctor` no longer
    reports NOT_READY for every package with migrations.
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
- **Refunds**: an admin "Request refund" now queues the `payment_refund` worker job that executes it
  at the provider (before, nothing executed it and the payment stayed `refund_pending`). Such an old
  request at a provider with a refund API gets the audited admin action "Retry refund" (permission
  `orders.refund`), which queues its job under the same idempotency key a new request would use; it is
  offered only while nothing was booked after the request and no job ever executed it. Payments no provider refund API returns (bank transfer, COD, manual, no
  `payment_id`) get the audited admin action "Record manual refund" (permission `orders.refund`),
  which books the amount and moves the payment to `partially_refunded`/`refunded`. **A paid order can
  no longer be set to `refunded` while a refundable balance remains** (admin, bulk, API v1 answer
  `refund_outstanding`): the status restocks, revokes downloads and mails "refund issued" but moves no
  money; cancel the order or request a refund instead, which closes it when the refund completes.
  Refund ledger rows are keyed by the provider refund id (Stripe `charge.refunded` reads the
  charge's refunds, from the Stripe API when the event does not embed them, so the webhook needs the
  configured `stripe_secret_key`); a refund the webhook booked first is logged by the worker as
  `refund_confirmed` and counts once. Item (credit-note) refunds a provider accepts asynchronously
  (PayU `PENDING`, Stripe `pending`) no longer fail the job: the refund webhook completes the credit
  note by refund id or amount. A GoPay `REFUNDED` / ThePay `refunded` notification completes the
  shop's refund in flight when it is the whole remaining balance; the ledger row names the request it
  settled, so the refund worker's own result for that request is only `refund_confirmed`. A failed GoPay/Comgate/ThePay
  notification raises the critical incident `payment.webhook_failed`. Downloads stay available after
  a partial refund, except for credited lines. No migration.
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
- **VAT and documents (audit round 5)** — **have the merchant's accountant confirm the rates and the
  legal wording below before going live; they are defaults, not tax advice.**
  - `config/vat-rates.json`: product categories may have their own rate per country and period
    (`categories`, keyed by `products.vat_class`): SK `books`/`medicines` 5 % from 2025 (10 % before),
    CZ `books` 0 % from 2024. EE standard 24 % from 2025-07-01, RO 21 % / 11 % from 2025-08-01
    (earlier rates kept in `history`).
  - The payment (e.g. cash-on-delivery) fee is part of the price of the goods and carries the VAT of
    the highest goods rate, like shipping (it used to be exempt). Existing order snapshots are unchanged.
  - A digital-only order to a non-EU country keeps the seller's VAT: the declared country alone is no
    evidence for a zero-rated export. Goods shipped there are still an export.
  - In-store pickup (`personal_pickup`) is a supply in the seller's country (seller's VAT), whatever
    the buyer's address.
  - Automatic free shipping (`pricing.json` `cart_rules`) is earned by the subtotal after the coupon
    and automatic discounts, and only in the rule's currency (`conditions.currency`, default the
    currency of the store whose `cart_rules` they are).
  - A buy-X-get-Y discount is booked on the free product's lines (its own VAT rate), not spread over the cart.
  - Migration `20261019090000_vat_id_checks`: every live VIES check is kept (`vat_id_checks`: status,
    trader, VIES `requestIdentifier`, raw answer) as evidence of a reverse-charge sale; the VIES
    parser reads namespaced answers (`<ns2:valid>`), and a SOAP fault is "unverified", never "invalid".
  - OSS report: credit notes reduce the period (60 € invoice − 20 € credit note = 40 €).
  - Invoice PDF: price without VAT per line, discount / payment-fee lines so the lines add up to the
    totals, the credit note names the invoice it corrects, exemptions cite their legal ground
    (intra-EU supply of goods Art. 138, export Art. 146, B2B e-services reverse charge Art. 196).
    The date of supply is the payment or shipment date, not the (late or backfilled) issue date.
  - The invoice e-mail prints the amount, issue and due date, online link and seller in all 7 languages.
  - Sales reports and the dashboard count money received: paid orders less refunds; unpaid
    (pending, failed, awaiting transfer / COD) and refunded orders are no longer revenue.
- **Money and robustness (audit round 5)**
  - A product-level price-list row (no variant) prices the product base: a variant with its own
    absolute price keeps it (a 999 € variant was sold for the product's 67.47 €), a variant with a
    price delta costs the listed base + delta. A variant row of the price list still wins.
  - An extension event listener that leaves a database transaction open is rolled back and recorded as a
    failed delivery (`extension.listener_open_transaction`); it used to swallow every later write of
    the worker run, refund records included.
  - The catalog cache marker is compared by file modification time (one clock, also on network or
    container volumes), and a catalog change made inside a transaction writes the marker again after
    the commit.
  - E-mail template overrides (`emails.manage`): one-time-token links (`reset_url`, `verify_url`,
    `unsubscribe_url`, `cart_url`, download `url`) may only be a whole `href="{{…}}"` or stand alone in
    the text, never inside an image, style or foreign address, nor in the subject; a hard-coded IBAN is
    refused (use `{{bank_iban}}`). **An existing override that does this is refused on its next save.**
    The audit log keeps the previous subject and a line diff of the HTML and text parts, not only hashes.
- **Customers, e-mails and GDPR (audit round 5)**
  - Migration `20261019120000_customer_email_per_store`: a customer e-mail is unique per store
    (`uq_customers_store_email` on `store_id, email`) instead of across the installation, so an
    account in one store no longer blocks (or lets anyone squat) the same address in another store.
    SQLite rebuilds `customers` with foreign keys off (addresses, reset tokens and verifications are kept).
  - Password reset and e-mail verification links of a store with its own `domains` use that domain.
  - A new reset request no longer cancels the links the owner already has (at most 5 open links per
    account; further requests are dropped silently); setting the password invalidates the others.
  - GDPR erasure records a `customer.erased` event for webhook subscribers so they erase
    their copies (customer id, guest flag and retained order ids — never the e-mail).
  - Admin GDPR: guests (orders without an account) can be exported and erased by e-mail like customers.
- **Installation and deployment (audit round 5)**
  - `setup.php install` into a subdirectory of a web root (`public_html/shop`, also `httpdocs`, `htdocs`,
    `wwwroot`, `html`, `www`, `web`) writes the private paths above it (`../bareboneseshop-data/<dir>/`);
    the template's `../bareboneseshop-private` would have been inside `public_html`, database included.
    **`doctor` and `staging-preflight` now fail a private path inside such a web root**: move it, or set
    the new `operations.document_root` when the guess is wrong.
  - `install` over an existing database whose admins' MFA secrets need another `admin_identity_key`
    (lost `config.php`, new checkout over the old private data) stops and removes the new `config.php`
    instead of locking every admin out. `doctor` (`admin.accounts`, `admin.mfa_key`) and
    `staging-preflight` (`admin_accounts`) fail when nobody can sign in.
  - `staging-preflight` no longer demands the legacy `admin_password` once a database admin account
    exists, reports unset carrier webhook secrets as a warning (a placeholder or short one still fails),
    checks `cache_dir` / `uploads_dir` instead of an unused `storage/`, and both tools read the mail
    provider like `MailService` (`MAIL_ADAPTER` first). `apply` creates `cache_dir` and `uploads_dir`.
  - Upload directories are created 0755, so a web server running as another user (nginx) serves them.
  - Docker sample: the image uses the production `php.ini` (errors logged, never displayed,
    `expose_php` off); the `setup` service runs `deploy/docker-setup.sh` as root, which creates
    `config.php` in the host-owned checkout and hands `cache/`, `uploads/`, `import/`, `logs/` and the
    private volume to uid 33. CI runs the README Docker install and fetches an uploaded image.
- **Shipping, API and extensions (audit round 5)**
  - Migration `20261019150000_shipment_tracking_event_sequence`: `shipments.tracking_status` /
    `delivered_at`, `event_outbox.seq` and the `event_sequence` counter table.
  - Carrier tracking webhooks also find the shipments shipped in the admin (carrier and tracking number
    as typed: case, spaces and dashes are ignored); they only looked at generated labels, so every real
    parcel ended as `unknown_awb`. The status only moves forward; the order becomes `delivered` when every
    parcel of a shipped order is delivered.
  - A carrier whose adapter cannot be constructed (missing key, broken extension) is left out of the
    rates with a `shipping.adapter_unavailable` incident instead of failing the checkout; a storefront
    view-model provider that throws or returns no array is left out of the page
    (`storefront.extension_failed`) instead of answering 500.
  - **Outgoing webhooks retry for about three days** (5 s doubling up to 6 h, `Outbox::MAX_ATTEMPTS` 25
    instead of 8 attempts over ~11 minutes). `php cli/outbox-requeue.php` lists dead events and queues
    them again (`<event-id>` or `--all`); endpoints that already acknowledged an event are skipped.
  - The event envelope has a `sequence` that only grows: for the same `subject` the higher one is the
    later change (deliveries can arrive out of order after retries).
  - API v1 `GET`/`PATCH /products/{id}` return an `ETag`; `PATCH` with `If-Match` answers 412
    `precondition_failed` when the product changed meanwhile (optional, existing clients keep working).
- **Audit round 6 (medium findings)**
  - Refunds: a provider refund that was accepted but that no notification can confirm (GoPay partial
    refund) is completed in the order detail with "Confirm refund paid" (permission `orders.refund`, audited).
    This includes item refunds (credit note / RMA) at GoPay: confirming books the amount once and completes
    that credit note's refund, also when an earlier refund on the order was already confirmed.
  - Prices: a customer-group product row plus the variant surcharge never prices a variant above its
    public variant row. B2B credit exposure counts only unpaid orders.
  - E-mail templates: overrides cannot put tokens into `<img>` attributes or style sheets, nor hard-code
    a bank account instead of `{{bank_iban}}`; the merchant alerts `core_drift_alert` and
    `merchant_incident` can no longer be overridden.
  - Documents: `cli/oss-report.php --store` includes the shared `CN-` credit notes; automatic free
    shipping keeps the cash-on-delivery fee; the invoice exemption text follows the seller's country;
    `cli/invoices-backfill.php` issues every store's orders in their own store and replaces cancelled
    invoices (the cancelled one keeps its number, type `replaced`); `cli/vat-backfill.php` uses the
    order's store as seller and taxes the whole total (discount and shipping included).
  - Customers: the forgot-password throttle is per store and counts only mails actually sent; guest
    erasure leaves an account with the same e-mail alone; invoice links use the store's own domain.
  - **`php cli/setup.php apply` (and so `install`) refuses private paths inside an enclosing web root**
    instead of creating them; it also creates `digital_assets_base_dir` when that is private.
    `config.example.php` keeps digital products in `<private runtime dir>/downloads` (existing configs
    are unchanged). The Docker image accepts uploads up to 20 MB.
  - `/readyz` reports `database: read_only` (not ready) and `doctor` fails `database.writable` when the
    database answers reads but refuses writes.
  - `product.updated` is also recorded for admin edits, CSV imports and feed price updates;
    `stock.adjusted` for every stock-ledger adjustment (admin, import, feed, purchase receipt, API).
    **The `stock.adjusted` `reason` is now the ledger movement type** (`manual_adjustment`, `import`,
    `feed_sync`, `receipt`, `api_adjustment`) plus `reference_type`, instead of `api.v1.product.patch` /
    `api.v1.inventory.adjust`.
- **Audit round 6 (low findings)**
  - **API keys: only known scopes.** `cli/api-client.php create` refuses a scope no endpoint checks
    (`product:read` instead of `products:read` used to create a key that could do nothing) and lists the
    allowed ones. A stored unknown scope grants nothing but does not lock the key out. Each key also has
    its own budget across all source addresses: `api_client_rate_limit` requests per minute (default
    600, HTTP 429), on top of the existing 300 per minute per IP.
  - Customer login is per store also inside one PHP session: visiting another store on the same host no
    longer logs the shopper out of the first one, and that store does not see the login. Logout ends the
    login in every store and empties the cart, its contact e-mail and the coupon (shared computers).
  - Changing the password in the account invalidates the reset links that were still open.
  - LogMail (development mail adapter) rotates `emails.log` to `emails.log.1` at `log_max_bytes`
    (default 5 MiB).
  - `php cli/setup.php install --admin-username=NAME` can be re-run: an existing owner is left unchanged
    (it used to fail on the second run).
  - GDPR retention: `doctor` warns (`operations.gdpr_retention_gc`) when `cli/gdpr-gc.php --apply` has
    not run in 8 days; the README cron list includes it and the Docker worker runs it once a day.
  - Migration lock per database: SQLite locks `<database file>.migrations.lock`, MySQL/MariaDB take
    `GET_LOCK`, so two app directories or containers migrating one database exclude each other.
  - Packeta: the checkout pick-up point field has a search (town, street or point name, diacritics
    ignored), so a point beyond the nearest 50 can be found and chosen.
- **Audit round 7 (hotfix)**
  - **A warehouse whose stock open orders still reserve cannot be deleted** (admin explains why); deleting
    one destroyed those reservations. The pending-order expiry isolates every order and every store: an
    order whose reservation cannot be released stays pending with a critical `order.expiry_failed`
    incident instead of stopping expiry of all later orders in all stores.
  - MySQL/MariaDB: shipments, cancellations and purchase-order receipts lock the order / purchase order
    before reading it, so two concurrent partial shipments cannot ship one order twice (or take another
    order's reservation), and concurrent receipts cannot receive more than ordered.
  - **Price lists of a shared provider scope are in the scope owner's currency.** A store whose currency
    differs from the store owning its `provider_scope` (the sample CZK/PLN/HUF/USD stores share the EUR
    one) no longer charges those shipping rates, COD surcharges, free-shipping thresholds or payment fees
    as its own money (4,99 Kč shipping): it gets only carriers with their own prices in its currency and
    payment methods without fixed amounts. **Give such a store its own `provider_scope`** with shipping
    methods and payment methods priced in its currency.
  - **The sample CZ, HU and PL stores have their own price lists** (they offered only personal pickup and
    fee-less payments). Migration `20261022230000_currency_price_lists` seeds the provider scopes `cz`,
    `hu` and `pl` with pickup, Packeta, GLS, card, bank transfer and cash on delivery priced in CZK, HUF
    and PLN (a scope that already has a shipping or payment method is left alone), and the sample
    `stores.json` points those stores at them. An existing installation keeps its `stores.json`: set the
    store's `provider_scope` to `cz` / `hu` / `pl` to use the seeded price list (review the amounts in
    admin → Shipping / Payments first), or keep sharing the EUR scope. The USD sample store still shares it.
    A price list is charged only to a cart in its currency - the products' currency, which can differ from
    the store's: EUR goods of the shared catalog in the CZ store get the carriers' EUR rates, never the
    CZK amounts as euros.
  - A credit note paid back by hand (order without a payment id) counts as refunded: the order can no
    longer be refunded for that amount a second time.
  - SQLite: transactions take the write lock when they begin (`BEGIN IMMEDIATE`, `ShopPDO`), so a
    read-then-write transaction waits for another writer (`busy_timeout`) instead of failing at once with
    "database is locked" (8–16 % of checkouts at 8 concurrent clients).
  - `/readyz` and `doctor` report a SQLite database another process is writing (backup, import, GDPR gc)
    as writable, without waiting for it; only a truly read-only database is `read_only`.
  - **The shop-wide checkout cap is off by default** (`checkout_global_limit`, attempts per minute, 0 =
    off): a hard-coded 200 that refused attempts counted towards let twenty addresses lock every shopper
    out. When set, it counts only attempts that passed the per-IP (10/min) and per-session limits.
  - Packeta: a postal code with a hyphen (PL `00-950`) lists the nearest points again instead of none.
- **Audit round 7 (money and checkout)**
  - **Currencies shown without minor units (CZK, HUF) are charged in whole units**: item prices, prices
    without domestic VAT, promotions, coupons, payment fees and shipping are rounded (`Money::round`),
    so a CZK order no longer totals 1 279,31 Kč. Stripe refuses a HUF amount that is not a multiple of
    100 with a clear error before calling the API.
  - **Export and reverse charge previews show what the order charges**: the cart, the checkout summary
    and the shipping/payment API price goods, shipping and fees without domestic VAT in those scenarios
    and judge free-shipping thresholds on the same amounts. The API takes the buyer's VAT ID as
    `?vat_id=` and returns `vat_scenario`; VIES checks from previews are rate-limited per client.
  - Previews ignore cart lines checkout does not order (sold out, unavailable): they no longer add
    weight or units, trigger promotions, or make an e-book order look like a parcel.
  - Enter in a checkout field re-prices the typed address instead of loading a saved one; a shipping
    method chosen while the rates load is kept.
  - GoPay/ThePay: two credit-note refunds accepted at once can both be confirmed; confirming the first
    no longer hides the second (its money was never booked). A confirmation never exceeds the
    refundable balance.
- **Audit round 7 (catalog and import)**
  - **Feeds in upsert mode book stock once**: a new product is no longer stocked in the default
    warehouse as well as the feed warehouse, and a lower count on a later sync lowers availability.
    A product the supplier no longer lists drops to its reserved units in the feed warehouse (only for
    a feed that maps stock, is the warehouse's only feed, and was read to the end; an empty download or
    a sync stopped at the error limit zeroes nothing). The sync result reports `stock_zeroed`.
  - Feed and CSV prices with thousands separators or currency signs (`1 299,00`, `€24.90`,
    `24.90 EUR`) are read correctly; a value that is not a price is a row error instead of 0.
  - MySQL search finds two-letter words (`TV`) and hyphenated SKUs (`AB-12`) and requires every word,
    as SQLite does.
  - `cli/migrate-magento.php` detects the table prefix (`--prefix=` overrides it) and fails when the
    dump has no products for it; migrated stock is booked into the default warehouse so products can be
    bought; images get the storage URL the storefront serves, stripped of metadata and queued for resizing.
  - Images: `http://` image URLs are served as `https://`; cards and thumbnails use the resized copy
    once the resize job wrote it; admin uploads are stored without EXIF/GPS, XMP and comments
    (orientation kept).
- **Audit round 7 (stock)**
  - **Backorders and preorders are allocated when stock arrives**: a receipt, adjustment, feed sync,
    return or released reservation of the SKU reserves it for waiting order lines of open orders,
    oldest order first, and the order can ship. Only the surplus goes on sale. On MySQL a waiting
    order another transaction holds (e.g. its shipment) is skipped instead of waited for, so a
    restock cannot deadlock; the next stock increase allocates it.
  - **Cancelling a partly shipped order refunds only the goods that never shipped**; shipping and
    fees stay charged and the order's planned shipments are cancelled. The shipped units can be
    returned through an RMA on the cancelled order (limited to the shipped units), whose restock
    now puts them back on stock and whose credit note refunds them.
  - An RMA receipt that discards (or keeps for repair) units the order-level return had already
    restocked writes them off again (ledger movement `rma_write_off`; units another order reserved
    meanwhile raise `inventory.rma_write_off_short`).
- **Audit round 7 (remaining findings)**
  - **Struck-through prices follow the EU Omnibus rule** (migration `20261020120000_product_price_history`):
    every price a product gets (admin, bulk actions, CSV/feed import, API v1 PATCH) is recorded, and the
    storefront shows as the prior price at most the lowest price of the 30 days before the reduction. A
    `compare_price` the product never actually had is no longer shown. **After the upgrade, discounts set
    before it show no struck-through price** (the migration records only the current price) until the
    price changes again; promotions and price lists below the base price still show the base price.
    Prices charged by catalog promotions and price lists in those 30 days count too, also after the admin
    re-dates, switches off, deletes or reprices them (migration `20261022150000_pricing_runs` keeps a copy
    of such a run, from the upgrade on).
  - **Google feed availability** follows the storefront: discontinued, disabled and not yet released
    products are `out of stock` whatever their stock, digital products are `in stock`, and products sold
    ahead of stock are `backorder` or `preorder` from their inventory policy.
  - **Return window** (migration `20261020140000_order_delivered_at`, new key `rma_return_window_days`,
    default 14, 0 = no limit): orders record `delivered_at` (backfilled for delivered orders) and a
    customer can request a return only within the window after delivery. The admin can still open a
    return later. **Shops that accepted returns at any time must set the key to 0.**
  - **Account erasure asks for the current password** (rate-limited like the password change).
  - **Old URLs keep working**: `cli/fix-slugs.php` keeps every replaced product/category slug, and the
    storefront answers an old or another language's slug with a 301 to the canonical URL. hreflang and
    the sitemap name each language's own product slug. Unknown addresses get the theme's 404 page.
  - **`cli/extract-specs.php`** (migration `20261020130000_product_specification_source`) replaces only
    the specifications it extracted itself; admin-entered and imported ones are kept. It uses the
    configured database instead of `db/shop.sqlite`.
  - **Catalog**: a category slug must be unique in the whole catalog (was per parent); the import
    prefixes a colliding subcategory slug with its parent's. Deleting a product books its warehouse
    stock out (a later product with that SKU no longer inherits it). Category product counts follow
    admin, bulk, API and feed writes.
  - **Promotions**: free shipping is offered for automatic cart promotions and coupons; a coupon that
    only waives the carriage is accepted and counts against its usage limits.
  - **Prices**: a variant whose negative surcharge would go below zero is refused in the editor and in
    price lists. API v1 product PATCH refuses `price`, `stock`, `weight_g` and `compare_price` above
    2000000000 with a 400 (was a 500 on MySQL).
  - **Carriers**: COD labels send the amount to collect (a carrier without COD refuses them), a label
    after a cancelled one is a new request, a delivered parcel delivers the order only when no other
    parcel is still on the way, `in_transit` after a `failed` attempt is applied, and a webhook
    shipment typed with an unregistered carrier (e.g. DPD) is no longer matched by the order method's
    carrier.
  - **Security and headers**: CSP `form-action` allows only the gateways of the store's active online
    payment methods (was every registered adapter). `X-Forwarded-Proto: https` from a `trusted_proxies`
    address enables HSTS and secure cookies. `vendor/bbf/submit.php` is no longer public. The invoice
    token link is `no-store` and `noindex`. E-mail templates refuse IBANs disguised by separators.
  - **Robustness**: concurrent payment webhooks and invoice numbering no longer deadlock on MySQL; an
    extension listener that runs `exec('BEGIN')` is rolled back on MySQL 8.0, MariaDB and SQLite; a
    failing rate-limiter store fails open (critical incident) instead of a 500; `invoice_issue` for an
    order cancelled meanwhile ends without an invoice; a feed CSV keeps quoted multi-line fields and a
    sync that lost rows zeroes no stock; admin bulk price/status actions emit `product.updated`.
  - **Accessibility**: the cookie banner works without JavaScript, and every admin form field has an
    accessible name.
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
- **Backup and update snapshot retention**: `cli/backup.php --gc` (and `auto_gc_on_backup`) now keeps
  only the newest `retention.pre_migration` (default 5) `pre-migration` backups instead of all of them;
  `manual` backups are still kept unless `retention.manual_max_age_days` (default 0 = forever) is set.
  `cli/update.php apply` deletes all but the newest `update_snapshots_keep` (default 3, min 1)
  `pre-update` snapshots in `update_storage_dir` (each a full database copy/dump); the snapshot of the
  installed release and `manual-backup` snapshots are never deleted. Raise the values before updating if
  you rely on older snapshots. **GDPR: erased or retention-scrubbed personal data survives in backups
  and update snapshots until they rotate out** - the oldest kept backup bounds how long (document it
  in your privacy policy / records of processing).
- **Retention `orders_pii`** also scrubs `orders.notes` (customer order notes). Copy the extra field
  into a customised `config/data-retention.json`. `scrub_pii` now only touches rows that still hold PII
  (a re-run neither rewrites already scrubbed rows nor counts them), and uses distinct placeholders
  (the repeated `:marker` failed under native MySQL prepares).
- **CSV import encoding**: a CSV that is not valid UTF-8 (Excel "CSV" in SK/CZ locales is Windows-1250)
  is converted from Windows-1250 (needs the `iconv` extension) and the admin flash / `cli/import.php`
  says so; a file that is neither is rejected with a hint to save it as "CSV UTF-8". Before, such files
  stored invalid UTF-8 names that blanked HTML and API responses. The JSON API now substitutes invalid
  UTF-8 (`JSON_INVALID_UTF8_SUBSTITUTE`) instead of answering an empty body; fix rows imported earlier
  by re-importing them as UTF-8.
- **API product/category cache**: product saves (admin, bulk actions, CSV/feed import, API v1 PATCH),
  category tree and pricing edits, and stock movements mark the catalog's cached `product/*.json`,
  `cat/*` and `categories.json` entries stale (`<cache_dir>/catalogs/<catalog>.changed`, shared by all
  stores of the catalog), so prices, names, availability and deactivation show up at once instead of
  after up to an hour. Admin, import, feed/ERP, receipt and return movements always do; checkout
  reservations, their releases and shipments only when a product or variant goes out of or back into
  stock, so a busy shop keeps its caches (the exact stock count shown may lag up to the cache TTL).
- **Worker logs**: refund attempts and processed imports are written to the structured log
  (`Logger`, redacted, in `<private_runtime_dir>/logs/application.jsonl`, events `refund.attempt`,
  `import.processed`) instead of `logs/refunds.log` / `logs/imports.log` in the application root.
  Existing files there can be archived and deleted.
- **SQLite search**: full-text queries join from the FTS index (`CROSS JOIN`), so a short query such as
  `?q=a` no longer scans every product (80 s → ~0.1 s at 50k products). `PRAGMA optimize` runs after
  migrations and CSV imports. MySQL/MariaDB FULLTEXT search is unchanged.
- **Stores sharing a catalog** (migration `20261017093000_catalog_stock_erp_retry`):
  - Stock belongs to the catalog: warehouses, warehouse stock, feeds and the admin warehouse pages are
    bound to the store's `catalog_scope`, so every store sharing a catalog sells from (and its admin
    edits) the same warehouses. The migration moves warehouses a store created under its own id to its
    catalog (the catalog's default warehouse stays the default) and refreshes the catalog's stock.
  - Gateway `success_url`, `cancel_url` and `notify_url` carry the order's store domain and
    `url_prefix` (e.g. `<app_url>/cs/api/v1/webhooks/payment/<p>`). Stripe events (one dashboard endpoint,
    the global `stripe_webhook_secret`) now settle orders of every store, also of another `provider_scope`.
  - `cli/sync.php` syncs every store: products and stock are pulled once into the default store's
    catalog, then each store pushes its orders (with their items) and pulls their statuses. Cancelled
    orders that never reached the ERP are not pushed; a failed push is retried after 15 min, doubling
    up to a day (`orders.erp_attempts`, `erp_next_retry_at`). A run still in progress makes the next
    cron run exit 0 (`<jobs_dir>/sync.lock`); a lock file it cannot open (missing or read-only
    `jobs_dir`) is an error on STDERR with exit code 1. The product watermark is the run's start time, kept in the
    new table `erp_sync_state` instead of the cache (the cached value is read once as a fallback).
  - `cli/gdpr-export.php` exports a customer of any store in that store; `--store=<id>` narrows the lookup.
  - Cart and checkout amounts are formatted in the cart's (product) currency; the storefront runtime
    config adds `currencyDecimals` (from `stores.json` `currencies`), which `storefront.js` uses.
    `cli/seed.php` books the seeded stock on the default warehouse (`warehouse_stock`).

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
  calling the old `api.php` URL, which answers 404. The worker's pending-order sweep asks GoPay, ThePay,
  Comgate and PayU for the status of each pending payment older than `pending_order_expiry_seconds`
  (default 48 h) and books the ones they report paid, so such a payment turns paid at the latest then
  (it used to stay pending forever); check them in the provider portal if you need them sooner.
  There are no legacy routes: `api.php` (any action) and
  `/api/v1/storefront?action=<action>` answer 404. Custom themes that call
  `api.php` must use `window.BareBonesStorefront.apiUrl` + `/<action>`; nginx needs the updated
  `deploy/nginx.conf` (the `/api/(.*)` rewrite is gone). The theme version is now 2.3.0, so pages
  load the new `storefront.js` instead of a cached one.
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
- **"Retry refund" refunded again on every click** once the provider had answered the refund with "pending"
  (PayU always, Stripe and GoPay sometimes): the refund job's own pending row was offered as a new request
  under a fresh idempotency key. An executed refund is no longer offered for retry. Credit note and RMA
  refunds (key `admin-credit-…`) were still offered after a "pending" answer; only refund requests are now.
- **`migrate` stopped with "modified after being applied"** on installs that had applied
  `20261017093000_catalog_stock_erp_retry` before it was made resumable; its released checksum is
  accepted (`Migrator::LEGACY_CHECKSUMS`), and the test suite checks every released migration checksum.
- **A carrier webhook could deliver another carrier's parcel**: an admin shipment whose carrier names a
  different carrier ("GLS") no longer matches the webhook of the order's shipping-method carrier.
- **Refunds without the refund right**: cancelling an order with captured money refunds it, so it now needs
  `orders.refund` in the admin (the `fulfillment` role can no longer do it) and `refunds:write` in the API
  (`orders:write` alone gets 403). Returned and cancelled orders, like paid ones, cannot be set to `refunded`
  while a refundable balance remains.
- The multi-store stock migration (`20261017093000`) finishes on a rerun after failing halfway on MySQL
  (warehouses moved, stock rows not yet).
- **Non-default stores sharing a catalog** (e.g. `/cs/`) were broken end to end: checkout always failed
  with "stock changed" (stock looked up under the store id, products under the catalog), the cart showed
  EUR amounts as CZK, gateway return pages said "order not verified" and webhooks parked (URLs of the
  default store), the worker's full cache rebuild wrote other catalogs' categories and prices into the
  store's cache, the ERP sync only pushed the default store's orders (without items) and a failing order
  blocked its batch, and `cli/gdpr-export.php` could not find other stores' customers
  (`AuditRound4MultistoreRegression`, e2e `multistore-checkout.spec.js`).
- Refunds could be booked twice when the Stripe `charge.refunded` webhook and the refund worker both
  saw them (a full refund showed 2000 of 1000), and concurrent `charge.refunded` events could over- or
  under-book; refunds are now booked once per provider refund id under the order row lock. Credit
  notes no longer double-count a pending refund already on the ledger (`refundableForCredit`), and
  PayU refunds answered `FINALIZED` count as succeeded. GoPay/ThePay refunds (no refund id) were booked
  twice when the `REFUNDED` notification arrived while the worker's refund call was in flight.
- **Storefront UX review (round 4).**
  - Add to cart works without JavaScript: product cards, the home product list and the product page
    render POST forms (`cart_action=add`, quantity, variant, CSRF token, redirect back) to the store URL;
    JS intercepts the form submit, so behaviour with JS is unchanged. A no-JS add, a wishlist toggle or
    removal now shows a one-shot notice in a `role="status"` region of the shell (`StorefrontFlash`).
    A no-JS form post that is refused redirects back with the reason instead of answering 422.
  - Listing cards of products with variants (`requires_variant`, one batched query) link to the product
    page ("Choose options") instead of an add-to-cart button that always failed.
  - Adding more than the stock or more than 99 units reports the quantity the cart really holds
    (`quantity`, `clamped` in the cart response) instead of a plain "Added".
  - Checkout refusals are translated (`checkout.error.*` and existing keys) and the JSON API returns
    `error_code` next to the translated `error`; no Slovak/English strings are hard-coded any more.
  - Order e-mails format line items in the order currency, show a translated order status
    (`order_status.*`; `OrderStatusLabels` for reuse) and take carrier, tracking number and tracking
    link of the latest shipped shipment (admin shipments), so "order shipped" mails carry them.
  - All e-mail templates exist in Spanish.
  - The order confirmation page's payment summary is translated, including the payment status
    (`payment_status.*`); a free payment method shows "Free", not "Free shipping".
  - Category and search totals use plural forms ("1 produkt", "2 výsledky pre"); product attribute
    labels are translated; shipping options show "Delivery in 2 days" (nothing for 0/unknown) instead of
    a bare number (`delivery_label` in the shipping_rates API).
  - A failed shipping-rate refresh keeps the rendered options and adds an alert instead of replacing them.
  - Sort selects no longer submit on every arrow key (WCAG 3.2.2): a visible sort button, Enter, or a
    pointer choice submits; cart quantities submit once after a short pause.
  - `checkout_consents.*.text` may be a `{locale: text}` map; a relative consent `url` gets the store
    prefix (`/cs/?page=terms`).
  - `q[]=x` and other array query values (`cart_recover`, `cart_unsubscribe`, `lang`, `token`, `msg`,
    the redirect fallback `path`) no longer raise "Array to string conversion".
  - Managed menu links get the store prefix (a link already entered with it, `/cs/page/x`, keeps a
    single one instead of `/cs/cs/page/x`); navigation, footer, breadcrumb, pagination and cart links
    use the canonical `/category/{slug}` and `/product/{slug}` paths.
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
  (pricing.json coupons check usage limits before the minimum order now, as admin coupons did). The
  HTML checkout's coupon field spends the same budget (each code the session has not had accepted) and,
  like order placement, refuses with `coupon_invalid` or `coupon_min_subtotal` only; the lang keys
  `coupon_used_up` and `coupon_already_used` are gone (themes overriding them can drop them), the new
  `coupon_too_many_attempts` is shown when throttled.
- **Customer account (UX audit round 4).** Withdrawing cookie consent in the account works: the
  choice is written as `cc_v1` (the banner's cookie, name, JSON value and path), logged in
  `cookie_consent` like the banner's API action, a legacy `cookie_consent` cookie is cleared, and the
  privacy page reads the same cookie as the banner and `MarketingConsent`. New addresses are validated
  (name, street, city, ZIP required; country from the checkout's delivery countries, now a select)
  and re-rendered with field errors and the typed values instead of "Saved"; address types are
  translated. Every account form label is linked to its control, with `autocomplete` hints. Order
  list/detail show translated statuses (`order_status.*`, `payment_status.*`) and dates in the shop's
  display timezone and the locale's `format.date`/`format.datetime` (`I18n::dateTime()`). New
  `?p=downloads` page lists the customer's digital entitlements (expiry, downloads left); its button
  issues a fresh link (`DigitalDelivery::reissueForCustomer()`: only token hashes are stored, so the
  e-mailed link stops working, limits and expiry are unchanged); it follows the download rules, so after
  a partial refund the uncredited lines stay available. Unknown `?p=` values are a real 404.
  The profile offers only the current store's languages; a too-fast registration (form trap) says
  "please try again" instead of "too many attempts".
- **Admin.** Rejected product and CMS page saves keep the typed input (one-shot, `AdminForm::keepInput()`);
  a rejected new product returns to the prefilled new-product form, not `id=0`. The customers list is
  paginated (50 per page) and its heading shows the real total. Product bulk actions flash a message
  when nothing is selected, no action is chosen or the action fails, and return to the filtered list
  (search, status, stock, page). Order bulk status starts with an empty required choice and asks for
  confirmation; a post without a status changes nothing.
- **Storefront SEO.** A category page past the last one (`/category/x?pg=999`) is a 404 with `noindex`
  instead of an indexable empty page. `/category/x/` 301s to `/category/x` (the clean-URL rewrite
  captured the slash in the default store). Product JSON-LD maps backorder/preorder stock to
  `BackOrder`/`PreOrder` instead of `InStock`. A store's further `languages` (selected with `?lang=xx`)
  get their own canonical (`…?lang=xx`), an hreflang entry and a language-switcher entry when no store
  has that language as its default (that store stays the language's one hreflang URL). Internal links
  do not carry `?lang=` yet, so browsing on from such a page returns to the store's default language.
  Legal pages (`?page=privacy|terms|cookies`) have a canonical and hreflang alternates.

### Migration
- `20261008090000_stock_alerts` (idempotent): `products.stock_low_threshold`,
  `products.stock_critical_threshold`, tables `stock_alert_state`, `stock_alert_digests`.
