# Changelog

## Unreleased

- **Admin order acceptance fixes** (review round 17, medium).
  - Recording a payment as Paid by hand (for example after a failed card payment, or a bank transfer) moves a pending
    order to Paid, as a gateway capture does; the order used to stay Pending. Delivered cash-on-delivery orders stay
    Delivered; money recorded on a cancelled order still goes to a manual refund.
  - An e-book whose line was refunded through a return shows "Blocked" in the order detail instead of "Active"; the
    download itself was already refused.
  - A credit note has a variable symbol of its own: "CN-20260007" no longer carries the digits of invoice 20260007.
- **Line endings in the release tree** (review round 17, high). The seven `templates/emails/*/order_shipped.html`
  were stored with CRLF and a stray carriage return, so `php cli/build-update.php` refused the tree and no release
  could be built from `main`. They are stored with LF; `.gitattributes` keeps all text LF on every checkout
  (`* text=auto eol=lf`), the update builder also refuses a lone CR, and CI builds an update package from the tree.
- **Low findings** (review round 16).
  - The admin product form takes the price and the original price in the product's currency ("12.90"), not in
    cents; an amount finer than the currency shows is refused.
  - A homepage hero without an image is a text block instead of an empty grey 16:5 box.
  - The delivery note names the country ("Slovensko") and the shipping method as chosen at checkout, not their codes.
  - `php cli/setup.php doctor` exits 1 until the shop is ready and `php cli/staging-preflight.php` exits 1 on NO-GO,
    so a deploy script stops; `--strict` is still accepted. `install` and `apply` still exit 0 unless `--strict`.
  - A feed variant link in a store's second language is `?lang=xx&variant=N` (was `?lang=xx?variant=N`).
  - An update running longer than an hour (a big migration) keeps the shop in maintenance while its process still
    runs on that host, for at most a day; a marker left by a killed updater still stops blocking after an hour.
  - Tests now cover the content of `<style>`, `<script>` and the other elements rich text removes, and a `<style>`
    in the body of a mail's HTML (mutants SEC12 and MAIL2).
- **Theme texts, unregistered sections, theme-check on an installed shop** (review round 16, medium). A theme had
  nowhere to keep its own texts but the core's `lang/` files, which an update replaces: it now ships
  `themes/<name>/lang/<locale>.json` with keys under its own name (`{{t.acme.tagline}}`), falling back to `en.json`;
  it cannot change the core's texts. A homepage section whose type no `theme.json` registers rendered nothing without
  a trace; it leaves `<!-- unregistered section: <type> -->` in the page source and `php cli/theme-check.php` lists
  it as a warning. After `setup.php install`, theme-check checked the stores of `stores.example.json` instead of the
  installed `stores.json` in the data directory; it reads the installed one and names the file it used.
  `docs/THEMES.md`, `docs/EXTENSIONS.md` and `CONTRIBUTING.md` describe texts, section registration and the check.
- **Back in stock, logs, transfer reminders, shipped e-mail** (review round 16, medium). Asking again on the "notify
  me" form mailed a new confirmation link every time, so anyone could flood an address with links: a waiting request
  now gets a new link at most once an hour, and one address at most three links an hour over all products (a fourth
  request answers "Too many requests"). The logs now mask payment card numbers (Luhn-checked, with spaces or dashes,
  also at the end of a sentence and next to the CVC, the expiry date or another number), card security codes (`cvc`, `cvv2`, `security_code`), secrets inside JSON nested in a JSON string (escaped quotes)
  and `Cookie` / `Set-Cookie` values and session ids. The bank-transfer reminder sweep read the 500 oldest pending
  orders without a reminder; cash-on-delivery orders and overdue transfers never get one, so once 500 of them piled up
  no transfer was reminded again. It now reads only orders inside the payment term, page by page. "Create and ship"
  without a carrier mailed "Order X was handed to ."; the shipped e-mail now says the order has been shipped and names
  the carrier only when there is one (`without_carrier` in `order_shipped`; an edited copy of that template keeps its
  own text).
- **Shops installed with `install.php` can be updated** (review round 16, high 2). The web installer left
  `update_public_key` empty, so the signed updater refused every package ("Signed update package requires an Ed25519
  public key") and the bookshop package could not be upgraded as the README says. The installer now copies the
  shipped `releases/update-public.key` to `update-public.key` in the private data folder (outside the web space,
  0640) and points `update_public_key` at it; `php cli/update.php fingerprint` prints the release key's fingerprint
  right after the install. A shop installed from the v2.1.2 bookshop package does this once by hand (see the release
  notes of `bookshop-demo-v2.1.2`). `demo/bookshop/package.php` records the commit, not the tag object, in
  `starter.json` when built from a tag.

## 2.1.2 — 2026-11-05

**Correctness hotfix of 2.1.1** (review round 16, high 1 and 3, and the medium findings fixed since). Update every
2.1.1 shop: after an update rollback on MySQL or MariaDB invoice and credit note numbers were issued a second time,
orders of a `shipping.json` method could get no label, one anonymous request could disable an extension (payment
provider included), and a refund after a cancellation credit note failed for ever.

Signed with the same update key as 2.1.0 and 2.1.1, whose fingerprint (`php cli/update.php fingerprint` on a shop,
`php cli/build-update.php fingerprint --public=releases/update-public.key` in the 2.1.2 tree) is

    ae998f94dde4c8aa8651dd644cfa309880df155a6d0fa4bf695eca75279b576a

The tag `v2.1.2` is signed with the SSH key `SHA256:+6NmgAQLsyy8akzFUqhZuzHqgHZ7cR/Yrz1VbRpyEik` (as `v2.1.1`, listed
in `releases/allowed_signers`): `git -c gpg.ssh.allowedSignersFile=releases/allowed_signers verify-tag v2.1.2`, with
an `allowed_signers` copy you already trust. The GitHub release `v2.1.2` carries `update-2.1.1-2.1.2` and
`update-2.1.0-2.1.2` (installed 2.1.1 or 2.1.0: `php cli/update.php apply --package=...`), `update-2.0.0-2.1.2` and
`update-1.8.0-2.1.2` (run from the trusted 2.1.2 tree with `--installed=<shop root>`, see
`docs/AI_UPDATE_RUNBOOK.md`).

### Upgrade notes (2.1.1 → 2.1.2)

Run `php cli/update.php doctor --package=...` before `apply`, and `php cli/setup.php doctor` after it.

- **Rolling this update back:** the snapshot is taken by the shop's own 2.1.1 (or 2.1.0) updater, which does not
  record customers, so `php cli/update.php rollback` of this update always reports `customers=unknown` and a critical
  `update.rollback_discarded_writes` incident, even when nobody registered. Before rolling back, list the customers
  registered since the update (`created_at` after the snapshot time in `php cli/update.php list`) to re-create them
  afterwards; a SQLite rollback keeps no copy of the replaced database (MySQL/MariaDB with
  `update_mysql_probe_database` keep a `pre-rollback-*.sql` dump in the snapshot directory). With none, close the
  incident.

- **Migration** `20261104120000_back_in_stock_double_opt_in`: "Notify me when back in stock" is double opt-in. Requests
  stored before the update were never confirmed; they lapse 48 hours after the update unless confirmed, so a
  customer who wants the notice signs up again.
- **ERP API credentials:** an order's phone, delivery address and note are returned only to a credential with
  `customers:read` as well as `orders:read`. Grant `customers:read` to an ERP that books or ships orders from them.
  Resume incremental syncs at `meta.next_updated_after` (not `meta.server_time`), and expect stock changes to move a
  product's `updated_at`, so the first sync after the update may return more products than usual. Order lines carry
  `discount` and the VAT of the order's financial snapshot; an ERP that summed the old line VAT should compare again.
- **Doctor warnings to act on:** the nginx site config (`operations.nginx_config`) missing deny rules of the shipped
  `deploy/nginx.conf` (copy the named rules, e.g. `*.lock` and `*.tmp`, into your server config); carrier webhook
  secrets set while `shipping_webhook_require_timestamp` is not (it defaults to `true`: set it to `false` for a
  carrier that signs only the body, whose webhooks are refused otherwise, or to `true` to silence the warning); `MAIL_FROM` unset or on an example domain (warning)
  or not a valid address (failure: no e-mail is sent).
- **Product feeds:** a product with variants is listed as one item per variant (own SKU, price, availability, grouped
  by `item_group_id`) instead of one item: Google Merchant Center and Meta see the variants as new items, and the
  product's own item as removed, on the next fetch.
- **Admin amounts:** `1,299` is read as one thousand two hundred ninety-nine (it was 1.30), and an amount with more
  decimals than the currency has is refused instead of rounded.
- **Extensions:** an extension page that throws answers 500 and logs `extension.page_failed`; it is no longer
  quarantined. An extension quarantined by such a request under 2.1.1 is released once one of its PHP files changes
  (the doctor lists `extension.quarantined`).
- **Refunds by hand:** a payment returned by bank transfer or cash on delivery offers only "Record manual refund". A
  payment left at "Refund in progress" by "Request refund" shows the amount to pay back; pay it and record it.
- **Backups:** `cli/restore.php` also takes an absolute path to a file in a local backup storage.

### Changes

- **Tests on an installed shop, doctor, restore** (review round 16, medium). After `php cli/setup.php install` the test
  suite stopped midway: it read the installed shop's stores.json and legal texts from `private_runtime_dir` (the
  sample stores are hidden there, so a storefront test answered 404 and ended the run). The suite now runs on its own
  copy of `stores.example.json` in a temporary data directory and never reads or writes the installed shop's data.
  `php cli/setup.php doctor`, `php cli/update.php doctor` and `cli/staging-preflight.php` warn when the nginx site
  config (`operations.nginx_config`) lacks deny rules of the shipped `deploy/nginx.conf` (for example `*.lock` and
  `*.tmp`) and name them, and when carrier webhook secrets are set but `shipping_webhook_require_timestamp` is not
  (it defaults to true, so a carrier that signs only the body has its webhooks refused). `cli/restore.php` takes the
  backup path as `--list` prints it, relative to the backup storage, and now also an absolute path to a file in a
  local backup storage; any other path is refused with what it takes (was: "path must be relative").
- **Rollback numbering on MySQL/MariaDB** (review round 16, high). After an update rollback on MySQL or MariaDB the
  invoice and credit note counters were not raised past the numbers issued during the update, so those numbers were
  issued a second time: the counter update bound one named placeholder twice, which native prepares reject (HY093;
  SQLite accepts it). A regression test now scans every SQL string in `src/` and `extensions/` for a repeated named
  placeholder. A rollback also counts the customers registered after the snapshot, and for a snapshot taken by an
  older updater it reports the tables that updater never tracked (v2.1.1: customers; v2.1.0 or older: invoices,
  refunds and customers) as an unknown number of discarded rows instead of skipping them silently. When the counters
  still cannot be raised, the rollback notice no longer also claims the discarded invoice numbers are not issued again.
- **Refunds after a cancellation credit note, refunds by hand** (review round 16). A cancelled purchase on invoice
  that was paid all the same had its invoice fully credited by the cancellation credit note; refunding that money
  then failed for ever ("the refund exceeds what the invoice still has uncredited"), and its credit-note job raised a
  critical incident. The refund now credits only what the invoice still has left (nothing, when the cancellation
  credited all of it). "Request refund" on a payment returned by hand (bank transfer, cash on delivery) set the
  payment to "Refund in progress" with nothing to execute it; such an order now offers only "Record manual refund",
  and a request left from before shows the amount to pay back and record.
- **Extension quarantine only for real fatal errors** (review round 16). One anonymous request could disable an
  extension, payment provider included, until a developer changed its files: a page throwing on bad input ended the
  request as "Uncaught ..." and the fatal-error guard quarantined the extension. An uncaught exception no longer
  quarantines, and a throwing extension page answers HTTP 500 with a neutral "temporarily unavailable" page and
  logs `extension.page_failed`. Errors PHP cannot catch (a class missing interface methods, a class declared twice)
  still quarantine as before.
- **Labels for shipping.json orders** (review round 16, high). An order shipped by a `shipping.json` method
  ("Standard Delivery", "Express Delivery") showed "standard: carrier API not connected" and no label form, although
  Packeta and GLS were connected: the method's code was taken as its carrier. Such a method names a delivery speed,
  not a carrier, so its orders are offered every connected label carrier except in-store pickup.
- **Low findings** (review round 15). An admin amount typed as `1,299` was read as 1,30 EUR (the comma taken as the
  decimal separator, the rest rounded away); an amount with more decimals than the currency has is now refused with
  a message instead of being rounded. A checkout form that failed validation or was reloaded kept nothing: the
  contact and address last posted stay in the session and fill the form again (only from a post that passed the
  CSRF check), and are dropped when the order is placed and on logout. Withdrawal and complaint confirmations (page and e-mail) gave the receipt time in UTC; they
  show it on the shop's clock with its zone, and the 30-day complaint decision deadline counts from the shop's local
  receipt day. A mutation sweep of MailService, Security, Warehouse, PaymentService and InvoiceService left survivors
  the suite did not catch; new regression tests kill every one that can change behaviour (rate-limited mail retry,
  form-action and role checks, stock reservation races and ledger signs, refund requests and bookings, credit caps
  per VAT rate, the VAT breakdown and payment terms). A test of refund-period dates no longer flakes when the clock
  crosses a second.
- **Reports, feed and translations** (review round 15). Top products list each variant on its own row, and a refunded
  item comes off the line it refunded instead of being spread over the whole order; only the rest of a refund (an
  amount or shipping refund) is spread over what is left. The Google Merchant and Meta feeds list one item per
  variant (own SKU, price, availability, `item_group_id`, `color`/`size`) with a link that opens the product page on
  that variant (`?variant=`), and a reduced product carries `price` plus `sale_price`. The B2B method seeded as
  "Invoice purchase" now reads in the invoice's and e-mail's language, also on existing orders; the `payment_method`
  e-mail variable is the method's name, not its code; the admin parcel list and label block name the carrier
  ("Packeta") instead of its code. `php cli/update.php doctor` and `php cli/setup.php doctor` warn while `MAIL_FROM`
  is unset (mail leaves from `noreply@localhost.invalid`) or on an example/reserved domain, and fail when it is not
  a valid address (no e-mail is sent).
- **Content, e-mails and GDPR** (review round 15). The e-mail template guard no longer lets hidden text through
  `color:white`, a text colour equal to (or too close to) its background, or `<font color>`: it compares the
  computed text and background colours. A legal page's "last updated" date follows the merchant's own edit of that
  text instead of staying at the shipped date. Anonymizing a guest in the admin GDPR screen now shows that the
  address was erased and what retention keeps (name, address and e-mail on N orders, for accounting) instead of the
  same row and button again; a later order from the address brings the button back as new data. "Notify me when
  back in stock" is double opt-in: the address gets a confirmation link (valid 48 hours, confirmed by a posted
  button, so mail scanners following the link confirm nothing) and only confirmed requests are mailed. Requests
  stored before the upgrade were never confirmed and lapse after 48 hours (migration
  `20261104120000_back_in_stock_double_opt_in`).
- **Extensions and developer documentation** (review round 15). A broken extension class (for example a provider
  missing an interface method) ended every request with a fatal error, although the documentation promises that a
  broken extension is skipped. Such an extension is now quarantined after the request that hit the error and skipped
  until one of its PHP files changes (`extension.quarantined`, shown by the doctor). `docs/EXTENSIONS.md` documents
  the payment, shipping and tracking adapter contracts (when `getRates()` is called, which events and statuses the
  core acts on); a carrier with its own notification format implements `TrackingWebhookAdapter` instead of being
  bypassed. New `docs/THEMES.md` (theme authoring) and `CONTRIBUTING.md`. The README test steps work on a fresh
  clone (`--recurse-submodules`, `config.php`), and `cli/test.php` names what is missing instead of aborting
  midway. `package-lock.json` is accepted by `npm ci` of npm 10 as well as 11. The file-queue worker takes its lock
  in the jobs directory the queue really uses: two suites run side by side (two checkouts sharing the default
  `jobs_dir`) no longer make a test's worker back off and drop out of the run.
- **ERP API sync, line VAT and personal data** (review round 16, medium). A sale, shipment or stock adjustment left
  the product's `updated_at` as it was, so `updated_after` never returned a stock change; it now moves `updated_at`
  of the product (and of the product of a variant). Resuming at `meta.server_time` lost a change committed by a
  transaction that began earlier: lists now return `meta.next_updated_after` (server_time less five minutes) to pass
  as the next run's `updated_after`. Under an order discount the lines' `vat_amount` was that of the undiscounted
  line and did not add up to the order's or the invoice's VAT (248 of 658 orders in the review); a line now carries
  its `discount` share and the VAT of the order's financial snapshot. `docs/openapi.yaml` misparsed: four unquoted
  descriptions with a comma inside a flow mapping became stray keys; they are quoted, and CI now lints the file with
  a strict YAML parser (`npm run lint:openapi`). Upgrade note: the order's phone, delivery address and note are
  returned only to a credential with `customers:read` as well as `orders:read`; grant it to an ERP that needs them.
- **ERP API** (review round 15, medium). An ERP could neither create a product, nor sync incrementally, nor book or
  ship an order from the API alone. `POST /api/v1/products` (scope `products:write`, `Idempotency-Key`) creates a
  simple product the way the CSV import does: unique slug, price history, stock booked in the default warehouse
  ledger, `product.updated` to subscribers; an existing SKU is a 409 `sku_exists` and is never overwritten. The
  product, order and customer lists take `updated_after` (UTC; `meta.server_time` of the first page is the next run's
  value) and refuse it on lists without `updated_at`. Orders in the API carry their lines (SKU, name, quantity, unit
  price, total, VAT), the delivery address with company IDs, phone, locale, shipping method and pickup point, payment
  method and the customer's note; webhook payloads are unchanged. Attaching guest orders to a verified account and
  erasing a customer now bump the orders' `updated_at`, since both change what the API shows. `docs/openapi.yaml`
  documents all of it.

## 2.1.1 — 2026-11-04

**Security and correctness hotfix of 2.1.0** (review round 15, high 1–8). Update every 2.1.0 shop: among others a
manual order no longer exposes a buyer's data to an unverified account with their e-mail, and the web worker no
longer runs jobs inside a failed request's transaction.

Signed with the same update key as 2.1.0, whose fingerprint (`php cli/build-update.php fingerprint
--public=releases/update-public.key` in the 2.1.1 tree, and `php cli/update.php fingerprint` on a shop from 2.1.1
on; 2.1.0 has no such command) is

    ae998f94dde4c8aa8651dd644cfa309880df155a6d0fa4bf695eca75279b576a

The tag `v2.1.1` is the first signed tag (SSH key `SHA256:+6NmgAQLsyy8akzFUqhZuzHqgHZ7cR/Yrz1VbRpyEik`, listed in
`releases/allowed_signers`): `git -c gpg.ssh.allowedSignersFile=releases/allowed_signers verify-tag v2.1.1`.
The GitHub release `v2.1.1` carries `update-2.1.0-2.1.1` (installed 2.1.0: `php cli/update.php apply
--package=...`), `update-2.0.0-2.1.1` and `update-1.8.0-2.1.1` (run from the trusted 2.1.1 tree with
`--installed=<shop root>`, see `docs/AI_UPDATE_RUNBOOK.md`; a v1.8.0 shop now upgrades its `stores.json`).

### Upgrade notes (2.1.0 → 2.1.1, added with 2.1.2)

- **Maintenance window:** `update.php apply` and `rollback` (and from 2.1.1 on `php cli/migrate.php apply`) put the
  shop into maintenance: web requests get 503 with `Retry-After`, `worker.php` and `feed-sync.php` skip their run.
  The `order_items.digital` backfill runs in ranges of 5,000 lines; on a large order history plan minutes, not
  seconds.
- **Migrations:** `20261102090000_variant_digital` (download variants), `20261103090000_order_item_digital` (digital
  order lines frozen as they were sold, existing lines backfilled) and `20261103150000_fulfillment_status_grant` (a
  fulfillment account whose store grant lists permissions explicitly gets `orders.status`, which order status moves
  of that role need since 2.1.1).
- **Cron:** the CLI install leaves the web worker off. Without the README's cron entries (worker, GDPR retention,
  backup) order e-mails stay queued; add them if the shop has none.
- **Signing:** `update.php rotate-key` no longer takes `--public-key`, and an endorsement printed by
  `build-update.php endorse` of 2.1.0 has to be made again. Verify release tags from v2.1.1 on with
  `git -c gpg.ssh.allowedSignersFile=releases/allowed_signers verify-tag`.
- **Custom themes:** account links appending `?p=` to `{{account.action_url}}` lose a further language; use
  `{{account.page_urls.<page>}}`. Include `{{> global/lang-field}}` in the theme's own GET forms.
- **Prices:** `pricing.json` catalog rules and customer group discounts are not applied to any price (they never
  were: nothing read the price maps the cache rebuild wrote for them); `doctor` reports them as `pricing.legacy_rules`. Recreate them as promotions or price lists.
- **Payments:** bank transfer is offered only when the store has an IBAN with valid check digits (Admin → Stores
  and seller, which also takes the BIC). A manual order joins only an account with a verified e-mail address.
- **Retention:** `gdpr_events` are deleted 5 years after the event (`config/data-retention.json`).

### Changes

- **Release process and upgrade** (review round 15, high 7). A v1.8.0 shop adopted exactly per the runbook answered
  503 on `/readyz` and the admin: its `stores.json` (main store keyed `sk`, no `default_store`, no channels) stayed as
  it was, while every migrated row belongs to the store `default`. `update.php apply` and `setup.php apply` now write
  an upgraded copy into `private_runtime_dir` (main store → `default`, the old file stays for a rollback), and both
  doctors report a v1.x file. The 2.1.0 notes gave the SHA-256 of the key file instead of what
  `php cli/update.php fingerprint` prints; they now give that value, which `php cli/build-update.php fingerprint`
  prints for release notes. The release checklist signs (`git tag -s`) and verifies tags, and the bootstrap runbook
  verifies the tag before exporting it. An update rollback now also counts the invoices, credit notes and refunds it
  discards, and raises the restored invoice/credit note counters back past the numbers already issued, so no number
  is issued twice. The `order_item_digital` backfill runs in id ranges of 5,000 lines instead of one UPDATE of the
  whole table (156 s and deadlocks at 4 million lines), and `php cli/migrate.php apply` puts the shop into
  maintenance (503) while migrations run, as `update.php` does.
- **Bookshop package modes and upgrades** (review round 15). The package's zip entries carried no Unix modes, so
  many hosts unpacked the whole shop as 0777 (writable by anyone on a shared host). Folders are now 0755 and files
  0644, including the top folder, and the acceptance run refuses a package with group- or world-writable entries.
  A package built from a release tag records the release in its `starter.json` (`--version=` overrides) and
  `install.php` baselines the signed updater with it, so the shop upgrades with `php cli/update.php apply` like any
  other installation (README: Upgrading).
- **Web worker after a failed request** (review round 15, high 1). The page-request worker shares the request's
  database connection. When the request died inside a transaction, every job of the pass ran inside it, looked
  completed and was rolled back with the connection at exit: a credit note and its job vanished, a database or Redis
  queue ran the job again, and SQLite stayed write-locked for the whole pass (up to 300 s). The pass now rolls that
  transaction back first (`web_worker.request_transaction_rolled_back`), or skips and leaves the slot free when it
  cannot. `cli/worker.php --once` now handles every ready job (within its 55 s budget), not only the first five.
- **Credit notes per VAT rate and on cancellation** (review round 15, high 3 and 4). After a refund credit note (a
  share of every VAT rate) the item credit note of a returned product credited its full price at its rate again:
  negative VAT at that rate. Item credit notes are now capped, per VAT rate, at what the invoice still has uncredited
  there. The issued invoice of an unpaid order closed without its money (a purchase on invoice cancelled, a refused
  cash-on-delivery parcel returned) stayed issued and due and in the VAT and OSS reports; such an order now gets a
  cancellation credit note (job `closure_credit_note`): the whole invoice when nothing was delivered, after a partial
  shipment the goods that never shipped.

- **Manual orders and unverified accounts** (review round 15, high 2). An order entered in the admin joined any
  active account with the buyer's e-mail, verified or not: signing up with someone else's address showed their
  address, phone, invoices and downloads. Only an account with a verified address gets the order now; otherwise it
  stays a guest order and joins the account when its holder verifies the address (signed in to it).

- **Payments** (review round 15). The expiry sweep no longer cancels an order whose "pay again" opened a new gateway
  session after the sweep asked about the earlier attempts (or is opening one right now): the customer could pay the
  cancelled order. The payment webhook route takes the provider codes adapters accept (`/api/v1/webhooks/payment/acme-pay`
  was a 404). At a provider whose checkout session id is not its payment id, the capture of the order's own session now
  records the payment id, so refunds go to the payment instead of the session.
- **Fulfillment** (review round 15, high 5, high 6). "Create and ship" and "Ship" in the admin order detail never
  moved the order to Shipped nor e-mailed the customer. The parcel with the last unshipped goods now does both (the
  `order_shipped` e-mail carries that parcel's tracking); an earlier partial parcel e-mails its own tracking and the
  order stays paid. An e-book credited through a return (RMA) stayed downloadable when the order kept the payment
  status `paid` (refund without a provider payment): the credited line no longer resolves nor counts a download.
  Labels are offered only for the carrier the customer chose: a Packeta cash-on-delivery order was offered just the
  in-store pickup label (the only connected carrier); without the Packeta API the parcel is booked in its portal.
  The order status form no longer preselects the first allowed move (for some orders Cancelled): it starts empty.

- **Further language in links with their own query** (review round 14, UX2 follow-up). With a further language active,
  the password-reset and e-mail verification links, the account page's links (login, register, forgot password,
  orders, addresses), the specification-value search links and the category trailing-slash redirect got a second
  `?` after `?lang=` (`/account.php?lang=es?p=reset&token=…`): the reset form never opened and the language was
  lost. Their query now goes into `Template::storeUrl()`; account templates use `{{account.page_urls.<page>}}`.
  Custom themes appending `?p=` to `{{account.action_url}}` should switch to those.

- **Usability** (review round 14, UX1–UX8). Storefront links keep a store's further language (`?lang=en` in a
  Slovak store): the language was lost after the first click, and the English product link answered with a 301 to
  the Slovak slug. The search, sort and filter forms submit the language too (`{{> global/lang-field}}`, which
  themes can include in their own GET forms). Canonicals and hreflang alternates still carry exactly one language. The README quick start runs
  the worker and lists the cron entries (worker, GDPR retention, backup); the CLI install leaves the web worker off,
  so without them order e-mails stayed queued. The admin names payment methods ("Cash on delivery") instead of their
  codes. Every signed-in admin, editor and fulfillment included, changes its own password and authenticator app
  under Admin → My account (current password required); before, only admins.manage could. A checkout without a
  payment method says why (the chosen delivery has none, or names the deliveries that have one, or gives the store's
  e-mail), and Admin → Payments warns that bank transfer is hidden while the store has no valid IBAN. Status lists offer only moves that would be accepted:
  the order detail drops "paid" until the payment is confirmed and "refunded" while money is still to go back, and
  the bulk list offers "refunded" only to admins who may refund. The product editor uploads up to 10 images at once
  (added at the end, nothing kept when one file is not an image) and lists the images with move up / move down /
  remove buttons over the "URL | alt" lines, which still work without JavaScript.
- **Times, dates and log masking** (review round 14, T12, T13, T15, T21, UX6). The admin shows stored times in the
  shop's time zone and the admin language's format (it printed UTC as "2026-07-01 22:30"); invoice dates, the
  bank-transfer due day (page and e-mail, in the order's language) and the legal texts' "last changed" use the
  language's date format. The API keeps ISO dates (`bank_transfer.due_date`; `due_date_display` is new). Feed
  schedules with a wildcard minute or hour (`0 * * * *`, `*/15 * * * *`) follow the wall clock through clock changes
  as cron does — they no longer skip the repeated autumn hour, and only fixed-time schedules catch up a time the
  spring change skips. The logger masks HTTP Basic credentials without digits or shorter than 8 characters, any
  `Authorization:` header value, URL passwords containing "@", and quoted or `password=` values containing spaces.
  `AuditRound7StockRegression` counts its 15 days on the shop's calendar (it failed at 23:xx for two weeks after a
  clock change).
- **Shipping and the withdrawal period** (review round 14, T4, F3, carrier labels, feed). Cancelling a parcel in the
  admin also cancels its labels at the carrier (they stayed active there, so the parcel was still picked up and its
  cash on delivery collected); a label the carrier cannot cancel stays active, is reported and the admin is told to
  cancel it with the carrier. The carrier's idempotency key names the parcel, so two parcels of one order labelled at
  the same time get two labels (they were one carrier request with one AWB). A cancelled parcel gets no label, and a
  label the carrier creates while its parcel is being cancelled is cancelled at the carrier instead of being recorded
  on the cancelled parcel. The withdrawal period of a bundle starts once every component is in a delivered parcel,
  counted as bundle quantity × units per bundle (the component units were summed against the bundle quantity). The
  marketing feed lists a bundle with an e-book component as out of stock, as the shop does not sell it.
- **Payments** (review round 14, finding 3, T2, T18, T20). A capture of an older payment attempt records that
  attempt as the order's payment (refunds go to the money), and pending-order expiry asks the gateway about every
  attempt, not only the newest. A hidden store opens its payment link and the pages its gateway returns to. The
  Stripe completion checks for a second capture and records the payment under the order lock. The return page shows
  an order whose older attempt is paid as being confirmed, not failed. PayU notifications signed with SHA-256 are
  accepted. The page after paying through a link no longer shows the customer's name and delivery address.
- **Credit notes and refunds** (review round 14, finding 2 and T18). A refund credit note takes its VAT split from
  what the invoice's earlier credit notes left uncredited, so after an item credit note no rate is credited
  beyond what was invoiced, and all credit notes of an invoice together never exceed it. The refund key and the
  uncredited balance are checked under the order lock (two calls for one manual refund issued two credit notes).
  A provider refund booked by its webhook while an item refund of the same amount still has no provider refund id
  waits for the refund job (up to 30 minutes, then fails visibly) instead of getting a second credit note. An item
  refund the provider declines, or whose pending refund fails later, is marked failed and its credit note
  cancelled, so the returned units and the money can be refunded again (they stayed "being refunded" for ever).
  The invoice of an unpaid cash on delivery order no longer prints the seller's IBAN.
- **Updater and installation** (review round 14, findings 4–6, T20, installer self-check). `update.php rotate-key`
  no longer takes `--public-key`: the endorsement is checked against the configured `update_public_key` only (a
  key given on the command line could endorse itself while the configured key stayed trusted and the wrong key was
  revoked). The signed rotation statement (v2) names the fingerprint of the key it replaces; an endorsement printed
  by `build-update.php endorse` of 2.1.0 has to be made again. A rollback run as root no longer chmods or chowns the
  staged database or state file by path in a directory the web user can write: the copy is created exclusively with
  its mode and handed over with `lchown`/`lchgrp` after an inode check. `setup.php install` with a new `config.php`
  over an existing database no longer switches off the live shop's online payment methods (it decides from the
  database as it was before migrating). The web installer skips its self-check only under `php -S` started with
  `router.php`. `update.php apply` and `rollback` put the shop into maintenance (`.maintenance` in the application
  root): web requests get 503 with `Retry-After`, `worker.php` and `feed-sync.php` skip their run, a marker older
  than an hour (a killed updater) stops blocking. `gdpr_events` are deleted 5 years after the event (new policy in
  `config/data-retention.json`; the "separate policy" it promised did not exist).
- **Usability fixes** (review round 13, UX1–UX7, README). Checkout offers bank transfer only when the store
  has an IBAN with valid check digits (the shipped sample IBAN has none, so a customer got an order without
  payment instructions). A fresh `setup.php install` keeps every sample store, including `cz`, hidden until the
  merchant enters its seller, and switches off online payment methods whose gateway has no keys (the sample
  card method without Stripe keys), so `doctor` fails only on the default store's example seller. The
  thank-you page shows the order summary (items, totals, shipping and address) the account's order detail
  shows. An e-mail verified after registration attaches that customer's earlier guest orders. The home page
  heading falls back to the shop name instead of a fixed "Sauna & Wooden Products". Admin → Stores and seller
  has a BIC field (validated, printed with the transfer instructions). The README states the install outcome,
  the Docker `--admin-email`, the private-directory default, the IBAN rule and the API action count correctly.
- **Low findings of review round 13 and clock-dependent tests** (T21). A listing of more than 500 products with
  variants no longer reads every variant of the later products as a download (`variantStatesMany` overwrote its
  column expression). A digital product without its file is not sold, like a download variant, and `doctor`
  counts it; a bundle with an e-book component is not sold either (its download was never delivered while its
  "stock" was shipped) and `doctor` warns (`catalog.digital_bundles`). Saving variants before `migrate` no longer
  fails on the missing `variants.digital`, and the unreleased `order_items.digital` backfill reads the snapshot keys
  from the decoded attributes instead of a `LIKE` on the JSON. The cache rebuild no longer writes the per-group
  price maps (`cache/prices/*.json`) nothing read: `pricing.json` catalog rules and customer group discounts are
  not applied to any price, `doctor` now says so (`pricing.legacy_rules`) and the sample `pricing.json` ships none;
  catalog discounts are promotions and price lists. A transfer due in whole days counts calendar days, so an order
  placed just after local midnight before the clocks go back is no longer due a day early. The bookshop theme's
  texts are translated (`bookshop.*` keys, all seven languages). Tests no longer depend on the hour they run.
- **Admin fixes** (review round 13, T10, T17, UX8). A store can no longer be added under a path the shop
  routes itself (`/api`, `/product`, `/category`, `/page`, `/feeds`, `/assets`, an application directory, …):
  a `/api` store answered the whole JSON API in its own context. Such a prefix, hand-edited into
  `stores.json`, is never routed and `doctor` fails on it. The legal-text editor passes the text's language as
  `text_lang` (`lang` switched the admin's own language). Re-entering the current password for owner or own
  credentials allows five wrong passwords per account in 15 minutes, then signs that session out. A store
  with invoices, customers, refunds, payments, withdrawals, complaints, returns, GDPR or back-in-stock records,
  or whose catalogue or payment and shipping methods another store uses, cannot be removed; removing a store
  drops its admin grants. Every admin created in the web admin gets TOTP (`cli/admin-user.php create --no-mfa`
  still can create a password-only account). The `fulfillment` role moves orders along the workflow with the
  new `orders.status` permission instead of `orders.edit`: it no longer edits items or prices, cancels orders,
  creates manual orders, payment links or invoices.
- **Backups, the updater and the price-list repair from review round 3** (review round 13, T6, T7, T8, T11).
  Migration `20261101150000_price_list_repair` no longer brings back what a merchant deleted from a seeded CZ/HU/PL
  price list (cash on delivery, a carrier with its rates, COD countries): it completes only a remainder a failed
  run can leave, an exact prefix of the seed's inserts; installs that applied the previous version keep it as
  applied. The update signing key is
  rotated with an endorsement: `php cli/build-update.php endorse` signs the new public key with the current secret
  key, and `php cli/update.php rotate-key --new-key=... --endorsement=...` accepts it only if the key the shop
  trusts made that signature (not because the repository or release notes say so), writes it into the key file
  and revokes the old key in `update_storage_dir/revoked-update-keys.json`. A package signed with a revoked key is
  refused even while that key is still configured, `doctor` fails on it, and the lost v1.9.0 key ships revoked;
  `php cli/update.php fingerprint` prints the trusted key's fingerprint. A restore run as root no longer chmods or
  chowns by path in directories the web user owns (a symlink swapped in made root take over e.g. `/etc/shadow`):
  restored files and the restored SQLite database are created exclusively with their mode and owned via
  `lchown` on that inode. Backups now include `stores.json`, the merchant's legal texts and the sold digital
  files (`digital_assets_base_dir`), and a files restore brings them back.
- **Payments and shipping from review round 3** (review round 13, T2, T3, T4, T9). A hidden store answers
  its payment and carrier webhooks (they returned 404, so payments of its test orders were never recorded);
  its storefront and browser API stay hidden. Each PayU payment attempt sends its own `extOrderId`
  (`<order number>-P<8 hex>`), so a retry after a cancelled payment is no longer refused as not unique;
  notifications of older orders without the suffix still resolve. The label of a cancelled parcel no longer
  keeps the cash on delivery, so the remaining parcel collects it. The carrier API is called with no
  database transaction open (the admin label action no longer holds the order lock, on SQLite the whole
  database, for the carrier's timeout); the label is recorded afterwards under the order lock, and a label
  that took the COD while another parcel's label was being created raises a `shipping.cod_twice` incident.
- **Page-driven worker no longer dies in admin requests** (review round 13, N1). `cli/worker.php` and the
  admin page module both declared a global `handleCacheRebuild()`, so a pass started by the dashboard,
  import, cache, audit, warehouse, feed, shipping or payments page ended in an uncatchable "Cannot
  redeclare" fatal error — and, the interval slot being taken, no other pass ran. The job handlers now live
  in the class `WorkerJobs` (`src/WorkerJobs.php`); `cli/worker.php` declares no global function. Custom
  code calling `handleEmailSend()` & co. calls `WorkerJobs::handleEmailSend()` now.
- **Installer self-check: "cannot tell" is not "protected"** (review round 13, N2 and lows). The check
  requests the static `src/install-probe.txt` (no PHP worker needed, so one FPM child no longer times it
  out) and the starter manifests, counts a path only when the file itself comes back, and follows one
  redirect to the same host (http → https). When it still cannot decide, `install.php` installs only after
  the key holder confirms that the probe file answers Forbidden. The check runs only after the installation
  key matched (before, any visitor could make the server connect to ports named by the client). A failure
  is logged without call arguments (the owner's password was one), and `error_log` / `php_error_log`
  (written next to the scripts by cPanel) are denied in `.htaccess` and `deploy/nginx.conf`.
  `install-key.php` is `0600` when PHP runs as the uploading account (suEXEC, per-user PHP-FPM) and stays
  `0644` only where PHP is another user (mod_php as www-data).
- **Page-driven worker on more servers** (review round 13, lows). Without FastCGI the page is held back
  only up to 1 MiB (`WebWorker::BUFFER_LIMIT`); a larger response (feed, sitemap) streams as before and
  that request runs no pass, instead of a whole feed in memory. Outside mod_php (php-cgi behind
  mod_deflate) the early-ended page is gzip-encoded by PHP itself when the browser accepts it: mod_deflate
  leaves an encoded body with its `Content-Length` alone, but held a plain one open until the pass ended.
- **Withdrawal period from the last parcel, refunds** (review round 13, N4, N5, T5). The withdrawal and return
  period of an order delivered in several parcels starts when the last one arrives (a parcel on the way, or goods
  in no parcel yet, keep it open; 2011/83/EU art. 9). A credit note checks the refundable balance again under the
  order lock refund requests take, so a credit note and a refund requested at the same time can no longer return
  more than was paid. A capture arriving while a refund is in flight is logged as ignored (a second capture) instead
  of moving the payment back to paid. A provider refund completes an item refund only by its refund id, never the
  first credit note of the same amount; the refund job links its own. The refund job moves the payment status
  through the state machine under the order lock (a disputed or already refunded payment is not overwritten).
  Every refund booked on the payment ledger (admin refund, cancellation, a refund made in the provider's
  dashboard) now gets a credit note correcting the invoice, not only money returned by hand.
- **An e-book supplied abroad is its own VAT group** (review round 13, N3 + low). In an order collected in the
  seller's store, the e-book of a consumer from another member state (OSS) is a breakdown group naming its country
  (`"10 AT"`, `VatService::breakdownKey`). Before, its rate was summed into the seller's own rates: the invoice showed
  "10 %" and the OSS report skipped the line because the invoice is domestic. Invoices, credit notes and the PDF
  ("10% AT") keep that country, and `cli/oss-report.php` now also reads domestic invoices for such groups. Shipping
  and the payment (COD) fee take the highest rate of the goods, not the e-book's foreign rate. A business buyer with
  a valid VAT ID of another member state gets the e-book reverse charged: 0 %, charged without the Slovak VAT, group
  `"0 DE RC"`, and the Art. 196 note on the invoice. `VatService::digitalSupply()` replaces `digitalRateCountry()`.
  Snapshots summed by rate alone still validate.
- **Digital order lines are frozen with the order** (review round 12, H1 + M2). `order_items.digital`
  (migration `20261103090000_order_item_digital`, existing lines backfilled as they were sold) records
  whether a line is digital content. Such a line reserves nothing, gets no parcel and no shipment line, so
  in a mixed cash-on-delivery order the paperback parcel collects the whole amount (before, an e-book
  first in the cart became a "parcel" of its own carrying the cash on delivery while the books went out
  with 0). Cancelling, returns, RMA restocking and the withdrawal period read the frozen flag, so an admin
  switching a variant's digital flag afterwards no longer loses or invents stock. A bundle cannot be a
  digital variant (refused in the editor and at checkout); a digital line keeps its required
  personalization. Cart and checkout read `variants.digital` only once it is migrated.
- **Page-driven worker releases the visitor first** (review round 12). PHP writes and unlocks the session
  only after every shutdown function, so the admin whose click carried a pass waited for the whole pass on
  the next click. The pass now closes the session, drops the visitor's session data (its changes are audited
  as `system`) and, without `fastcgi_finish_request` (Apache mod_php), ends the buffered response with an
  exact `Content-Length` and `Connection: close` before working, so a payment webhook is answered at once.
  A response already streamed without a length skips the pass. The pass may run 300 s
  (`WebWorker::TIME_LIMIT`) instead of the web `max_execution_time`.
- **Web installer needs the installation key and checks the server** (review round 12). The first visit
  writes `install-key.php` (a random key, readable over FTP, empty over HTTP); `install.php` installs only
  with that key, so the first stranger to open a fresh upload no longer becomes its owner. Before
  installing it requests `src/autoload.php` and `demo/*/dist/*.json` from its own server and refuses while
  they are served (`.htaccess` inactive). After seeding it deletes `demo/<name>/dist` (starter database,
  paid eBooks) and the key file. A failed installation shows a reference number; the exception (paths)
  goes only to the PHP error log.
- **E-books and download variants (review round 12, low findings).** A download of a book is taxed in
  its own VAT category `ebooks` (`digital_categories` in `config/vat-rates.json`; without a country's
  `categories.ebooks` it takes the books rate). With OSS on, a download in an order collected in the
  store is taxed in the consumer's EU country, not the store's. The marketing feed reports a product
  with variants as in stock from its variants, and catalog price rules on `product_type` "digital" now
  apply to download variants. A download variant without an active file is not for sale; the variant
  editor and the doctor (`catalog.digital_files`, linked from the dashboard) point it out. The bank
  transfer reminder day no longer builds a `"+-2 days"` date.
- **Digital variants of physical products.** A variant can be marked as a download (`variants.digital`,
  migration `20261102090000_variant_digital`, checkbox in the variant editor), for example an e-book
  next to the paperback and hardcover of the same book. The download variant is sold as digital content:
  quantity 1, no weight, no shipping, the digital-content consent, never reserved in the warehouse, and its
  file is a digital asset of the variant SKU. A listing card of a product with variants shows the
  availability of its variants.
- **Bookshop demo catalog** (`demo/bookshop/build.php`): 321 public-domain titles in 12 genres, each in
  three formats (eBook EPUB download, paperback, hardcover), with original descriptions, generated SVG
  covers and an EPUB demo edition. It builds into an empty catalog of the configured database.
- **Bookshop theme** (`themes/bookshop`, English): a book-store storefront with a serif typography, cover
  cards with the author, a home page with new arrivals, a bestseller shelf, genres and authors, and a book
  page with a format picker. New section providers `core.tagged-products` (products with one tag) and
  `core.specification-values` (top values of one specification, linking to a search). Product cards
  expose the product attrs (`custom_fields`), and the cart and checkout name the chosen variant.
- **Web installer** (`install.php`) for hosting without a shell: while there is no `config.php`, the
  storefront and the administration redirect to it. One form sets the shop name, seller identity, time
  zone, payment methods (cash on delivery, bank transfer, optional Stripe test keys), the private data
  folder and the owner account; it writes `config.php` with a fresh `admin_identity_key`, applies the
  private runtime paths, creates and migrates the SQLite database, and shows the owner's TOTP QR code and
  recovery codes once plus the doctor checklist. Afterwards `install.php` answers 404. Other stores still
  naming the example seller are switched to non-public.
- **Bookshop package** (`demo/bookshop/package.php`): builds `bareboneseshop-bookshop.zip` from a git ref
  with `vendor/bbf`, a ready SQLite database of the bookshop demo, its EPUB files and an English
  single-store `stores.json` in the web-denied `demo/bookshop/dist/`, and no `config.php`. The web
  installer copies that starter data into the private folder, so the shop sells right after the form.
- **Background jobs without cron** (`operations.web_worker`, set by the web installer): after a web
  response, at most once per `web_worker_interval` (60 s), one `cli/worker.php --once` pass runs in the same
  PHP process. Every web entrypoint (storefront, account, CMS pages, wishlist, downloads, API, admin)
  drives it, so a customer waiting on the downloads page receives a paid eBook without a cron job.
- **Bookshop package acceptance** (`tests/bookshop/run.sh`, CI job "Bookshop package acceptance"): builds
  the zip, installs it through the web installer in a clean `php:8.4-apache` container and, in Chromium,
  buys an eBook (bank transfer, then downloaded), a paperback (cash on delivery) and a hardcover (bank
  transfer) that the owner confirms and ships. The zip and screenshots are uploaded as a CI artifact.

## 2.1.0 — 2026-11-01

**Update signing key rotated.** The secret key that signed v1.9.0 was lost, so v2.1.0 is the first
release signed with a new Ed25519 key. `releases/update-public.key` now holds the new public key; its
fingerprint, as `php cli/update.php fingerprint --public-key=<that file>` prints it, is

    ae998f94dde4c8aa8651dd644cfa309880df155a6d0fa4bf695eca75279b576a

(Corrected after the release: the notes first gave `78d6474a…0e53`, the SHA-256 of the key file itself, which
`sha256sum releases/update-public.key` prints; both identify the same key.)

On every shop, before applying the v2.1.0 package: compare the fingerprint of `releases/update-public.key`
from the `v2.1.0` tag with the value above, replace the file that `update_public_key` points to, then run
`php cli/update.php doctor --package=...`. Packages signed with the old key are refused from then on; do
not apply an old-key package that was not already verified (README › *Signing key rotation*).

The signed packages are attached to the GitHub release `v2.1.0`: `update-2.0.0-2.1.0` (installed v2.0.0)
and `update-1.8.0-2.1.0` (one-time bootstrap of a v1.8.0 shop, see `docs/AI_UPDATE_RUNBOOK.md`).
Run `doctor`, `check` and `apply` for a v2.0.0 shop from the trusted v2.1.0 tree with
`--installed=<shop root>`, as for v1.8.0: the v2.0.0 updater's own `doctor` reports NOT_READY
("no migration runner is configured") for this package and does not record the core drift baseline.

### Upgrade notes (operator-facing and breaking changes from PRs #10-#14 and the follow-up audits)

Read before updating a live shop; run `php cli/migrate.php` after the files are in place, then
`php cli/setup.php doctor` (and `php cli/staging-preflight.php` on staging), which warn about the
settings below that silently change behaviour (http webhooks, cart reminders without consent, the
nginx PHP fallback when `operations.nginx_config` is set).

- **Round-11 tests & low findings (2026-11-01)**
  - A fresh install creates `stores.json` with the sample stores pl/hu/de/us set to `"public": false`: their URL
    prefix / domain answers 404 and they are left out of the sitemap and hreflang. Admin → Stores and seller
    publishes a store only once its own seller identity is entered; the default store is always public. Doctor
    reports a hidden store's sample seller as a warning. Existing `stores.json` files are not changed.
  - Cart reminders: one e-mail address gets at most one reminder per `cart_reminder.email_window_hours` (default
    168), however many carts were captured with it.
  - Customers see carrier names (order e-mails, account, tracking page) instead of internal carrier codes.
  - Logs mask HTTP Basic credentials, passwords in URLs, Stripe `sk_live_`/`rk_live_` keys, webhook secrets and PEM
    private keys — also a PEM key written after a secret-named key (`signing_secret: -----BEGIN …`, which used to
    keep the key body in the log); nginx (`deploy/nginx.conf`) and `.htaccess` also refuse SQLite `*-journal` files — **copy the
    nginx rule into your server config**.
  - New regression tests kill the mutants that survived the suite (stock reservation, adjustStock, refund
    idempotency, the currency of refund.reported, snapshot fingerprint).

- **Round-11 operations & data (2026-11-01)** — run `php cli/migrate.php` and `php cli/setup.php apply`.
  - Legal texts are overridden per store (`<data>/legal/stores/<store>/<locale>/<page>.html`), no longer per
    language: the US store's English terms no longer change the terms of an EU store that also speaks English.
    `setup apply` (or the first save in Admin → Stores and seller) copies a text saved per language to every store
    of that language and removes the shared file. **Review each store's texts; have them checked by a lawyer.**
  - Migration `20261101150000_price_list_repair` completes a CZ/HU/PL price list an old run left half-seeded on
    MySQL/MariaDB (no cash on delivery, few payment countries); a scope the merchant configured is not touched.
  - `Warehouse::setAvailable` locks the stock rows it reads (MySQL): a change committed meanwhile is not overwritten.
  - Redis queue: jobs queued before the upgrade get the current score on the first pull (once per queue,
    marker key `<prefix>score_v2`), so they are no longer starved by new jobs.
  - Doctor and staging preflight skip payment methods of stores no longer in stores.json.
  - Cached API listings and products are a miss once a catalog promotion or price list started or ended after
    they were written (no sale price for up to an hour after the sale).
  - `config/data-retention.json`: `orders_contact` also covers shipped orders; new policies `complaints_pii`,
    `withdrawal_requests_pii`, `rma_requests_text`, `return_receipts_note` (5 years, LAWYER TO REVIEW the periods)
    and `promotion_runs` / `price_list_runs` (deleted 400 days after they ended). A shop with its own copy of the
    file should add them.

- **Round-11 documents, e-mails & refunds (2026-11-01)**
  - The order confirmation lists subtotal, discount, shipping, payment fee and VAT above the total, so the
    lines add up. Customised `order_confirmation` overrides keep working; merge the new `{{#each totals}}`
    block from the default to show the lines.
  - An invoice of an order already paid (card, gateway) states it is paid instead of "Total to pay" with the
    IBAN and payment QR. The discount row is named "Discount" with its VAT rate in the VAT column.
  - Every refund that moves money — partial, RMA, provider-reported or by hand — mails the customer the
    refunded amount (`refund_issued` now has `{{refund_amount}}`; the full-order total stays in
    `{{order_total}}`). A refund by hand issues a credit note correcting the order's invoice.
  - Admin payment and refund amounts are typed in the order currency ("5,90"), no longer in cents.
  - The e-mail template guard also refuses consumer information hidden by an empty or unrelated `{{#if}}`,
    CSS rules, entity-encoded styles, tiny fonts, closed `<details>` or attributes;
    `cli/email-templates.php import` refuses a `--base` that is the installed templates, empty or another
    release instead of freezing every template as an override.
  - An order placed in a further store language (e.g. Spanish on the US store) returns to the
    order-done page, gateway return/cancel pages, pay link and legal links in that language.
- **Round-11 cash on delivery per parcel (2026-11-01)** — run `php cli/migrate.php` (`shipments.cod_amount`).
  Every parcel of an unpaid cash-on-delivery order shows in the admin what it collects ("COD <total>" on
  exactly one parcel, "prepaid" on the others), also when the carrier API is not connected and the parcel
  is booked in the carrier's portal. Shipping a parcel by hand or labelling it records that amount, so the
  total is never offered on a second parcel.
- **Round-11 release & CI (2026-11-01)**
  - **`stores.json` is no longer tracked by git.** The repository ships `stores.example.json`; the shop
    reads `private_runtime_dir/stores.json` (created by `php cli/setup.php apply`/`install` from the
    example, or moved there from an older install's `stores.json`), and the admin saves only there. Update
    packages never write or delete a `stores.json`. Doctor warns while a shop still runs on the example.
  - **Upgrading a git checkout without a pull conflict** (a `stores.json` edited by hand or saved by the
    admin would block `git pull`): with writers stopped,
    1. `cp stores.json <private_runtime_dir>/stores.json` (the running version already prefers that copy),
    2. `git checkout -- stores.json` (drop the local edits; they are in the copy),
    3. `git pull`, then `php cli/setup.php apply` and `php cli/setup.php doctor`.
    Without `private_runtime_dir`, use `cp stores.json stores.json.local` in step 1 and
    `mv stores.json.local stores.json` after the pull: an untracked `stores.json` in the application root
    still works and git ignores it.
  - CI: pull requests without repository secrets (Dependabot, forks) skip the Stripe test-mode checkout
    with a notice instead of failing; it still runs on `main` after the merge and nightly, and still fails
    there when the key is missing.
  - Catch blocks that swallowed an exception now log it (`Logger::exception`) or rethrow; static analysis
    keeps a ratchet (`SILENT_CATCH_BUDGET`) so new code cannot add silent ones.

- **Round-9 release & CI (2026-10-23)**
  - Stripe webhooks are verified by one implementation, `StripePayment::verifySignature`; the global
    `verifyStripeSignature()` helper is gone (extensions calling it: use the adapter method).
  - CI: PHP 8.5 joins the matrix, MySQL 8.0 (end of life) is replaced by 8.4 LTS, container images
    are pinned to digests and Dependabot proposes action/image/npm updates. The Stripe test key is
    visible only to the steps that need it, never to `npm ci`.
  - PHP 8.5 clean: no `curl_close()` calls; HTTP response headers come from
    `http_get_last_response_headers()` on PHP 8.4+.
  - README documents the update signing key rotation; SECURITY.md states what is actually supported.

- **Round-9 hotfix (2026-10-23)**
  - `php cli/restore.php` restores SQLite backups again (it held its own database connection, so the
    maintenance gate always answered "database is still open in another process").
  - Cash-on-delivery orders ship from `pending` without being marked paid: the label keeps the amount
    the carrier collects, and the invoice is issued at shipping. Other unpaid orders still do not ship.
  - The checkout total includes the cash-on-delivery surcharge and keeps an accepted coupon after a
    reload, so the order charges what the page showed.
  - nginx (`deploy/nginx.conf`): `location ^~ /uploads/` repeats the deny rules for dotfiles and data
    files (`.env`, `.sql`, `.sqlite` …). **Copy the updated `location ^~ /uploads/` block** into your
    server config if you based it on the sample.
  - Saving stock in the product editor applies the admin's change to today's stock: units sold while
    the form was open or being saved stay sold.
  - Migrations `20261022110000_invoice_download_token_hash` and `20261022230000_currency_price_lists`
    finish when re-run after an interruption on MySQL/MariaDB, and the price lists no longer fail on rows
    left from an earlier own price list. Installs that already applied them are not affected.
  - Retention `orders_contact` (`config/data-retention.json`) scrubs only closed orders (delivered,
    cancelled, returned, refunded), leaves empty fields empty and keeps refund ids and ERP markers in
    the note. A shop with its own copy of `data-retention.json` should add `status_in` and `keep_lines`.
- **Round-9 merchant onboarding (2026-10-23)**
  - **Admin → Stores and seller** (`stores.manage`, owners): storefront name, seller identity, IBAN, VAT
    registration and OSS of each store, written to `stores.json` (atomic, audited). `stores.json` must be
    writable by PHP for the page to save; otherwise it is read-only with a notice.
  - **`setup.php doctor` fails while a store still invoices as the shipped example seller** (Woodle,
    `example.*` e-mail, an IBAN with wrong check digits). Enter the real seller, or remove the stores you
    do not sell in. `setup.php install --admin-email=<address>` sets `admin_email` (new orders,
    payments, incidents).
  - An owner administers every store of `stores.json`, also one granted a single store; other roles
    still see only their stores. The product list has a "New product" button.
  - The order detail lists the consumer's withdrawals. Shipping such an order (shipment form, status
    change, bulk status, API v1 `POST /orders/{id}/status` with `"acknowledge_withdrawal": true`)
    needs an explicit acknowledgement; without it the API answers 422 `withdrawn`.
  - Orders paid online (card, gateway) or free get the `order_confirmation` e-mail (seller, withdrawal
    form, terms) when the payment arrives, not only `order_paid`. A gateway that cannot start a payment
    marks it failed, and the order page tells the customer.
  - Invoices repeat the variable symbol of the order's bank-transfer instructions; a credit note has its
    own, no IBAN and a "total credited" line instead of a negative amount due. Invoice PDFs keep Slovak,
    Czech, Polish and Hungarian letters.
  - **A price list in the store's currency sells the shared catalog in that currency.** The CZK price
    list of a CZK store sharing the EUR catalog was ignored (only lists in the product's currency
    counted), so the store charged euros and offered the EUR `shipping.json` methods. Now a product
    priced by such a list is shown, carted and ordered in the store's currency, and the store's own
    carriers and payment fees apply. **A store sells only in its own currency:** a product the list
    does not price, and a variant with a surcharge or its own price but no row of its own, is shown as
    unavailable in that store, cannot be added to the cart and is refused at checkout. Before, it was
    sold in the catalog's currency, and a cart mixing it with priced items could not be ordered.
    `setup.php doctor` warns (`stores.currency_prices.<store>`) and lists such items. **The shipped
    PLN, HUF and USD sample stores sell nothing** until you give them price lists in their currency
    (or remove them). With no recorded price history in that currency, no prior price is struck through. `shipping.json` method
    names are shown in the shopper's language (`cs`, `pl`, `hu`, `es` names added).
- **Round-9 payment readiness (2026-10-23)** — `setup.php doctor` and `staging-preflight.php` check the
  provider of every active online payment method (`payment.provider.<code>`): an unknown provider, the
  test gateway `mock`, or a shipped gateway (Stripe, PayU, GoPay, Comgate, ThePay) without its keys fails.
  The checks `provider.payment` (doctor), `payment_adapter`, `stripe_secret_key`, `stripe_publishable_key`
  and `stripe_webhook_secret` (preflight) are gone: they judged `provider_defaults.payment`, which
  checkout does not charge through. **A fresh install now fails `payment.provider.stripe`** until the
  sample card method gets Stripe keys or is switched off.
- **Round-9 shopper-facing labels and feed prices (2026-10-24)** — checkout no longer prints a payment
  method's provider code (`stripe`, `manual`) when the method has no description. The free digital
  order's payment/delivery and the GLS, Packeta and in-store pickup labels come from the shopper's
  language (`checkout.digital.*`, `checkout.free_payment.*`, `carrier.*`). The Google/Meta feed and the
  product JSON-LD give CZK/HUF prices in whole units, as the storefront shows and the cart charges
  (`750 CZK`, not `749.90 CZK`).
- **Round-9 low findings (2026-10-24)**
  - With its storage broken, the rate limiter refuses customer login, password reset and password change
    (and the admin login answers 429) instead of letting unlimited attempts through; ordinary requests
    still pass.
  - CZK/HUF invoices: whole-unit lines add up to the printed totals. Invoices and credit notes print the
    payment method the customer chose (e.g. "Platba kartou"), never its code (`stripe`, `free`). A lost
    invoice PDF (e.g. a database restored without the invoice storage) is re-rendered from the issue-time
    snapshots when downloaded or e-mailed, instead of "PDF missing".
  - An order with no delivery date is returnable for at most 12 months past the return period, not for
    ever; a delivery the carrier reports on the shipment starts the withdrawal and return period.
  - The `orders.delivered_at` migration can be re-run after an interruption. A pending order the expiry
    sweep cannot release raises its incident once a day. Deleting a product or category frees its URLs.
    The search category facet lists at most 30 categories. The PHP log masks secrets inside JSON and
    escapes line breaks.
  - **Removed unused public methods** (an extension calling one must switch): `Orders::byStatus()`,
    `Orders::byEmail()`, `Customers::byEmail()`, `Products::onSale()`, `Sitemap::storePrefixes()`,
    `StoreContext::ossEnabled()`, `Import::validateSlug()` (use `Import::registerSlug()`, which refuses a
    taken slug), `OrderWorkflow::isTerminal()` (`allowedTransitions($s) === []`), `Pricing::tierPrice()`,
    `Shipping::zoneForCountry()` (`zonesForCountry()[0]`), `Promotions::catalogRules()`,
    `Search::withHighlights()`, `StockAlerts::belowLowCount()`, `MockPayment::refundPayload()` and the
    `apiActiveStore()` helper.
  - **`tier_pricing` in `pricing.json` was never applied** and is gone from the sample. Quantity prices
    are price-list rows with a minimum quantity (Admin → Price lists).
- **Round-10 money and cart (2026-10-25)**
  - API v1 `PATCH` of a product refuses a `price` below the lowest base price its variants allow (422
    `price_below_variant_floor`), like the admin editor and CSV import.
  - `cart_add` and a restored cart (`cart_restore`) skip a product with variants unless one of its own
    variants is given; no line at the bare base price is created.
  - A `pricing.json` coupon with a fixed discount or a `min_subtotal` applies only in its rule currency
    (`conditions.currency`, else the cart-rules currency). A coupon shared by stores without their own
    `cart_rules` counts `max_uses` and `per_customer` across all of them.
  - Refunds: a refund request and a pending credit note each see the money the other has already promised
    back, so the same amount cannot be refunded twice. A Stripe refund still `pending` is not booked as
    refunded until it succeeds (also `requires_action`); when it fails, the payment returns to paid, the
    refund can be requested again and a critical `payment.refund_failed` incident is raised. A failed
    delayed payment (Stripe SEPA Direct Debit) counts as failed, so the unpaid order expires and frees its
    stock.
  - A credit note's number series year and PDF folder come from its issue date (one instant), so one issued
    around midnight on 31 December is not numbered in the next year's series.
- **Round-10 operations (2026-10-25)**
  - MySQL update rollback: refused while another session (web, worker) still uses the live database; the
    error names the rollback command to run once writers are stopped. Before importing the snapshot, live is
    dumped to `pre-rollback-*.sql` (owner-only) in the snapshot directory; a failed import re-imports that
    copy, and the dump is kept so writes the rollback discarded stay recoverable.
  - The updater sets installed file modes itself (0644, 0755 for executables) instead of copying the
    package's; a SQLite restore or rollback keeps the live database's mode, owner and group.
  - `HttpErpAdapter` follows `next_cursor` across pages (products and stock, `page_size`, a repeated
    cursor fails). A failed catalog or stock pull no longer keeps paid orders from being pushed;
    `cli/sync.php` still exits 1 and raises an `erp.catalog_pull_failed` incident.
  - CSP reports: a batch repeating one violation is stored once, at most 100 rows per reporter and 2000 per
    store per hour, kept 7 days (was 14).
  - `cli/setup.php` no longer treats `$1` / `$0` in a value as a regex backreference (the generated
    `config.php` could fail to parse) and removes its `.tmp` file; `stores.json` saves are locked and leave
    no `.tmp`; `*.tmp` and `*.lock` are refused over HTTP (`.htaccess`, `router.php`, `deploy/nginx.conf`).
  - Retention: failed (dead-lettered) jobs are deleted after `failed_jobs_days` (default 90; database,
    Redis and job files), manual backups after `retention.manual_max_age_days` (default now 180, 0 keeps
    them forever), and `cli/update.php backup` prunes old update snapshots like `apply` does.
  - Redis queue: ready jobs are ordered by priority, then by age (as the database queue); before, any job
    one second older outranked every priority. Let the worker drain the Redis queue before updating: jobs
    queued under the old ordering are picked after current ones.
  - Carriers: carrier webhooks need the signed `X-Shipping-Timestamp` by default
    (`shipping_webhook_require_timestamp`, set `false` only for a carrier that can sign nothing but the
    body). An unknown AWB is answered 404 and an ambiguous one 409 (with an incident), so the carrier
    redelivers instead of the event being dropped. "Delivered" for an order never marked shipped is
    recorded on the parcel with an incident instead of a 500 retried forever. Packeta and GLS without
    their API fail instead of inventing an AWB or transit events; `SHIP_MOCK_LABELS=1` enables mock
    labels for development.
  - API v1 answers a database error in a write with a generic 409 message; the PDO text is only logged.
  - robots.txt, the sitemap and the Google/Meta feeds of a store served on its own domain use that domain
    (app_url scheme and path), not the global `app_url`.
- **Round-10 low findings (2026-10-25)**
  - An attribute used by another store's catalog can be renamed (or its unit changed) only by the owner;
    product values follow the new name in every catalog. Its slug and type stay locked as before.
  - A consumer who withdrew in time has 14 more days from the withdrawal statement to send the goods back
    (RMA is no longer refused 14 days after delivery). Digital content can be complained about: its
    liability period runs from its first supply.
  - `cart_update` with a non-string `key` (e.g. an array) answers 400 instead of 500.
  - Cookie consent, the order IP, account registration and GDPR export throttling and their GDPR log use
    the client IP resolved through `trusted_proxies` (`Security::clientIp()`), not the proxy's address.
  - The editor and fulfillment roles land on the first admin page they may open after login instead of a
    403 dashboard.
  - Saving an address without a valid `type` stores a shipping address (no 500); editing an address in the
    account is checked like a new one (required fields, known type) and refused with 400.
- **Round-10 UX 1–5 (2026-10-25)**
  - The admin dashboard (owner) lists what `setup.php doctor` still flags for the current store, failures
    first, each with a link to the admin page that fixes it.
  - Checkout no longer offers a card method whose gateway cannot start a payment (e.g. Stripe without
    keys on a fresh install); a gateway replaced by an extension adapter is still offered.
  - Pay again: an unpaid online order shows a Pay button on its account page, and the admin can e-mail
    the customer a payment link (`order_payment_link` template, valid for a limited time, only the token
    hash is stored). Sending needs `app_url`; without it the admin gets an error and nothing is sent.
    A gateway failure no longer blocks the order: the next Pay opens a new session. A session opened
    after a failed payment is reused while it is open, so a second click does not open a second one.
  - `cli/seed.php` registers the sk/en/de product slugs in `slug_registry`, so seeded `/en/` and `/de/`
    product links open, and keeps capitalised words in slugs.
  - Stores and legal texts are managed in the admin (Stores page: add/remove a store; legal texts per
    language). When the code is read-only (Docker), `stores.json` and the texts are saved to
    `private_runtime_dir` and that copy takes precedence over the shipped file. A store with orders and
    the default store cannot be removed.
- **Round-10 UX 6–10 (2026-10-25)** — run `cli/migrate.php` (new table `back_in_stock_requests`).
  - Admin lists show order, payment, return, invoice, e-mail, customer and admin-user statuses (and
    payment methods) in the admin's language instead of raw codes.
  - Manual orders: Orders → New order takes SKU lines, the customer, shipping and payment (phone orders);
    the lines of a pending, unpaid order can be edited, with stock reservations moved accordingly. A paid
    order's lines stay locked.
  - Shoppers: sign in from the checkout and pick a saved address (or save the new one); after a guest
    order the confirmation offers an account for that e-mail with the order in it; guests look up an
    order's status by number and e-mail (`?page=order-status`, throttled); an out-of-stock product offers
    "e-mail me when it is back" — one e-mail, then the request is deleted (covered by GDPR export/erasure);
    a restock booked in any store of a shared catalog alerts the waiting shoppers of all its stores.
  - Installer: `--db-driver/--db-host/--db-port/--db-name/--db-user` switches (password from
    `BBE_DB_PASSWORD`, checked against the server before `config.php` is written), a warning when the
    private directory already holds another install's data, the owner's TOTP as a QR code in the
    terminal, and next steps with the shop's real address instead of `localhost:8080`.
  - Admin: Duplicate in the product editor (a draft copy with a new SKU and slug, no stock and no GTIN);
    a rich-text editor for product descriptions, their translations and CMS pages (the HTML view stays
    one click away; saved HTML is still sanitized on the server); a daily revenue chart per currency on
    the reports page; the admin fits a 360px phone — wide tables scroll in their own keyboard-reachable
    box, two-column panels stack, filter rows wrap, checkboxes are 24px targets (checked with axe on 14
    admin pages in the browser suite); editing a reverse-charge order keeps its VAT treatment; merchant
    e-mails (new order, paid, withdrawal, complaint, incident) in cs/de/hu/pl.
- **Round-10 documentation (2026-10-25)**
  - Spanish cookie and privacy policies (`templates/legal/es/`) replace the English fallback. Like the
    other templates they are a starting point: **have them reviewed by legal counsel** before production.
  - README: `db_sqlite_path` has no default (`config.example.php` points it outside the web root); the
    test runner keeps its database in the system temp directory (no `mkdir -p db`); checkout is a single
    page; the storefront, account and admin feature lists name what the shop does today.
  - `cli/worker.php` header: the worker serves the `file`, `database` and `redis` queue backends and runs
    the order-expiry, outbox, cart-reminder, cleanup, digest, backorder and incident sweeps.
- **VAT rates (2026-10-23)** — products with `vat_class` `books` or `medicines` now get each country's
  own rate instead of its `reduced` class (e.g. IE books 0 %, IT/ES 4 %, FR 5.5 %, DE medicines 19 %).
  FI reduced rate 13.5 % from 2026, LT 9 % rate replaced by 12 % / 5 % from 2026. Invoices already
  issued keep their snapshot; new orders to those countries are priced with the new rates.
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
  - **`tax.json` and `src/Tax.php` are removed.** VAT rates come from `VatService`; the file's stale
    country rates (SK 20 %) were no longer read. Its only setting still in use, `prices_include_tax`, is
    a `config.php` key (default `true`). A shop that set `"prices_include_tax": false` in `tax.json`
    must set `'prices_include_tax' => false` in `config.php`; it matters only for legacy direct
    `Orders::create()` callers that pass net prices with a separate `tax_amount`.
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
