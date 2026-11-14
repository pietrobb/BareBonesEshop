# Changelog

## Unreleased

### Upgrade notes (2.1.2 → 2.1.3)

Run `php cli/update.php doctor --package=...` before `apply`, and `php cli/setup.php doctor` after it.

- **This update is installed by the 2.1.2 updater; close the shop at the web server yourself.** `apply` runs the
  installed `cli/update.php`, so the updater fixes of 2.1.3 (maintenance window, stricter check) take effect only
  from the next update and for a `rollback` run after this update. The 2.1.2 updater deletes `.maintenance` when
  `apply` ends, whatever the outcome: after a failed update and also after a failed automatic rollback, which leaves
  a half-restored shop serving orders; it also stops blocking requests after an hour (a long MySQL dump), and it
  deletes a `.maintenance` you placed yourself. Before `apply`, stop the job worker and cron and answer 503 at the
  web server (nginx `return 503;`, Apache `RewriteRule ^ - [R=503,L]`, or take the site offline). If `apply` fails,
  keep the web server closed: when the output says "automatic rollback also failed", run the rollback command it
  names (`php cli/update.php rollback --snapshot=... --force --accept-data-loss`) and check that it ends without an
  error and that `php cli/update.php list` shows that snapshot as `rolled-back`; only then open the web server. A
  failed `apply` whose automatic rollback succeeded (`auto-rolled-back`) or that stopped before changing a file
  (`aborted`) left 2.1.2 in place.
- **Admin amounts are typed in the currency, not in cents.** Variant prices, price lists, promotions and coupons,
  the B2B credit limit, supplier purchase prices and shipping and payment amounts took cents up to 2.1.2: from 2.1.3
  "1290" saves 1 290 €, not 12.90 €. Tell everyone who enters prices to type "12.90"; the stored amounts do not
  change. A CZK/HUF amount stored finer than a whole unit is shown with its cents ("12345.67") and kept on save. A
  price above 20 000 000.00 is refused in the admin, the CSV import and feeds (the row or item is reported, the product
  keeps its price), and a thousands separator must group three digits (`5..0` and `1.29,00` are refused).
- **CSV import and feeds refuse an ambiguous price.** 2.1.2 read a single separator always as the decimal mark:
  "199.000" was 199,00, "12.990" was 12,99 and "2.000" was 2,00 in every currency (only a repeated one, "1.299.000",
  was digit grouping). From 2.1.3 one separator before exactly three digits ("199.000", "2.000", "12,990 Ft",
  "1.234 Kč") is refused with a row or feed item error, because it may as well mean thousands; the product keeps its
  price, and a feed item still updates its stock (the feed status shows `partial: … prices refused`). Before the first
  import or sync after the update, check which of these is your case:
  - **An export with three decimals** (common for CZK and HUF ERPs: "199.000" meaning 199 Kč) was read correctly by
    2.1.2. From 2.1.3 its prices below 1 000 are refused and stay frozen (a supplier change from 199 to 189 Kč is not
    applied), while prices from 1 000 up ("1299.000") still update, so the gap is easy to miss. Add `"decimal": "."`
    (or `","` for "199,000") to the feed's `price_to_cents` mapping (see "Import and feed prices" below); a CSV
    import has no such setting, so export the CSV with two decimals ("199.00") or none.
  - **An export that meant a separator as thousands** ("12.990 Ft", German "2.000" €) was stored 1 000 times too low by
    2.1.2 (12,99 Ft, 2,00 €), and those products sell at that price now. From 2.1.3 such a value is refused and the
    wrong price stays. Find the affected products (Products, sort by price; prices far below the usual), export the
    prices without a separator ("12990") or set `"decimal": ","` on the feed, and run the import or sync again.

  Look through the errors of the first imports and syncs (the import message and the feed page list the refused rows).
- **ERP product sync takes only integer cents.** 2.1.2 stored the `price` and `compare_price` an ERP adapter returned as
  sent. From 2.1.3 each must be an integer or a string of digits in cents (`1990` or `"1990"`); a decimal number
  (`1990.0`, `19.90`), a sign (`"+1250"`), a separator or text refuses that product, which is counted as refused and not
  written. An ERP whose adapter returns prices as decimal numbers updates no product after the update: before it, make
  the adapter return integer cents (`(int)round($price * 100)` for a price in units) and run one sync by hand.
- **Custom themes: the store switch is a new header variable.** From 2.1.3 `language_links` lists only the current
  store's languages; the links to the other stores (SK, CZ, HU) are in `store_links` (label "CZ · Kč", `title`,
  `active`). A theme whose header renders only `language_links` loses the navigation between stores: add the
  `store_links` loop from `themes/default/blocks/storefront/header.html` (and the `.store-switch` style) to it.
- **MySQL with a MariaDB dump client: install the MySQL client first.** Against a MySQL server `mariadb-dump` (also
  installed as `mysqldump` by the Debian/Ubuntu `mariadb-client` package) writes generated columns into the dump and
  MySQL refuses it on restore. The 2.1.2 updater refuses this package when it restores its pre-update dump into the
  probe database (`doctor` reports the backup probe as FAIL, `apply` stops before changing a file with the MySQL
  restore error, not the client name), but only when the dump holds a row of `categories` or `invoices`, the two
  tables with a generated column: a shop with no category and no invoice yet passes the probe and is updated, and
  its later snapshots stop restoring once it has one. Install the MySQL client before the update either way. From
  2.1.3 on `doctor`, `check` and `apply` name the client whether or not those tables hold rows, and
  `php cli/migrate.php apply` runs no migration because its pre-migration backup fails. Install
  the MySQL 8.x client and set `update_mysql_dump_binary` and `update_mysql_client_binary` to it
  (`docs/AI_UPDATE_RUNBOOK.md`); the check names the generated columns concerned (`categories.parent_id_uniq` and
  the invoice uniqueness column). A MariaDB server keeps the MariaDB client.
- **Stricter update check (from the next update):** the 2.1.3 `check` and `apply` refuse while the doctor reports a
  configuration FAIL (a private path such as `jobs_dir` inside the web root, a v1.x `stores.json` without
  `private_runtime_dir`). The 2.1.2 updater that installs this release does not refuse: fix every FAIL its
  `php cli/update.php doctor` reports before `apply`, or the next update will be refused. A shop that still has `api.php` gets a `payment_webhooks`
  warning with the webhook URLs to set in the payment gateways.
- **Migration** `20261105120000_product_stock_updated_at` adds `products.stock_updated_at`: a sale or stock change
  moves it instead of `updated_at`, and API v1 `updated_after` matches either column. Feeds and sitemaps are rebuilt
  only for a change they show (price, content, going in or out of stock).
- **Migration** `20261107120000_feed_last_errors` adds `feeds.last_errors`: the feed detail lists which items the
  last sync refused and why (also for a scheduled sync), not only their count.
- **Migration** `20261110120000_back_in_stock_retry` adds `back_in_stock_requests.mail_tries` and `next_try_at`: a
  "back in stock" notice not delivered yet keeps its request and one retry series (review round 27).
- **Migration** `20261110190000_saved_for_later_items` adds `saved_for_later_items`: the "Buy later" list of a
  signed-in customer (see below). Nothing to configure; GDPR export and erasure include it.
- **Migration** `20261111100000_saved_for_later_variant_sku` adds `saved_for_later_items.variant_sku` (review round 29):
  a saved variant line keeps its variant's SKU, so on SQLite, which gives a deleted variant's id to the next one, the
  line is not mapped onto another variant. Until it runs, lines are saved without it; until the table exists, a
  signed-in customer's cart works and shows no list (was: a 500).
- **Migration** `20261111200000_order_billing_address` adds `orders.billing_addr`: the address an order is invoiced to
  when the shopper gives one other than the delivery address (see "Billing address other than the delivery address" under Changes). Existing orders keep NULL
  and are invoiced to their delivery address as before. A custom theme offers the billing address once it copies the
  billing part of `themes/default/blocks/storefront/checkout.html` (without it checkout works as before); an admin
  override of the `order_confirmation` or `merchant_order_created` e-mail shows the addresses once it adds the
  `delivery_address` / `billing_address` loops of the default template. The orders CSV export gets nine `billing_*`
  columns at the end, and API v1 orders a `billing_address` (for keys granted `customers:read`).
- **Migration** `20261112100000_customer_address_company_ids` adds `customer_addresses.ico` and `dic` (review round
  33): a saved billing address keeps the company's IČO and DIČ with its name and IČ DPH, so the next checkout prefills
  the whole company. Existing addresses keep NULL; until it runs, addresses are saved without them as before.
- **Migration** `20261113180000_invoice_oss_net` adds `invoices.oss_net` (review round 36): what an issued invoice or
  credit note adds to the 10 000 EUR OSS threshold. It fills it for the documents of the current and the previous year;
  until it runs, the threshold counts every document from its lines as before (slower).
- **Migration** `20261113190000_oss_threshold_totals` adds the table `oss_threshold_totals`, `orders.oss_net` and two
  indexes (review round 36): the threshold's year totals per store, filled for the current and the previous year, and
  what each order of those years adds until it is invoiced. On a large shop it reads two years of orders once: both
  migrations together took 7.7 minutes for 200 000 invoices on MySQL 8.4 (they commit every 500 rows).
- **Maintenance window (from the next update, and for a rollback run after this one):** a refused or failed update
  step of the 2.1.3 updater no longer reopens the shop. A rollback refused (without
  `--accept-data-loss`, unknown snapshot, changed files) or an update refused after it entered maintenance puts back
  the window it found. A rollback that stopped after it began restoring and a failed `php cli/migrate.php apply` keep
  the shop on 503 until the command named in `.maintenance` succeeds: run that command, do not delete the file of a
  half-restored shop by hand. A failed pre-migration backup ran no migration and reopens the shop. A window whose
  lock another process holds ends after 24 hours at most; one left by a killed updater still ends after an hour.
  Updates are rolled back newest first: a rollback of an older snapshot is refused while a later update is installed.
- **Theme assets:** the default theme is version 2.3.12 (bookshop 1.0.2), so browsers fetch the changed `storefront.css` and
  `storefront.js` (served with a year of cache under `?v=<theme version>`). A theme copied from the default one that
  takes over these changes bumps its own `version`.
- **Customer sign-in needs a confirmed e-mail address.** A customer who never confirmed the address is sent the
  confirmation link at the next sign-in attempt (at most 3 an hour) and signs in with the link and the password, or
  resets the password. Expect questions from such customers in the first days.
- **Checkout:** "Standard Delivery" and "Express Delivery" from `shipping.json` are now offered next to the store's
  `shipping_rates`; remove them from `shipping.json` if the shop does not ship that way.
  Checkout refuses over-long fields with `checkout.error.field_too_long`, and `cart_restore` takes at most 200 entries
  (100 lines, 64 KiB body).
- **E-mail:** the From line names the store, and without `MAIL_REPLY_TO` customers' replies go to the store's
  `legal.email` in `stores.json` instead of the noreply mailbox: make sure that mailbox is read, or set `MAIL_REPLY_TO`.
- **Web server:** the shipped rules deny the JS tool configs at the web root (`*.config.js`, `.mjs`, `.cjs`). A custom
  nginx site config needs the new `deploy/nginx.conf` deny line; the doctor lists it under `operations.nginx_config`.
- **Admin orders:** recording "Paid" with less than the order total is refused with the missing amount. A manual order
  starts with no shipping or payment method chosen; pick both. A product created in a store that shares another
  store's catalog is priced in that catalog's currency.
- **Root must never run PHP of a tree another account can write; run every step as the owner of the application
  root.** From 2.1.3 every `php cli/….php` run by root switches to the owner and group of the application root before
  it loads code of `src/` (a notice says so); in a tree root owns, root stays only when no other account can change
  anything in the tree or above it, else the step is refused. That switch is code in the tree itself (`cli/cli-only.php`):
  an account that can write the tree, often the web user, can change or replace it, and root then runs its copy. It is a
  second line of defence, not a fix: run steps as the owner (`sudo -u <owner> php cli/….php`, with `Defaults use_pty`
  in sudoers, the default from sudo 1.9.14, or the step can type commands into root's terminal), or install the wrapper
  `deploy/bbe-step.sh` outside the tree (`install -o root -g root -m 0755 <verified release>/deploy/bbe-step.sh /usr/local/sbin/bbe-step`)
  and run `bbe-step /var/www/shop update.php apply …`: it switches to the owner (never group root) before any PHP of the
  shop runs, in a session of its own without root's terminal as controlling terminal; a tree root owns it runs as root
  only after checking that no other account can change it (see "A tree root owns" in the README).
  Install `bbe-step` from a release you verified, not from the shop's copy. The step gets no terminal (pass `--yes` to
  `restore.php`), only the `BBE_*`, `BAREBONES_*`, `SMTP_*`, `MAIL_*`, `PAY_*`, `LANG`, `LC_*` and `TZ` variables of
  root's environment, and nothing it starts outlives it; Ctrl-C stops it at once. A step now changes only what that owner can write: run backups and restores
  as the user that owns the files they change (`sudo -u www-data php cli/restore.php …`, as the runbook says). The
  Docker `setup` service hands the directories it fills to the checkout's owner for the command and to `www-data`
  afterwards.
- **Install 2.1.3 as the owner of the application root, not as root.** The 2.1.2 updater keeps root, and the theme
  check of 2.1.3 runs the new release's checkout probe as root only for a shop root owns alone: otherwise `apply`
  started as root is refused with `Theme checkout contract: Not a theme problem: the updater runs as root …` (2.1.2
  adds "would break checkout"; your theme is not the cause). The 2.1.2 updater does not tell the probe which shop it
  copied: for a shop root owns alone, run it as root with that shop named,
  `BBE_INSTALLED_ROOT=/var/www/shop php cli/update.php apply --package=…`. If an
  update ever ran as root, it left the update directory, its snapshots and the backups to root, and the next step,
  which runs as the owner, could not read them (it reported "version unknown" and "no snapshots"; 2.1.3 refuses and
  names the path). Before the update, as root: `chown -R <owner>: <update_storage_dir> <backup storage_dir>` (and the
  private directory if root created it), and make the package directory readable for the owner
  (`chmod -R o+rX /srv/releases/update-2.1.3` or `chown -R <owner>:`). Then run
  `sudo -u <owner> php cli/update.php apply --package=…` (or `bbe-step`).

### Changes

- **The checkout prices the delivery it offers after a change of country (review round 36, medium 3).** The form
  still named in-store pickup (the default) after the buyer switched to an address abroad, where pickup is not
  offered, and the VAT preview counted the goods as collected in the shop: a business with a valid Austrian VAT ID saw
  Slovak VAT (26,40 €) while the order went by courier under reverse charge (22,68 €), and without JavaScript got a
  "total changed" it did not cause. Goods count as collected in the shop only where pickup is offered for the
  address and the cart's weight. Without JavaScript, cash on delivery refused for in-store pickup names the new
  total, as "total changed" does.
- **The OSS threshold counts every distance sale (review round 36, medium 2).** Only invoices charged the seller's
  own VAT counted toward the 10 000 EUR threshold. A shop that sold past it last year under OSS and switched OSS off
  counted 0 this year, as did a shop whose later sales this year were already charged the buyer's VAT, and one credit
  note brought it back under: Austrian consumers paid 23 % Slovak VAT again and the doctor reported "under the
  threshold". Now every invoice and credit note stores what it adds (new column `invoices.oss_net`; the migration
  fills it for this and last year's documents): goods sent to a consumer of another member state, whatever VAT they
  were invoiced with, and an e-book where the consumer lives in another member state (also next to goods exported
  outside the EU; an e-book billed to a consumer in the seller's state no longer counts with goods sent abroad). A
  credit note subtracts its lines, or credited by amount its invoice's share. Orders placed and not invoiced yet (an
  unpaid bank transfer) count too, and the sale that passes 10 000 EUR is the first one charged the consumer's VAT
  (art. 59c). Placing such an order counts again under a per-seller lock: when another order passed the threshold in
  the meantime, the buyer is asked to confirm the new total ("The VAT of the order changed in the meantime"). The
  doctor names the store by its name.
- **Shipping: weight bands in every currency, no COD with a no-COD method, settings page (review round 36, low).** A
  cart charged in another currency than the store's price lists gets the carriers' built-in prices only for a method
  whose rate covers the country and also the cart's weight and volume band; only the method's maximum weight was
  checked, and GLS was offered for 8 kg to Austria where its rates end at 5 kg. Checkout no longer offers cash on
  delivery with a delivery method set to "no cash on delivery" (the order refused it after). The shipping settings
  page says which prices apply in which currency and that "Standard" and "Express" at checkout come from
  `shipping.json`, and its tables scroll inside their card instead of pushing the page sideways on a desktop.
- **The OSS threshold check no longer slows down checkout (review round 36, medium 1).** It read every invoice of two
  years on each checkout preview and order: about 5 s per request on MySQL at 200 000 invoices (1.6 s on SQLite), on
  every change of country or shipping. Issued and cancelled documents now update a per-store, per-year total (new
  table `oss_threshold_totals`), a placed order stores what it adds until it is invoiced (`orders.oss_net`), and the
  check reads those few rows; past the threshold last year it stops there.
- **A late refund of a released item refund stops the second one (review round 36, medium 4).** The admin released a
  stalled item refund (its credit note reversed) and refunded the return again; when Stripe then paid out the released
  one after all, the second refund stayed queued and the worker sent it too: a multi-item return refunded twice, with
  only an incident that did not say which order. Now the second refund of the same units is stopped (failed, its credit
  note reversed, its queued job pays nothing) and the order history says which and why. One Stripe already holds cannot
  be stopped; the order history names it so the admin can check it at Stripe. Both report an incident, and the
  incident list in Operations links an incident to its order. A pending refund of other returned units is left alone.
- **Order history texts and tests run on their own (review round 36, low).** "Credit note reversed: Item refund
  released by admin: …" and "Admin receipt" stayed in English in every admin language; they are translated now. The
  history row of a late refund of a released item refund no longer reads "Payment failed → Payment failed". Running
  one scenario on its own (`php cli/test.php --only=FoundationScenario.php,X.php`) now applies every migration
  after Foundation, so the R34–R36 regressions also pass when run alone.
- **A refund booked while an item refund is in flight gets its credit note (review round 36).** Money refunded in the
  Stripe dashboard (or by an admin refund) while an item refund of the order waited for its job found the invoice
  credited already by that item refund's credit note, whose money had not moved. Its credit-note job failed for good
  ("The refund exceeds what the invoice still has uncredited"); once the item refund was released or declined, the
  ledger held money without a credit note. The job now waits for the item refund in flight (re-checked after 1, 2, 4 …
  at most 15 minutes) and issues the credit note when it settles. A new refund fuzzer
  (`AuditRound36RefundFuzzRegression`, `R36_REFUND_FUZZ_RUNS` seeds, SQLite and native MySQL) checks that Stripe, the
  ledger and the credit notes hold the same money after random returns, releases, late payouts and dashboard refunds.
- **Account addresses: long fields and the VAT ID (review round 35).** A company, name, street, city, ZIP, phone or
  VAT ID longer than its column (191 characters) failed the save with a 500 on MySQL; it is now a field error. The
  VAT ID is checked as the checkout checks it (country code and 6–14 letters or digits) and stored without spaces,
  dots and dashes, upper case, on a new address and on an edit.
- **Built-in carrier prices no longer override the merchant's shipping methods (review round 35, medium 2).** When
  the store's own rates did not cover the country and weight, the checkout asked every carrier for its built-in
  prices: Packeta (set up up to 10 kg, Slovakia and Czechia) was offered for 24 kg and to Austria at 10.99 €, a
  switched-off method came back, and in-store pickup (set up for Slovakia) appeared for an address abroad only when
  the cart was heavy. A carrier set up as a shipping method is now quoted only as set up there (its countries, its
  weight bands, active or not); built-in prices remain for carriers the store has no shipping method of. A cart charged
  in another currency than the store's price lists still gets the carrier's built-in tiers in that currency, but only
  where one of its methods is active, covers the country, allows the weight and volume and, for a cash-on-delivery
  cart, takes cash on delivery. Where no
  method covers an address, checkout says shipping is not available there.
- **No second refund of a return Stripe paid out after its release (review round 35, medium 1).** When the admin
  released a stalled item refund and Stripe's late `charge.refunded` then booked its money (with a credit note, 2.1.3),
  the return still offered "Refunded": a click issued another credit note and sent the money again (19.90 returned,
  23.89 paid back; a return of several lines was refunded twice in full). That money now refunds the returned units:
  the return becomes refunded, nothing of it can be credited, refunded or exchanged again, and a returned e-book
  stays revoked. A released refund nobody paid out leaves the return refundable as before.
- **VAT details (review round 35).** A manual order billed to another address than delivered to lost the "not
  verified in VIES" marker of its IČ DPH, so the invoice printed it as a checked registration. An e-book next to goods
  reverse charged in the same member state (an exempt intra-Community supply) was summed into the goods' VAT group and
  the invoice cited only Art. 138; it is now its own reverse-charge group ("0 DE RC") and the invoice also cites Art.
  196. The e-book of a business with a valid VAT ID of the seller's state is supplied in the seller's state (art. 44):
  next to goods exported to the USA it was zero-rated with them, it now pays the seller's VAT. Unchanged by design: a
  consumer's e-book billed outside the EU next to goods exported there is zero-rated with them (the billing and the
  delivery address both place the buyer outside the EU), while a consumer download alone billed outside the EU keeps
  the seller's VAT (round 5: a declared country alone is no evidence).
- **Documents and histories in words, not codes (review round 34).** The invoice prints the seller's and buyer's
  country by name in the invoice language, as the CHANGELOG promised. An OSS invoice names the rate country on its
  goods and shipping lines and in the VAT recap ("20% AT"), not only on the e-books. The order history notes written
  by the code ("Payment confirmed via webhook", "All goods shipped", "Item refund released by …", expired orders,
  refunds confirmed) read in the admin's language; the free digital order and the received bank transfer were noted
  in Slovak and are now written in English and translated (older Slovak notes too). The payment history's event
  column reads "Set in the admin: Paid" instead of `admin.paid`. The customer's returns list shows the status in
  words ("Requested"). The variant table's availability and stock policy options read in words. An admin edit that
  asks for more than one of a download saves one, as before, and now says so next to "Items saved".
- **The 10 000 EUR OSS threshold is applied at checkout (review round 35).** With OSS off, the threshold was only a
  doctor warning, and its count left out sales in another currency and an e-book billed to a consumer of another
  member state on an invoice whose goods were supplied at home. Both count now (other currencies converted with the
  new `eur_exchange_rates` of `config/vat-rates.json`; approximate values, replace them with the ECB rates your
  accountant uses — a currency sold in without a rate is reported by `stores.oss_threshold.<store>`). Past the
  threshold in the current or the previous year, checkout charges consumers of other member states their own
  country's VAT as with OSS on, and the doctor asks to register for OSS and switch it on.
- **The credit note of a released item refund executed late keeps the returned items' VAT rates (review round 34).**
  When Stripe's `charge.refunded` arrives after the admin released an item refund, its credit note now credits the rates
  the reversed item credit note did. It spread the amount over every VAT rate of the invoice, so in an order mixing
  rates the VAT and OSS reports no longer netted to zero for that return.
- **Checkout details (review round 35).** Over the VAT ID check budget of the previews, an unchecked VAT ID was shown
  with VAT, but the order checked it in VIES and charged reverse charge (27,89 € shown, 22,68 € charged; without
  JavaScript a "total changed" message the buyer did not cause). The session remembers a VAT ID the previews could
  not check, and the order prices it the same way: unverified, at the total shown (the invoice marks it "not verified
  in VIES"); once VIES has answered, previews and order use the answer. Without JavaScript, cash on delivery with
  in-store pickup was refused with "Delivery is not available to the selected country": it now says cash on delivery
  is not available with that delivery method (new key `checkout.error.cod_unavailable`). The "total changed" message
  names the new total, which on a phone is far below it. Moving through the billing country list of an order with a
  download with the arrow keys reloaded the page on every step: it re-prices once the buyer stops or leaves the list.
- **Checking a VAT ID no longer fails the checkout previews (review round 34).** The shipping and payment previews
  check a VAT ID in VIES within a budget of 20 lookups per 10 minutes and client, but running out of it answered the
  whole request with HTTP 429: about ten changes of a business's details broke the JS checkout, for everybody behind
  the same office IP. Over the budget an unchecked VAT ID now just counts as unverified in the preview (the order
  itself is still checked), and a VAT ID whose VIES answer is cached no longer uses up the budget.
- **Releasing a stalled item refund (review round 35).** A second "Release" of an item refund whose first release found
  it executed at Stripe (booked to its credit note) or still processed there showed an error: it reports the same
  outcome again and changes nothing. Only that release's own outcome is repeated (its audit entry names the Stripe
  refund): a refund Stripe executed or accepted without a release is still refused. A Stripe key that may not read refunds (a restricted key without the Refunds
  permission; Write includes Read) made every release fail with "try again later": Stripe answers 403, the release is
  still refused (nothing is released) and the admin is told to give the key that permission (new key
  `admin.order.msg.refund_release_forbidden`).
- **Editing an order's lines in the admin keeps the VAT of every line (review round 34).** The edit re-priced the
  buyer's VAT ID as unverified unless the whole order was reverse charge, and compared only the order's scenario. An
  e-book reverse charged for a German business next to goods taxed at home then silently paid German OSS VAT after
  the edit, and the order of a business with a Slovak VAT ID delivered to Czechia (Slovak VAT) could not be edited.
  The edit now takes the VAT ID status the order was placed with (a reverse-charged line, else the VIES answer on
  record from before the order) and keeps the scheme and country of every line; a line that would be taxed
  differently refuses the edit ("VAT treatment changed") instead of re-taxing it.
- **Admin texts (review round 35).** The order history notes of a released item refund that the release found executed
  or still processing at the provider, and of a released refund the provider executed later, read in the admin's
  language with the provider, refund id and admin. The order detail shows its last payment event in words ("Last
  event: Partially refunded", "Refund executed") instead of the code. The Spanish admin translates the 8 texts it
  showed in English, among them the "To" column of the status history. The R34 VAT test sets up the tables it
  uses, so it also passes run on its own.
- **Stripe test-mode checkout check (review round 35).** Since 2026-11-12 Stripe shows the hosted Checkout to the US
  CI runner in dollars (Adaptive Pricing) and the order's EUR amount only in its currency picker, an iframe; the
  real-gateway check looked for "€" in the top page only and failed in Chromium and Firefox although the session
  rendered with the card form. It now looks in every frame of the Stripe page.
- **Goods collected in the shop pay the shop's VAT, whatever the buyer's address (review round 34).** The VAT
  scenario looked for the pickup method in a shipping quote at weight 0; for an address abroad that quote came from
  the zone's own rates, so an order collected in the shop by a buyer with an Austrian address (heavy enough for the
  pickup rate to apply) paid 20 % Austrian VAT through OSS and was listed in the OSS report. The pickup method is now
  recognised by its definition (`shipping_methods` carrier or service type `pickup`, or the pickup adapter).
- **VAT details (review round 34).** A download for a business with a valid VAT ID of another member state is
  reverse charged in the VAT ID's state wherever it is billed (art. 44; billed to Slovakia or outside the EU it paid
  Slovak VAT), and its reverse-charge group is named by that state. With OSS off, the setup doctor and the admin
  dashboard warn once a store's cross-border sales to EU consumers at its own VAT (issued invoices net of credit notes,
  EUR, all stores of the seller) pass 10 000 EUR in the current or the previous year (check
  `stores.oss_threshold.<store>`). An invoice of exported goods next to an e-book reverse charged now cites both
  grounds (Art. 146 and Art. 196). An IČ DPH VIES could not check (unreachable) is printed on the invoice as "not
  verified in VIES". Unchanged by design (round 5): a consumer download billed outside the EU keeps the seller's VAT —
  a declared country alone is no evidence for leaving EU VAT.
- **Releasing a stalled item refund asks Stripe first; a refund Stripe executes afterwards gets its credit note
  (review round 34).** When Stripe executed an item refund but its answer was lost and the job ran out of retries, the
  admin could release the credit note, and the late `charge.refunded` then booked 21.00 as returned with the credit
  notes netting to zero and nobody told. The release now looks the refund up at Stripe by its refund key: one Stripe
  executed is booked to the credit note ("the provider did execute the refund"), one it still processes waits for its
  webhook, and when Stripe cannot be reached nothing is released. A refund executed after a release is booked with a
  new credit note and raises the critical incident `payment.released_refund_executed`. Also: a payment_refund job of a
  released refund queued again by hand pays nothing out; a second click on "Release credit note" reports it was
  released instead of an error; an item refund of an order without online payment, marked refunded by the admin, now
  e-mails the customer.
- **Checkout details (review round 34).** Without JavaScript, a buyer who picked another shipping or payment method and
  ordered without "Recalculate" was charged a total the form never showed (19.90 EUR shown, 27.89 EUR ordered). The
  no-JS form now posts the total it showed; an order re-priced since is not placed, and the form shows the new total
  to confirm ("The order total was recalculated…"). A re-rendered checkout (recalculation, the billing country of an
  e-book, a refused order) keeps the consents already ticked, including the digital-delivery consent, and with JS the
  changed field keeps the focus across the reload. Under a free-shipping coupon or promotion every delivery method is
  listed free, as the summary and the order charge it (express showed 7.99 EUR).
- **Cash on delivery of a zero-rated order: the total shown is the total charged.** Under export or reverse charge
  the checkout form and the payment-methods API priced the carrier's COD surcharge as the difference of two net,
  rounded prices (net(4.99) − net(3.99) = 0.82 EUR), while the order charges the surcharge net on its own
  (net(1.00) = 0.81 EUR). The shown total was a cent off, and the no-JS confirmation refused every attempt to order.
  Both now charge the surcharge the same way.
- **Account addresses: IČO and DIČ (review round 34).** A longer IČO or DIČ than the checkout accepts is refused on
  the field ("Enter a valid company ID or tax ID…") instead of failing the `VARCHAR(40)` column with an error 500 on
  MySQL. An edited address stores them without spaces and in upper case, as a new address and the checkout do. An
  address saved before IČO and DIČ were kept gets them (and the IČ DPH) from the next order that saves the same
  address for the same company.
- **An e-book next to goods is taxed in the billing country in every direction (review round 33 follow-up).** A
  download in a mixed cart took the goods' treatment whenever the billing country worked out domestic, and was skipped
  when the goods carried no VAT. A printed book and an e-book sent to Austria and billed to a Slovak consumer charged
  the e-book Austrian VAT, and the OSS report listed it under AT; sent to Switzerland and billed to Vienna, the e-book
  was zero-rated. The e-book now pays the seller's VAT when the buyer lives in the seller's state (its own "SK" group
  on the invoice, left out of the OSS report) and the billing country's OSS VAT next to exported goods (keeping its
  gross price; the report now also reads export and reverse-charge invoices for such lines). A buyer billed outside
  the EU next to exported goods still pays no VAT on the e-book.
- **Order e-mails label the company in the order's language; method names in the admin (review round 33).** The
  IČO / DIČ / IČ DPH labels of an address block in an order e-mail followed the language the worker last ran in, not
  the order's: a Slovak e-mail read "Company ID: / Tax ID:", a Polish one the Spanish "Número de registro:". They are
  now in the order's language in all seven languages. The order history shows "Admin payment" and "Admin status
  change" in the admin's language (an admin's own note stays as typed). The payment settings (countries and shipping
  limits) and the shipping rate selects name the methods instead of listing their codes (`bank_transfer`, `cod`,
  `gls`); the code stays the value and the tooltip.
- **Refunds, invoices and checkout details (review round 33).** An item refund (credit note) whose refund job ran out of
  re-queues after network errors (about 9 hours) stayed pending for good; the order page now offers "Release credit
  note" with a required note once no job of it is left and the provider never answered for it: it fails like a
  declined refund (credit note reversed, payment back to paid / partially refunded), audited with the admin. An IČ DPH
  that VIES answered is invalid is no longer stored on the order address, so the invoice does not print it as the
  buyer's VAT registration (checkout, API and manual order). Without JavaScript the checkout offers "Recalculate
  total" after the payment methods, so the summary shows the shipping and payment picked. The invoice e-mail goes once:
  a later `invoice_issue` job of the same order (shipped, paid) no longer mails the same invoice again.
- **Billing address details (review round 33).** On a shared computer a guest's unfinished checkout is no longer mixed
  with the saved address of the member who signs in next (the guest's name and city stood next to the member's
  street); the member's own draft is kept on reload. A saved billing address keeps the IČO and DIČ (migration
  `20261112100000_customer_address_company_ids`), so the next checkout fills the whole company; the account offers
  the same billing countries as checkout. A `billing_address` that is not an object and a field that is not UTF-8
  are refused at the field (422, also in the HTML checkout, which marks the empty billing fields). The address is
  formatted the same in the account, admin, delivery note, invoice and e-mails (country by name); the carrier label
  carries the company; a pick-up point (Packeta, personal pick-up) is named as the delivery address instead of the
  buyer's home address.
- **Downloads are taxed in the billing country (review round 33).** An e-service is supplied where the consumer lives
  or the business is established, which the buyer gives as the billing address; the checkout took the delivery country
  instead. An e-book for an Austrian consumer billed to Vienna paid 23 % Slovak VAT instead of 20 % Austrian under OSS,
  and a German business with a valid VAT ID paid Slovak VAT instead of reverse charge. Checkout (HTML and JSON), the
  cart, shipping and payment previews (`billing_country` parameter) and manual orders now place a download in the
  billing country when the order is billed elsewhere, else in the delivery country as before; goods stay taxed where
  they are delivered. The checkout form re-prices when the billing address changes while the cart holds a download.
  A business with a valid VAT ID of the seller's own state (a Slovak VAT ID on a parcel to Czechia) is no longer an
  OSS distance sale: it pays the seller's domestic VAT. Editing the lines of a reverse-charge order billed to another
  address no longer refuses with "VAT treatment changed": the VAT ID on the billing address is kept.
- **The OSS report counts a sale where its VAT is owed, not where it is billed (review round 33).** Since the billing
  address (round 31) the invoice names the billing party, and `cli/oss-report.php` took the consumption country from
  it: goods delivered to Germany and billed to a Slovak address charged German VAT but were reported as "SK 19 %", the
  seller's own state, and Germany's base was understated; their credit notes the same. The report now uses the country
  whose rate the order charged (an invoice without a financial snapshot: its delivery address).
- **Admin and storefront details from review round 32.** A disabled "Add to cart" (while the cart call runs, or a
  saved-for-later line that cannot be moved) is grey with a not-allowed pointer in both themes; it kept the active
  colour. The audit note of a payment recorded by hand ("Admin payment", with or without the admin's note) is in the
  admin's language, and the audit log is titled "Auditný záznam" / "Auditní záznam" in Slovak and Czech. The shipping
  page names the zones (the code, e.g. `near_eu`, stays the value and the tooltip), and the order detail shows the
  payment method's name with its code (e.g. `bank_transfer`) only as the tooltip, as the order list does.
- **Refund details from review round 32.** The `refund.updated` of a refund 2.0.x booked while it was still pending
  lists the charge's refunds and is recognised at once; it was answered with 503 for three days, with a warning, until
  Stripe gave up. "Retry refund" is no longer offered while the request's job still waits, runs or waits out a
  backoff in the queue (file, database and Redis queues), and a duplicate job of an earlier request no longer settles a later
  one or calls Stripe again. Setting "paid" by hand releases the refund request in flight, so a later credit note no
  longer finds it in flight for good. A refund job whose three attempts all failed on network errors is queued again
  with a growing delay (5 minutes up to an hour, about 9 hours in total, within the day Stripe keeps the idempotency
  key) before it fails and raises its incident; an item refund's job ended at once and its credit note stayed pending.
- **Billing address other than the delivery address (round 31).** The checkout (form without JS and JSON) has a box
  "Billing address differs from the delivery address" with name, street, city, zip and country; the buyer company and
  its IDs then belong to the billing address. The invoice, its credit notes and the bank-transfer payer use it, and the
  invoice also names the delivery address; carrier labels, delivery notes, shipping zones, pick-up points and VAT stay
  with the delivery address. The order e-mail, the merchant notification, the customer's order detail, the admin order,
  API v1 and the CSV export show both; a manual admin order can be billed elsewhere too. A signed-in customer's billing
  address is saved as a billing address and offered at the next checkout. GDPR export and retention include it.
- **`bbe-step` ignores root's PATH and stops on any signal to it (review round 32).** The wrapper looked up
  `setsid` and its other tools in root's PATH after changing into the application root, so an empty entry or `.` in it
  (`PATH=$EXTRA:$PATH` with an empty `EXTRA`) ran a `setsid` the owner put into the tree, as root. It now uses only
  `/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin`: install php there (or link it into
  `/usr/local/bin`), a php found only elsewhere in root's PATH is refused. A SIGTERM to the wrapper alone
  (`kill <pid>`, a supervisor, or `sudo` passing one on) now stops the step and everything it started like Ctrl-C does;
  it used to end only the wrapper. A step that put a newline into its process name no longer escapes Ctrl-C.
- **Invoice, texts and log details from review round 30.** A malformed BIC no longer goes into the invoice's payment
  QR (the e-mail's QR already left it out). A seller with only a VAT id shows it in the CS/HU/PL order e-mail instead of
  an empty line. The audit log translates the provider refund booked during an admin refund, two-factor setup and store
  permission changes; the import page shows row errors in the admin's language. Spanish e-mails address the customer
  as "usted", the German invoice calls the payment reference "Zahlungsreferenz" like the e-mail, and the unpriced-products
  warning uses the plural form of its count. The log also masks secrets written as a PHP array (`'pass' => 'x'`).
- **A declined admin refund stays released (review round 30).** After Stripe declined an admin refund, a later credit
  note made the payment `refund_pending` again and the declined request counted as in flight: the payment stayed
  `refund_pending` for good, the refund form was hidden and "retry" re-queued the declined refund (which Stripe could
  pay once it forgot the idempotency key, a day later). A refund declined or failed on an order a dashboard refund had
  refunded in full now returns it to `refunded`, not `partially_refunded`. A Stripe refund created within minutes of a
  refund that 2.0.x booked without its id is told apart by its place in the charge's refunded total; its
  `refund.updated` alone waits for the `charge.refunded` that lists the charge's refunds, so the old refund is no
  longer booked a second time.
- **"Buy later" details from review round 30.** Changing a variant's SKU in the admin no longer deletes every saved
  line of it: signed-in customers keep the line, a guest's line asks to choose the variant on the product page. A line
  of a draft or archived product is listed as unavailable with its own remove button (it counts towards the 100 lines,
  and was hidden). Signing in with a full list names the guest items that did not fit; signing in before the
  migration ran keeps the guest list until it has. "Nothing was moved" replaces "0 moved to your cart" when the cart
  already holds all the stock, and a failed move no longer says the item could not be saved for later.
- **Ctrl-C in `bbe-step` stops a step that runs as the tree's owner (review round 30).** When the tree belongs to
  another account (the usual case, the wrapper switches with `setpriv`), Ctrl-C or a dropped connection brought the
  prompt back while the step ran on in the background and kept changing files and the database: the wrapper killed
  only `unshare` and relied on the parent-death signal, which the kernel clears when the user changes. It now kills
  the step's pid namespace and process group itself, also without pid namespaces. The step runs in the application
  root instead of root's working directory, so a relative path in an argument is taken from there. Pruning after an
  update or rollback removes the private copies of the updater that a step killed at the wrong moment left in
  `finishers/`; only the one `.maintenance` names stays.
- **Translations and admin details from review round 29.** The PL/HU/DE/ES order confirmation no longer says
  "variable symbol" in English. The audit log shows the notes of pages, warehouses, feeds, store settings, credit
  notes, e-mail delivery and admin accounts in the admin's language, and the refund notes give amounts in the order's
  currency instead of cents. Slovak error counts use the plural ("2 chyby", not "2 chýb"). The setup checklist's
  e-mail item has its "Fix" button (Admin → E-mail delivery). A payment or shipping code saved in the admin keeps its
  underscores (`bank_transfer` stayed `bank-transfer` after saving). A product with no price in a store's currency
  (e.g. a PLN store) no longer shows its EUR catalog price on the product page, in listings or in the product's
  structured data.
- **Updater, mail and invoice fixes** (review round 29, lows).
  - Logs mask a bare `pass` key (`SMTP_PASS`, `smtp_pass`, `pass=`), which they used to write in clear.
  - `SMTP_HOST` can be an IPv6 address (`::1` or `[::1]`). It used to connect nowhere.
  - The order confirmation prints only the seller IDs the shop has, with no more empty "IČO: , DIČ:". New template
    variables: `seller_ids`, `seller_dic_sep` and `seller_ic_dph_sep`. The German and Spanish confirmations now also
    print the tax number (Steuernummer, NIF) when the shop has one. Admin-edited copies of the template keep the old line
    until they are re-edited.
  - The order confirmation and the payment reminder say that the payment QR code is attached and how to scan it
    (`{{bank_qr_note}}`).
  - Merchant order e-mails are written in `merchant_notifications.locale`, else the store's `default_locale`. They used
    to use the customer's language.
  - An invoice prints a payment QR only when the IBAN's check digits are valid.
  - Wrapping a very long word in an invoice line takes milliseconds instead of minutes.
  - A backup on a file system without file locks reports why it failed instead of ending with a fatal error, because its
    log is appended without the lock there. The operational log does the same.
  - A probe that could not check the themes (not started, refused to run as root, gave no verdict) is reported as
    "Theme checkout contract not verified … not a theme problem" instead of "would break checkout".
- **An interrupted update or rollback is finished by the updater that started it** (review round 29). A 2.1.3
  rollback restored `src/` before `src/Updater.php`: killed in that stretch, the command in `.maintenance` ran the new
  updater on the old `FileSystem` and failed on `FileSystem::tryLock()` every time (the shop stayed on 503); killed a
  little later, the old updater ran it and reopened the shop with files of both releases. Before its first change an
  update or rollback now copies the updater it runs (`src/`, `cli/update.php`, `cli/cli-only.php`) into
  `<update_storage_dir>/finishers/`, and `.maintenance` names that copy:
  `php '<update_storage_dir>/finishers/<snapshot>-…/cli/update.php' rollback --installed='<shop>' --snapshot=… --force`.
  Run exactly that command (as the same account as the step). The copy goes when the step completes; copies of a
  snapshot that no longer exists are removed with old snapshots.
- **Root no longer locks itself out of a tree root owns, and such a tree can be updated** (review round 29, finding 1).
  The first step there (install, migration, backup, restore) created `.maintenance.lock` 0660 root:root, and the
  stricter root check of round 28 (group write counts even for group root) then refused every later root step, worker,
  backup, migration and update included, blaming code in the empty lock; `setup.php install` as root failed half-way.
  The lock of a tree of group root is now 0600, one left 0660 by an earlier 2.1.3 build is accepted as the lock (a
  regular root-owned file with one name, which no step loads) and replaced by a 0600 one. The theme check of an update
  run as root in a shop root owns alone runs the new release's checkout probe as root on root's private copy (it
  refused root and told to run as the owner, which is root); `bbe-step` runs a step of such a tree as root after
  checking it from outside the tree (it refused every root-owned tree). A refusal for a root-owned tree now says how to
  make it root's alone (`chmod -R go-w`, or `tar --no-same-permissions` when extracting a release as root) instead of
  telling root to run as the owner. The README describes when a tree root owns is supported.
- **The e-mail delivery settings are in the backup; an empty `SMTP_*` variable no longer blanks the server; the invoice
  prints the BIC** (review round 28). `mail.json` (Admin → E-mail delivery) is a backup file source and a restore brings
  it back readable by the owner only (0600): a restored shop logged its mail instead of sending it. An empty `SMTP_HOST=`,
  `SMTP_PORT=` or `SMTP_ENCRYPTION=` (an `.env` line or a compose `${SMTP_HOST}` left unset) counts as unset, as Admin →
  E-mail delivery already showed it: it replaced the saved server with an empty host and port 0. An invoice to pay by
  transfer prints the seller's BIC next to the IBAN (for payments from abroad); the installer and Admin → Stores
  collected it for the invoices but it was never printed.
- **Root: the shop decides, the update directory counts, no group root, ACLs count** (review round 28, findings 4 and
  5, lows). `cli/update.php --installed=<shop>` run as root decided by the owner of the release tree (root) that it may
  stay root, then ran the shop's `config.php` and changed its files as root; it now switches to the owner of the shop
  (or refuses) before it reads anything of it, so the release tree must be readable for that owner. Root kept root in
  a root-owned tree without a look at the update directory, which in the private directory the web user writes held
  snapshots it could change: a rollback installed their files root:root and the next step ran them as root. Root now
  needs `update_storage_dir` to be root's alone as well, else the step is refused. A tree of group root (`www-data:root`)
  no longer leaves the step group 0 (it takes the owner's primary group, or refuses), and in a root-owned tree any group
  write counts as another account's (a POSIX ACL such as `setfacl -m u:www-data:rwx src/` shows only as these bits).
- **A rollback stopped half-way can be finished by the command `.maintenance` names** (review round 28, finding 3).
  Only the updater's own files waited for the end, not the classes they load: the rollback of 2.1.3 removed
  `src/AppConfig.php` and `src/FileOwnership.php` (new in 2.1.3) first, and stopped half-way, the named rollback and
  every `cli/update.php` failed with `Class "AppConfig" not found`, the shop stayed on 503. A rollback now restores the
  shop's other files first, then all of `src/` and every file the running updater loaded (`cli/update.php` last), then
  removes the files the older release lacks, and writes the version state last: one killed before it still names the
  newer version, so the same package cannot be applied over a mix of both releases. If such a command still fails
  with `Class "…" not found` (a rollback stopped by an older updater), copy the named file from the package's
  `payload/` into the shop and run the command again.
- **`bbe-step` gives the step nothing of root's: no terminal, environment or open file, and nothing outlives it**
  (review round 29, finding 3). The step kept root's terminal as stdin/stdout/stderr: a process it left running read
  what root typed after the prompt came back (`mysql -pHunter2` in the reproduction); Ctrl-C ended only the wrapper,
  and the step got root's environment and open files. Now stdin is `/dev/null`, stdout and stderr are pipes root's
  `cat` copies to the terminal, every other descriptor is closed, the environment is rebuilt (`PATH`, the owner's
  `HOME`, `BBE_*`, `BAREBONES_*`, `SMTP_*`, `MAIL_*`, `PAY_*`, `LANG`, `LC_*`, `TZ`), and the step is pid 1 of a pid
  namespace of its own (`unshare`), which the kernel empties when it ends; Ctrl-C, `kill` or a dropped connection stop
  it. The step keeps the tree's group only if its owner is a member (it took e.g. `disk`), and an owner without an
  account is refused. `bbe-step` needs bash; started as `sh bbe-step` it re-executes itself in bash.
- **Without file locks a step says so** (review round 28). On a private directory without file locks (some NFS/SMB
  mounts) the update, backup, migration and SQLite maintenance locks failed as "Another update process is already
  running", "lock acquisition timed out" or a maintenance "already in progress"; they now name the file system. A MySQL
  probe database left by a killed update or rollback is refused with the `DROP DATABASE` to run.
- **Root steps: the docs no longer promise a protection the tree cannot give, and a wrapper outside the tree does**
  (review round 28, findings 1 and 6). Round 27 called the root hole fixed by `cli/cli-only.php`, but that file is in
  the tree the web user may write, so a changed copy ran as root. New `deploy/bbe-step.sh`, installed root-owned outside
  the shop, switches a step to the owner of the application root (never group root) before any PHP of the shop runs and
  refuses a root-owned tree. It starts the step in a new session (`setsid`), so the step has no controlling terminal: a
  changed step can no longer push commands into root's shell with `TIOCSTI` for root to run after it ends (capability
  review of round 28; reproduced as uid 33 → `uid=0(root)` with `dev.tty.legacy_tiocsti=1`). The theme check of a new release started as root (by the 2.1.2 updater) now says "Not a
  theme problem: the updater runs as root" instead of failing as a theme that breaks checkout, and an update directory,
  `installed.json` or snapshot left by an update run as root is refused with the `chown` to run (was: "version unknown"
  and "no snapshots").
- **New: "Buy later" in the cart** (save for later, as Amazon's "Saved for later"). Each cart line has a "Buy later"
  button that moves it, with its variant and quantity, to a "Buy later (N)" list under the cart; each saved line shows
  its quantity and today's unit price and has "Move to cart" and remove, and the list has "Clear saved". Saved lines
  are not ordered: the cart total, item count, shipping, coupons and stock reservation do not include them. "Move to
  cart" checks today's price and stock: a changed price is shown (in the list and when moved), a sold-out line stays
  saved and says so. A guest's list is kept in the session and joins the account on login (a line in both is kept
  once); a signed-in customer's list is kept per store. The wishlist (favourites from the product page) is unchanged.
  Themes: `storefront/cart-saved` (included by `cart` and `cart-empty`). Review round 29: a product switched to draft
  for a while is hidden from the list, not deleted from it; only a product or variant that is gone is. Saving into a
  full list or a failed save says why; signing in with a full account list (100 lines) tells how many guest lines did
  not fit. With less in stock than saved, what fits moves to the cart and the rest stays saved. A sold-out line's "Move
  to cart" is disabled, and a product that has variants now links "Choose a variant" to its page.
- **`sudo php cli/restore.php` no longer leaves the shop a database it cannot open** (review round 27, capability review).
  A step started as root runs as the owner of the application root, and a restore gave the restored database, uploads
  and files their live owner and group only as root: in a deploy user's checkout the restored database of `www-data`
  belonged to the deploy user, the shop could not open it, and the restore reported success. A restore, files restore or
  update rollback of the SQLite database that cannot give the replacement the owner and group of what it replaces is
  now refused before anything changes, naming the file, its owner and how to run it (see the operations runbook).

- **A refund made at the provider while an admin refund is in flight no longer frees the money that refund promised**
  (review round 28). A Stripe dashboard refund (or another provider refund event) booked during an admin refund or a
  cancellation refund moved the payment from refund_pending to partially refunded: the request counted as settled, so a
  credit note or another refund could promise its money again, and the request's own result, no longer finding a pending
  payment, left a payment whose money was all returned "partially refunded". Such a refund is now booked while the
  payment stays refund_pending until the request settles; one leaving less than the request in flight raises the critical
  incident `payment.refund_overcommitted`.
- **An item refund (return, credit note) during an admin refund no longer frees the admin refund's money either**
  (review round 29). The item refund's job moved the payment out of refund_pending, so the admin could request the same
  money again and the customer was refunded twice, with no warning. Its money is now booked while the payment stays
  refund_pending until the admin refund settles. This holds also for an item refund of exactly the amount in flight
  (capability review): its own ledger row, under the credit note's key or refund id, never counts as the admin
  refund's, with or without a provider refund id (it matched by amount and released the request).
- **A refund Stripe declines is released at once** (review round 29). A definitive decline (HTTP 4xx other than
  401/403/409/429: already refunded, amount above what is left, …) was retried until the job died, and the order stayed
  refund_pending; after a dashboard refund the admin could neither retry it nor request a new one. The request is now
  released (the payment returns to paid or partially refunded, critical incident `payment.refund_failed`) and a declined
  item refund is marked failed with its credit note reversed. A refund no job executed keeps its "retry" button after a
  dashboard refund.
- **A Stripe refund booked by 2.0.x–2.1.2 is not booked again** (review round 29). Those versions booked a
  `charge.refunded` as the change of the charge's refunded total under the payment id, without refund ids. Since refunds
  are booked by their Stripe refund id, the next `charge.refunded` of that charge (e.g. after a new dashboard refund; it
  lists all of the charge's refunds) booked the old refund's money a second time: 10.00 + 5.00 refunded, 25.00 on the
  ledger. A refund without the shop's `bbe_refund_key` that Stripe created before the last such booking is now
  identified against that money, oldest first (`refund_confirmed`, no money); refunds created later are booked. Orders
  already affected show a refunded amount above what left the Stripe account in the admin's payment history.
- **Stripe webhook events are documented** (review round 29): `docs/OPERATIONS_RUNBOOK.md` → *Stripe webhook* lists the
  endpoint, the secrets it needs, the seven events to select, what each does and which answers Stripe retries.

- **Download links, the invoice e-mail and a manual order's payment link are sent once a misconfigured mail provider is
  fixed** (review round 27). Written to the log only because `MAIL_ADAPTER` named no usable provider, they reached
  nobody and were never sent again (the download links were even marked delivered). They are now tried again like order
  mail: every 30 minutes, at most 48 times; logged download links are revoked and new ones mailed. With `MAIL_ADAPTER=log`
  on purpose nothing is tried again.
- **"Back in stock" notices are retried once per request and not refused after a provider fix** (review round 27).
  The recipient limit (10 an hour) counted the copies only written to the log while the mail provider was
  misconfigured, so the real notice was refused once it was fixed; such copies no longer count for a real provider
  (with `MAIL_ADAPTER=log` on purpose they do). A notice held back by that limit (`rate_limited`) was taken as
  delivered and its request closed; it now stays and is tried again after the window. A restock while a notice waited
  started a second retry series; the series now lives on the request (`mail_tries`, `next_try_at`). With
  `MAIL_ADAPTER=log` on purpose the notice is not retried (was: 48 tries), as order mail.
- **Security: a step run as root no longer works as root in files the web user can change** (review round 27; the
  limits are in round 28 below). The web user can write the
  application root, and steps run as root worked in it: `DataOverrides::atomicWrite()` (the maintenance marker, the
  data directory's `stores.json`, the update key) chmodded its temporary file by name to the mode of the file it
  replaced, so a planted 0666 `.maintenance` and a symbolic link to `/etc/shadow` swapped in for `.maintenance.*.tmp`
  made `/etc/shadow` 0666 within seconds; `MaintenanceMode::restore()` dated the marker back with `touch()`, which
  creates the target of a dangling link; and a `src/` the web user replaced ran as root. For every step at once,
  `cli/cli-only.php`, the first file each CLI step loads, runs a step started as root as the owner of the application
  root for good (its groups too; no way back to root) before any code of `src/` is loaded, and refuses one in a
  root-owned tree another account can write. This holds only while `cli/` itself is not changed: an account that can
  write the tree can change `cli/cli-only.php` or replace `cli/`, and root runs its copy. Root must never run PHP of a
  tree another account can write (see the upgrade notes and `deploy/bbe-step.sh`). Besides, the temporary file is created exclusively with its final mode
  (no chmod by name; `stores.json` 0640 the same way), a marker put back keeps its age through its `since` (no
  `touch()`), root opens `.maintenance.lock` only in an application root no other account can write, a step refuses a
  lock that holds anything but a holder record (a `.env` renamed to that name was emptied), and the claim file without
  file locks belongs to the application root's group. CI runs these root paths as root with `/proc` hidden.

- **A rollback no longer reopens the shop over a half-done restore or rollback either** (review round 27). Only a
  migration stopped half-way was recorded with an update's snapshot: one taken over a restore of the database or a
  rollback left half-done held that state too, and once that step was run again and opened the shop, the rollback of
  the snapshot put the half-done state back and reopened the shop over it. A snapshot now records every failure of the
  window it was taken in, a manual `cli/update.php backup` too, and its rollback keeps the shop closed until the
  restore or the named rollback is run again (a restore of uploads only does not come back: snapshots hold no uploads).
- **A rollback no longer reopens the shop over a half-migrated database, and a killed SQLite restore recovers**
  (review round 26).
  - An update that completed a migration stopped half-way reopened the shop, but its snapshot held that half-migrated
    database; a later `cli/update.php rollback --snapshot=...` of it restored that database and reopened the shop. The
    snapshot now records the failed migration, and its rollback keeps the shop closed until `php cli/migrate.php apply`
    (or an update that migrates) completes it.
  - A restore killed between moving the live SQLite database aside and moving the restored copy in left no database:
    the shop and every later restore stayed refused until the copy was moved back by hand. The next request or step
    now moves the copy (`<db>.pre-restore-*.rollback`) back once the killed process is gone. The shop stays closed for
    the interrupted restore, which can be run again.
- **Security: the maintenance step lock no longer lets the web user take over root's files** (review round 26). Run as
  root, `cli/restore.php`, `cli/update.php` and `cli/migrate.php` opened `.maintenance.lock` following a symbolic link,
  then gave the file it pointed at to the owner of the application root (0660) and emptied it. The web user can write
  the application root, so `ln -s /etc/shadow .maintenance.lock` handed it `/etc/shadow` at the next update. The lock
  is now created exclusively, by root as the owner and group of the application root; an existing one is used only
  when it is a regular file with a single name, and a symbolic or hard link there refuses every step with a message to
  check and remove it. No step changes the owner or mode of a file it did not create: a lock the application root's
  owner and group cannot use, or that other accounts can open (0644 from before 2.1.3, one a deploy account made for
  itself), is replaced by a new 0660 one once it is held, by whichever account runs the next step; an account that
  cannot share a new lock with the application root's owner or group is refused with what to run instead. Root steps
  no longer need `/proc` (FreeBSD, macOS, containers with a masked `/proc` failed every root step with an uncaught
  exception). On a file system without file locks the steps exclude each other again, by a claim file
  `.maintenance.lock.held` (one left by a killed step on this host is taken over; one from another host is honoured for
  a day). A refused step prints the lock holder's record as plain text only. CI now also runs the step lock test as root
  with `/proc` hidden (`cli/test.php --only=<scenario>`; `BAREBONES_REQUIRE_ROOT_TEST=1` fails instead of skipping when
  it is not root), so the root path is executed and not only checked in the source.
- **A mistyped `MAIL_ADAPTER` no longer breaks mail** (review round 26). A value of an invalid format (`smtp.`) threw
  for every mail — a password reset, an e-mail verification, an order mail — instead of writing it to the log with an
  incident as R25 did for a provider that is not installed; one with a trailing space (`smtp `) threw while the admin
  showed smtp. The value is now trimmed, and any invalid one writes the mail to the log and raises
  `mail.provider_invalid`. An order mail written to the log that way is tried again every 30 minutes for a day, so it
  goes out once the setting is fixed. Mail only written to the log is no longer counted or shown as sent (password
  reset, e-mail verification, guest account link, back-in-stock confirmation and notification, stock alerts, the
  admin's payment link). A back-in-stock notice only written to the log keeps the shopper's request and is tried again
  every 30 minutes for a day, with a `back_in_stock.mail_logged` warning in the log, so the shopper is told once the
  setting is fixed (the request used to wait for the next restock, which does not come while the product stays in stock).
- **Order e-mails always go through the mail provider configured now** (review round 25). An order placed while the
  shop used SMTP still mailed real customers after the shop was switched to the log provider (a staging copy of the
  database), and one placed under SES failed every attempt once the shop moved to SMTP. Every order e-mail now goes out
  through the provider set in Admin → E-mail delivery (or `MAIL_ADAPTER`) when it is sent. A `MAIL_ADAPTER` naming no
  installed provider writes the mail to the log and raises a critical `mail.provider_invalid` incident instead of
  failing every order e-mail. Mail the log provider only wrote down no longer reads as sent anywhere: the withdrawal
  and complaint confirmation pages and admin screens, merchant notifications, the admin "resend" message and the GDPR
  export say "logged".
- **A failed restore, update or rollback no longer reopens a shop left half-done** (review round 25). A
  `cli/restore.php` that failed before it replaced anything (a corrupt backup, a refused files archive) lifted the
  window an earlier interrupted restore had left, and the shop reopened on a half-restored database or files; it now
  puts that window back as it was, and only a restore that completes lifts it. The command the window names is the one
  that ran, with `--force` and `--i-stopped-writers` (run again without them it was refused). A restore that leaves
  pending migrations (an older backup) keeps the shop closed until `php cli/migrate.php apply` has run. An update or
  rollback started over a failed window (a migration stopped half-way) carries that failure on: killed, its rollback no
  longer reopens the shop on the half-migrated schema — also when that rollback restores the database, since the
  update's snapshot was taken after the migration failed (`before_snapshots`). Which step lifts which failure is read from fields of the
  `.maintenance` marker (`restore_scope`, `rollback_snapshot`, `pending_other`), no longer from its text, so a
  restore's "earlier: … --snapshot=X" no longer lets a rollback of code only of X reopen it.
- **E-mails of orders placed before SMTP was set up are delivered** (review round 24). Checkout records the mail
  provider with the order. An order placed while the shop only logged mail (the default before SMTP or SES is set up)
  kept sending every later e-mail (paid, shipped, invoice, refund) to the log, and the admin showed it as sent. Now
  such an order's e-mails go out through the provider configured when they are sent, as does one whose provider is no
  longer installed. In the order list and order detail an e-mail that was only written to the log reads "Logged (not
  sent)".
- **An interrupted restore no longer keeps the shop closed for good** (review round 24). A `cli/restore.php` that was
  killed or failed half-way leaves the shop in maintenance with the restore to run again. That run completed, then put
  the earlier failure back, so the shop stayed closed whatever was run next. Now a successful restore of any backup —
  the same one, another one or the `pre-restore-snapshot` — lifts it and reopens the shop when it replaces at least
  what the failed restore was replacing (the marker records `restore_scope`: the database, the files or both). A
  database-only restore after an interrupted `--files`/`--files-only` restore, or a failed window of a migration or an
  update, stays, and the restore says so — also when a restore was killed or failed half-way on top of it (the
  marker carries that failure on as `pending_other`, and the restore that completes it puts it back).
- **A Stripe refund is booked once, by its Stripe refund id** (review rounds 24–27). A `charge.refunded` event without
  its list of refunds (when the Stripe API could not list them either) booked the refunded total at once. When that
  money was the shop's own refund in flight (an item return with its credit note, or a refund requested in the admin)
  the order got a second credit note and the refund job's result booked the same money again; refunds reported
  together, a dashboard refund next to an item refund, or a refund Stripe failed later left the refunded amount off
  what Stripe returned. Now such an event books nothing: the webhook answers 503 and Stripe delivers it again (with
  backoff, for up to three days), logging a `payment.webhook_deferred` warning each time; a Stripe API error while
  listing the refunds is handled the same way (was: a 500 with a critical incident). Every refund is booked by its
  Stripe refund id — from the event's refund list, `refund.updated` or the refund job's result, whichever comes
  first — so the ledger holds exactly what Stripe returned. Stripe refunds carry the shop's refund key in their
  metadata (`bbe_refund_key`): one reported before the job's result is booked as that refund (an item return keeps its
  single credit note, an admin refund gets one, and the job's result only confirms it). A refund Stripe answered
  "pending" and failed later is never booked, also when its `refund.failed` arrives before the job's result; its item
  refund is released and its credit note reversed. Orders already affected under 2.1.2 show two credit notes for one refund in their Invoices, and a refunded amount above what
  left the Stripe account: have the duplicate credit note corrected with your accountant.
- **Admin language** (review round 23, UX). The dashboard's setup checklist titles its checks in the admin's language
  (they were the doctor's English text; an unknown check still shows that text) and counts "1 problem", not
  "1 problems", in every language. The audit log shows the notes it writes ("Created in the admin", refunds, status
  changes, …) in the admin's language; stored entries are unchanged. Attribute types and payment methods in the admin
  forms follow the admin language (they were Slovak everywhere). The web installer asks for the seller's BIC, company ID
  (IČO) and tax ID (DIČ), checked like in Admin → Stores.
- **A migration can no longer open the shop in the middle of an update** (review round 24). `cli/migrate.php apply`
  and `cli/update.php` each checked that no other step held the maintenance window and then entered it; one starting
  in between could enter as well, and a migration ending first lifted the window of the update still running. Update,
  rollback, `cli/migrate.php apply` and `cli/restore.php` now share one step lock (`.maintenance.lock` in the
  application root, next to `.maintenance`), taken before they look at the window: while one runs, the others are
  refused with "Another maintenance window is open" and change nothing. The user that runs these commands creates the
  file and needs write access to it, as to `.maintenance`; the updater never replaces it.
- **The maintenance step lock works for root and the web user alike** (review round 25). `.maintenance.lock` was
  created 0644 by whoever ran a step first. Made by root, it shut the web user out (cron or a container running as
  www-data: "Cannot open the maintenance step lock", every migrate, restore, update and rollback failed for good), and
  any local account could open it to read and lock it, holding off every step with an empty reason. It is now created
  0660, and a step run as root hands it (also an existing one) to the owner and group of the application root. A step
  refused while another runs names the process that holds the lock. On a file system without file locks (NFS without
  lock support, CIFS `nobrl`) the steps were refused for good; they now run with a warning that they must not be
  started together. If www-data still cannot open the file, the error says its owner and mode and how to fix them.
- **Payment QR in the payer's standard** (review round 23, UX). The bank-transfer QR was PAY by square only, the
  Slovak standard: a German customer's banking app could not read it, and a CZK order had no QR at all. Now a CZK
  order gets the Czech SPAYD (QR Platba), a EUR order from a Slovak address keeps PAY by square, and a EUR order
  from anywhere else gets the SEPA EPC code (GiroCode). An order without an address follows the store's country.
  The order pages show it with a label and hint for its format, the order confirmation and payment reminder e-mails
  attach it as a PNG (mail clients do not show inline SVG), and an invoice still to pay by transfer prints it
  beside the IBAN. The JSON `bank_transfer` block adds `qr_format` and `qr_payload`, and `pay_by_square` is set
  only for that format.
- **E-mail delivery** (review round 23, UX). SMTP could be set only in environment variables, which most shared
  hosting cannot set, so the shop only logged every e-mail. The SMTP server, its login and the sender are now entered in
  Admin → E-mail delivery (with a test e-mail) or in the web installer's optional "Sending e-mail" fields. They are
  stored in `mail.json` in the private data directory (mode 0600), the password encrypted with a key derived from
  `admin_identity_key` and never shown again; a login without TLS/SSL is refused. The environment (`SMTP_*`,
  `MAIL_FROM`, `MAIL_ADAPTER`) still wins and its fields are shown read-only; the saved values win over the `smtp_*`
  keys of `config.php`, and `setup.php doctor` reports the provider really in use. The merchant's order e-mails print
  the payment method and state in the e-mail's language ("Kartou online — Zaplatené", not "paid" or "— pending") and
  link the order in the admin; the invoice e-mail of a paid order says it is paid instead of asking for an amount due
  by a due date; the bank transfer e-mails show the BIC. The e-mail log names the sender, Reply-To and attachments and
  keeps an `.eml` copy of the last 50 messages in `logs/emails/`.
- **Sample stores in other currencies sell the seeded products** (review round 23, UX). `cli/seed.php` creates a
  price list in each sample store's currency (cz CZK in whole korunas, pl PLN, hu HUF, us USD; fixed sample rates)
  and prices every seeded product in it; these stores used to sell none of the seeded EUR products. A second seed
  run reuses the lists. Saving a public store whose products have no price in its currency shows a warning with the
  count, examples and where to price them (Admin → Price lists); it saved silently. Docs: the README said such
  products "stay in the catalog currency" (they are not for sale in that store), the promotion hint asked for
  amounts in cents in every language (they are typed in the currency, e.g. 4.90), and the doctor pointed to a
  non-existent Admin → Pricing section.
- **Security** (review round 23, low). The CSP report endpoint counts an IPv6 reporter per /64 network: one client
  that took a new address of its /64 for every forged form-action report counted as several reporters and raised
  the critical alarm alone (IPv4 still counts per address, an IPv4-mapped address as that IPv4 address). The log
  masks `pwd=…`, `private_key` / `private-key` values and an `Authorization` header without a scheme
  (`Authorization: <token>`), which reached the log in clear (T15). PayU: the new `payu_signature_algorithm =
  'sha256'` (`PAY_PAYU_SIGNATURE_ALGORITHM`) refuses MD5-signed notifications once the POS signs with SHA-256; until
  it is set `cli/doctor.php` warns that MD5 is still accepted (T20, README).
- **API, time and retention fixes** (review round 23, low).
  - `POST /products`: two concurrent creates of one SKU both passed the SKU check on MySQL, and the second overwrote
    the first product while still answering 201. An API create now only inserts; the slower request gets
    `409 sku_exists`. The OpenAPI spec now lists `PATCH /products/{id}` → `422 price_below_variant_floor`.
  - A customer's VAT ID validation time (`vat_validated_at`) is stored in UTC; it was local time read as UTC.
  - `checkout_sessions` were never deleted, and every order lookup that shows a payment link added a row. The daily
    cleanup job now deletes lapsed payment and account links, pay-again claims and confirmation tokens older than
    `checkout_confirmation_days` (90); gateway sessions stay. An order keeps its newest five page payment links.
  - Back-in-stock requests past their retention (or never confirmed) were deleted only when a SKU was restocked;
    the daily cleanup job deletes them too.
  - Feed sync times in the admin are shown in the shop's time zone (they were UTC).
  - A return-window test failed between 00:00 and 00:59 in the two weeks before the autumn clock change.
- **Snapshot pruning leaves no hole in the rollback chain** (review round 23, low). Code-only and database snapshots
  are counted apart, so pruning could delete a code-only snapshot between two kept ones; rolling back from the newest
  then reached the older snapshot and left the deleted update's files in place, a mix of releases. Every update still
  installed above the oldest kept snapshot now stays; rolled-back snapshots and the chain below the oldest kept one
  are pruned as before.
- **Root updater and restore can no longer be tricked into giving away a hard-linked file** (review round 23, low,
  G5). Run as root, they gave a staged copy the owner of the file it replaces with `lchown` on its name, in a directory
  the web user can write to. With `fs.protected_hardlinks=0` the web user could hard-link e.g. `/etc/shadow` under that
  name between the inode check and the call. The owner is now set through the still open file (`/proc/self/fd`);
  without `/proc`, only when the kernel protects hard links, otherwise the update or restore refuses.
- **Stores on their own domains** (review round 23, medium). The store switch and the hreflang links built every
  store's URL from its prefix on the current host: with the Czech store on its own domain, "CZ · Kč" on the Slovak
  site opened the Slovak store in euros, and `hreflang="cs"` pointed to the Slovak host. A store with `domains` is now
  linked on its first domain (app_url's scheme and path), both from the switch and in hreflang, x-default and the
  canonical URL; the stores without a domain are linked on app_url from it. The sitemap of each host lists the URLs of
  the stores served there and links every alternate on its store's domain (it listed all stores on one domain).
  Two stores on their own domains may now both use the empty `url_prefix`: the Czech version of the Slovak store
  (`cs-SK`) was dropped from hreflang and the sitemap when they did.
- **Carrier labels of a cancelled order** (review round 23, medium). Cancelling a whole order cancelled its planned
  parcels in the shop only: their labels stayed active at the carrier, cash on delivery included, so the carrier still
  picked the goods up and collected. The cancellation now queues the job `shipping_labels_cancel`, and the worker
  cancels the labels of the cancelled parcels (and a label of the order itself when nothing shipped) at the carrier.
  A label the carrier does not cancel is a critical incident, e-mailed to the merchant when incident alerts are on, and
  stays listed in red on the admin order until it is handled. Low: cancelling a parcel now takes the order lock, so on
  MySQL a label created at the same moment can no longer stay active on the cancelled parcel; a label the carrier
  returns after the whole order was cancelled is cancelled at the carrier instead of being recorded as active.
- **Refunds** (review round 23, medium). After the provider declined an item refund the payment stayed `refund_pending`
  for good and the next refund from the admin was refused; it now returns to paid (or partially refunded) when no
  other refund is in flight. The numbered credit note of a failed refund is no longer cancelled (it may be in a VAT or
  OSS return already filed): a reversal of it is issued in the credit note series, with its own PDF, and the OSS report
  adds it back in its own period. Low: with three or more VAT rates a refund credit note no longer credits a cent more
  at one rate than the invoice has left there; a refund booked without a gateway refund id (GoPay, ThePay, Comgate)
  gets its credit note at once instead of waiting 30 minutes for an unrelated item refund of the same amount and failing.
- **Feeds** (review round 23, medium). A CSV supplier feed's price of only spaces is a missing price: an existing
  product keeps its price (it became 0) and a new one is not created ("Missing required field: price" in the sync
  status; it was created free and the sync said "ok"). A control character in a product name (`\x01`, `\x0B`, e.g. from
  a CSV import) is left out of the Google feed; it made the whole feed invalid XML and Google refused every item. A
  simple product with no price in the store's currency (a HU store of the shared EUR catalog) is no longer offered in
  that store's feed; it was "in stock, 10.00 EUR" and the cart refused it. Low: a feed's link to a variant that cannot
  be bought now opens the page on that variant at its price (it showed another variant's), and the XML sitemap escapes
  quotes (a CSV slug with `"` broke it).
- **A killed update, rollback, migration or restore keeps the shop closed** (review round 23, medium). `apply`,
  `rollback`, `cli/migrate.php apply` and now `cli/restore.php` mark their maintenance window failed, with the command
  to run, right before their first change. A process killed half-way (SSH dropped, OOM killer, a migration timeout)
  runs no clean-up: its plain marker used to expire after an hour, and the shop and worker then ran a mix of old and
  new files over a half-migrated database. Such a window now stays until the named rollback, migration or restore
  succeeds. The job worker checks maintenance before every pull and every job, so a run that started just before an
  update stops within seconds (it went on for up to 55 s) and closes its SQLite connection, which had made the
  automatic rollback after a failed migration refuse. `cli/migrate.php` and the updater refuse while another process
  holds the window open (an update or migration that is running) instead of working under it and lifting it at the
  end; `cli/migrate.php` lifts only its own marker. `cli/restore.php` closes the shop while it replaces the database
  and files, refuses while an update or migration holds the window, and keeps a failed window it found. A failed
  window is written before the lock on the old marker is let go. Whether a window is still running is told by its
  lock, not its failed flag: a step that is changing files or the database marks its own window failed, and that
  window had been taken for one left by a dead run, so a restore, migration or update went ahead in the middle of
  it. Only a failed window that no process holds is taken over now, and an update, rollback or restore lifts the
  window at the end only while the marker is still its own.
- **Invoice, admin and texts** (review round 22, low). A long item name on the PDF invoice wraps inside its column and
  is printed in full; it was cut at 32 characters and, after round 21 narrowed the column, capitals still ran into the
  quantity. Text width now follows Helvetica's character widths, and a word wider than its column is split. On a 390 px
  phone the refused-rows list, the feed's import-mode select, promotions and digital assets no longer scroll sideways
  (form controls stay inside their cell, long paths and values wrap, the digital-assets table scrolls in its own box).
  The audit log names payment events in words ("admin.paid" reads "Set in the admin: Paid", "refund.requested" reads
  "Refund requested"), and amounts inside logged values (an order's `total`, a parcel's `cod_amount`, a credit note's
  `gross`, a product's `price`) show in the record's currency, not in cents. Re-queued and failed refund notes are
  written in money, and older ones are read that way. The English order e-mail asks for "this amount and payment
  reference" (it still said "variable symbol").
- **The store switch keeps the language, the sitemap lists every language version** (review round 22, low). The
  store switch opened every store in its own language: a shopper reading the HU store in English landed in the SK
  store in Slovak. A store that has the shopper's language now opens in it (`?lang=en`, also Slovak in the CZ store);
  one that does not opens in its own. The sitemap listed only each store's default language, while the page's
  hreflang also announces the further store languages (`cs-SK`, `en-HU`, `es` of the /en/ store): each is a `<loc>`
  of its own and an alternate of every entry, with the same URL as the page's hreflang (`/hu/…?lang=en`).
- **Feed messages only where a price was kept, `decimal` checked, the feed status translated** (review round 22,
  low). "Price kept" was listed for SKUs that are not in the catalog (skipped anyway in "price and stock") and when the
  price was updated and only the compare price was refused, which filled a supplier feed's error list. A SKU not in
  the catalog gets no message, "price and stock" (which never writes a compare price) does not report one, and a
  refused compare price says "compare price kept". Saving a feed refuses a `decimal` other than `","` or `"."`, or one
  on a mapping that is not `price_to_cents` (it was saved and refused every price at the next sync); the README
  documents it under "Supplier feeds". A CSV import's ambiguous price no longer advises declaring the feed's decimal
  mark (a CSV import has no such setting); a feed's still names `decimal`. With `"decimal": ","` a `0.500` was read as
  500,00: grouped digits never start with a zero, so it is refused. The feed status and the refused items are shown
  in the admin's language (they were English in the SK and HU admin); CLI and logs keep the English text.
- **No off-site link from the store switch, error messages of bounded length** (review round 22, low). On
  `/cs//evil.example/` (a 404) the store switch linked the SK store as `//evil.example/`, a protocol-relative link to
  another site: the store prefix was cut from a path with a doubled slash (older, round 21 extended it to every store
  with a prefix). Repeated slashes in the request path are collapsed, and a path left after the prefix never starts
  with `//` or `/\` (store and language links, canonical, hreflang). An import, feed or ERP sync error quoted the
  refused value whole: a feed with a 1 MB price made a 9 MB admin page. The value is quoted with at most 60 characters
  and its length, a SKU with 64, each error line with 300 (`Str::clip()`), and each line of an admin message too.
- **Rollbacks newest first, the updater restored last** (review round 22, low). A rollback stopped half-way had already
  put back the old release's `cli/update.php` and `src/Updater.php` (the snapshot's file order); the next rollback ran
  that updater, which did not know the failed window and opened the half-restored shop. The updater's own files are now
  restored after every other file, the version state and the SQLite database, so a rollback stopped at the state or
  the database step also leaves the updater that wrote the window. A rollback of a snapshot while an update applied after it is still installed, or
  half-applied after its automatic rollback failed, restored only its own files next to the later release's others;
  it is refused before it changes anything and names the rollback to run first. `update_snapshots_keep` no longer
  deletes the snapshot whose rollback a failed `.maintenance` names (an update during that window could delete it, and
  the shop could not reopen). Snapshots record `created_us` to order those of the same second.
- **Admin and documents** (review round 21, low). Audit-log refund notes and the amount a Stripe refund webhook
  records were in cents ("Manual refund of 1647000 completed by …" for 16 470 Ft): they are written in the order's
  currency, and older entries are shown that way in the audit log. Cash on delivery of an order shipped in several
  parcels: each parcel collects its own goods and the last one the rest of the total (shipping, fees); the first parcel
  no longer carries the whole COD amount (the label, the admin parcel row and the carrier portal amount agree). The
  bank-transfer reference in the order and reminder e-mails is labelled like the storefront in each language: English
  "Payment reference", not the Slovak/Czech "Variable symbol" (German, Spanish, Hungarian and Polish had calques too).
  The PDF invoice items table gives the item column 12 mm less, so "Unit price excl. VAT" (and "Einzelpreis netto",
  "Precio unit. sin IVA") fits on one line.
- **A half-done rollback keeps the shop closed until that rollback succeeds** (review round 21, low). After an update
  whose automatic rollback failed, or a rollback that stopped half-way, a completed rollback of code only of another
  snapshot reopened the shop although `.maintenance` still named the rollback that was due. Such a window is now lifted
  only by the rollback it names; any other update or rollback keeps the shop closed and warns. `update_snapshots_keep`
  counts the snapshots of updates of code only (no database) apart from those with a database: three such updates,
  or their rollbacks, deleted the only database snapshot. `README.md` and the `MaintenanceMode` comment say which step
  lifts which window and that a killed updater's marker blocks for an hour.
- **Store switch apart from languages, one URL per store language** (review round 21, low). After round 20 the SK and
  CZ stores no longer linked each other ("CS" in the SK store was Czech in euros), and "HU" in the switcher moved the
  shopper to the HU store and repriced the cart in forints unannounced. The language links now list only the current
  store's languages; the other stores are a separate store switch named by country and currency ("CZ · Kč", the store
  name as title; theme variable `store_links`, `.store-switch` in the default and bookshop headers). A shopper whose
  cart arrives in a store of another currency is told once ("Your cart is now in HUF"). A further store language that
  is another store's own (`/product/x?lang=cs` in the SK store, English in the HU store) is canonical to itself (it was
  the store's default-language page, Slovak) and is announced as hreflang language-region by the store's seller
  country (`cs-SK`, `en-HU`; `Bootstrap::hreflangAlternates()` returns them as `regional`). `canonicalSecondaryLocale()`
  is removed: use `secondaryLocale()`. Default theme 2.3.6, bookshop theme 1.0.1.
- **Admin, documents and languages** (review round 20, low). A withdrawn line of which only some units left (1 of 3)
  says how many were sent and that the rest must not ship; it read "already sent". The invoice's VAT scenario in the
  order detail and the status values in the audit log ("shipped", `{"status":"draft"}`) are shown by their names. The
  PDF credit note labels the corrected invoice's date apart from its own ("Dátum vystavenia faktúry"), and an items
  header label wider than its column wraps (the English "Unit price excl. VAT" ran into "Qty"). The shipping and payment
  methods seeded into the CZ, HU and PL stores are named in the page language on a page in another language ("GLS
  futár" on an English page); in the store's own language and once renamed they stay as stored. The checkout totals
  that `storefront.js` rewrites use the shop's price format (`I18n::price`, now also in the page config as
  `priceFormat`): "HUF 7,980" stood next to "5 490 Ft". Default theme 2.3.4.
- **One welcome e-mail per account** (review round 20, low). Every further confirmation link of an account already
  confirmed sent the welcome e-mail again; now only the link that confirms the address does. Tests: the web worker
  test's `php -S` server inherits the suite's environment, so it no longer opens the developer's `config.php` and
  database.
- **A rollback of code only keeps a failed migration's window** (review round 20, low). Like an update of code only,
  a rollback that restores no database leaves the schema of a `php cli/migrate.php apply` stopped half-way as it was,
  so the shop stays closed and `update.php rollback` warns (it reopened the shop). A rollback that restores the
  database still lifts the window. `README.md` and the runbook say which step lifts which window, and the upgrade
  note on a MariaDB dump client says that the 2.1.2 updater refuses it only when `categories` or `invoices` hold rows.
- **Feed and sitemap cache** (review round 20, low). The catalog fingerprint read every product row (a count and the
  round 19 price sums) on every feed and sitemap request, also when the file came from the cache: about 0.1 s on SQLite
  and 0.7 s on MySQL with 200 000 products. It now reads only indexes (the newest product id and `updated_at`); a
  product deleted in the admin marks the catalog changed. A product renamed, repriced or taken off sale in the same
  second as another change (`updated_at` has whole seconds) reached the feed only after the 15-minute cache time: an
  entry built in the second of the latest change is now rebuilt once that second is over.
- **The language switcher stays in the store** (review round 20, medium). In the HU and CZ stores the EN link led to
  another store (`/?lang=en` or `/en/`) and the cart was repriced in its currency (5 490 Ft became 31,67 €). A language
  the current store offers in `languages` now opens the same page of the current store (`/hu/?lang=en`), with the same
  cart and currency; only a language the store does not offer leads to the store that has it. English chosen in the HU
  store also stays on the next page: links, forms and the storefront API keep `?lang=en` even though English is the
  `/en/` store's own language (the canonical URL still leaves English to that store).
- **Import and feed prices: no guessing** (review rounds 20 and 21, medium). Rounds 19 and 20 were not released: 2.1.2
  read one separator always as the decimal mark (see the upgrade notes). The round 19 rule read one separator
  before three digits as digit grouping in every currency, so an ERP exporting three decimals had "12.500" stored as
  12 500,00 € and "99.990" as 99 990 €, without a warning; reading it as decimals in EUR only (round 20) turned a German
  or Polish "2.000" into 2,00 € and still grouped "199.000" in CZK into 199 000 Kč next to "1299.000" = 1 299 Kč. Such a
  value ("199.000", "2.000", "12,990", "1.234") is now refused in every currency with a row or feed item error, as the
  admin price field refuses it; so is a part finer than a cent ("0.125", "12.3456"). Unambiguous prices are read as
  before ("1 299,00", "1.299,00", "1,299.00", "12,90", "1299", "1299.000", "0.500"). A feed declares its price format
  with `"decimal": "."` or `","` on its `price_to_cents` mapping (`{"field": "price", "transform": "price_to_cents",
  "decimal": "."}`): "12.500" is then 12,50 (or 12 500 with `","`) without an error. A feed item whose price is refused
  keeps the product's price but still syncs its stock (a stock-only feed no longer reads prices at all), a new product
  is then not created, and refused prices do not count towards the 100 item errors that stop a sync (400 items with
  four-decimal prices updated the stock of 51). The feed status says `partial: N errors (M prices refused)` or
  `stopped after N errors` instead of `ok with N errors`, and the admin's "Sync now" reports such a sync as an error.
  The reading no longer depends on any currency, so an XML feed and a CSV file read a price the same way whatever the
  currency of the product or its catalog (round 20 used the catalog's currency in feeds, the product's in CSV).
- **Import, feed and ERP sync details** (review round 21, low). The admin saw only how many rows a CSV import or a
  feed sync refused: the message now lists them with the reason (the first 20), and the feed page keeps the item
  errors of the last sync, also a scheduled one (migration `20261107120000_feed_last_errors` adds `feeds.last_errors`).
  A product row is stamped when written, not when committed: a public feed rebuilt during a long sync (MySQL serves
  the old rows until the commit) after a later admin edit kept that edit's `updated_at` as its fingerprint, and the
  synced prices reached the feed only after its 15-minute cache time; a sync or import that changed a product now
  marks feeds and sitemaps stale when it commits. The ERP product sync stored its `price` and `compare_price` as
  sent: "abc" or a negative price was saved on SQLite. Both must be integer cents (0 to 2 000 000 000), otherwise the
  product is skipped and counted like any other refused product.
- **Updater maintenance window** (review round 19, low). A successful update of code only no longer lifts the failed
  window of a migration that stopped half-way (the shop reopened on an unfinished schema); `update.php apply` warns that
  the shop stays closed, and an update that runs the migrations or `php cli/migrate.php apply` lifts it. A rollback whose
  snapshot misses a file is refused before it changes anything and gives the shop back the window it had (it was marked
  failed and stayed closed). `docs/AI_UPDATE_RUNBOOK.md` describes the maintenance window instead of saying there is none.
- **Cart and manual payment limits** (review round 19, low). Adding to the cart stops at 100 different items, as a
  restore already did (the storefront says the cart is full). A restore that lists the same new item twice counts it
  once (2 × 30 gave 60). On the order detail, "Paid" with the amount `0` is refused (it paid the whole order; an empty
  amount still means the order total), and an amount above the order total is recorded with a warning naming the excess.
- **The merchant's withdrawal e-mail names only the withdrawn items** (review round 19, medium). It listed every line
  of the order and the order total; it now lists the lines of that statement and their sum (new template variable
  `{{withdrawal_total}}`; `{{order_total}}` is still the whole order for customised templates). On a partly shipped
  order the admin marks each withdrawn line as already sent (wait for the return) or not sent (do not ship), instead of
  "do not ship" for all of them, and the acknowledgement column shows the e-mail status in words.
- **Checkout, cookie consent and search suggestions work in a further store language** (review round 19, medium).
  In a language that is not the store's own (`?lang=en` in the Slovak store, `?lang=en` in the Hungarian one) the
  page hands `storefront.js` an API address that already ends in `?lang=en`; the script put the action after it
  (`/api/v1/storefront?lang=en/shipping_rates`) and every storefront API call answered 404 since round 14. Checkout
  kept the total without the chosen shipping (23,33 € shown, 26,82 € charged; 5 490 Ft shown, 7 180 Ft charged), the
  cookie choice was not recorded on the server and search suggestions stayed empty. The action now goes on the path and
  the request's parameters join the address's query. Default theme 2.3.2; a browser test shops in `?lang=es` and checks
  that the total shown is the order's total.
- **Default theme 2.3.1** (review round 18, medium). `storefront.css` and `storefront.js` changed after 2.1.2 (the
  breadcrumb, a homepage hero without an image, the method names at checkout) but kept `?v=2.3.0`, so browsers went on using the cached old files for up
  to a year. A test now pins a fingerprint of the theme's assets to its version and fails when they change without a
  new version.
- **A partial payment no longer pays the order; feeds are rebuilt only for a change they show** (review round 18,
  medium).
  - Recording "Paid" on the order detail with less than the order total (10 € of 100 €) is refused with the missing
    amount; the order no longer goes to fulfilment. Money on a cancelled order is still recorded for a refund.
  - Feeds and sitemaps are no longer rebuilt by every ERP or feed sync and every admin stock edit: an unchanged ERP
    product keeps its `updated_at` (a stock-only change moves `stock_updated_at`), a price feed with the same price
    does not rewrite the product, a recount to the same number changes nothing, and a stock change that keeps the
    product in stock refreshes the product pages only. A product going in or out of stock still rebuilds them.
- **CZK/HUF amounts in the admin, currency of a new product in a shared catalog** (review round 18, medium).
  - An amount stored finer than a whole koruna/forint (by an import, the API or an older version) is shown with its
    cents ("12345.67") and saved back unchanged. Saving an unchanged form used to round it (price list 1234567 →
    1234600, B2B credit limit 123456 → 123500), and saving one price-list line rounded every other line. Admin
    amount fields accept cents in every currency; the storefront still charges whole units.
  - A product created in the admin of a store that shares another store's catalog is priced in the currency of the
    store the catalog belongs to (a HU store sharing the default EUR catalog creates EUR products and sells them
    through its HUF price list). It used to be priced in HUF and could not be sold in the EUR store. The product
    form says which currency its prices are in.
- **The mariadb-dump check names only the real generated columns** (review round 18, medium). Against MySQL 8 it
  listed every column with a `DEFAULT CURRENT_TIMESTAMP` (184 instead of 2), because MySQL marks them
  `DEFAULT_GENERATED`; only `VIRTUAL` and `STORED` generated columns count now.
- **Bounded cart restore and checkout fields** (review round 18, medium and low).
  - `cart_restore` reads at most 200 entries, restores at most 100 lines and prices the cart once; a body over
    64 KiB is refused with 413. One anonymous request with 20 000 lines used to take ~40 s and end in HTTP 500.
    An entry repeated within one restore now counts once.
  - Checkout refuses a name or e-mail over 191 characters, a phone over 40, a street over 200, a city over 100, a
    ZIP over 20 and a company over 255 with `checkout.error.field_too_long` (and `field`, `max_length` in JSON),
    instead of a failed order on MySQL.
- **Sign-up no longer reveals whether an address has an account** (review round 18, medium + low).
  - The storefront sign-in admits an account only once its e-mail address is confirmed. A sign-up could be signed in
    at once with the password the visitor had just chosen, which told that the address had had no account. An
    unconfirmed account gets the same answer as a wrong password (the message now mentions the confirmation link) and
    its owner is sent the link again (at most 3 an hour); the link plus the password signs in and confirms the address.
    A completed password reset confirms it too. **Upgrade note:** existing customers who never confirmed their
    address confirm it at their next sign-in (with the mailed link) or reset the password.
  - Registering a taken address now takes as long as a new account: the password is hashed first and the account
    e-mails (welcome, confirmation, sign-in link) are sent after the response.
  - Per-IP rate limits (sign-in, registration, data export, account offer, admin sign-in) count an IPv6 client per
    /64 network; one client could rotate through the addresses of its /64.
- **Bounded prices** (review round 18, medium).
  - A price from a CSV import, a feed or an admin money field above 20 000 000.00 (`Import::MAX_CENTS`) is refused.
    `184467440737095516.16` overflowed to 0 and the CSV import created the product active for free; now the row is
    reported as an error and an existing product keeps its price.
  - A thousands separator must group three digits: `5..0` is refused instead of read as 50.00, and so are `1.29,00`
    and `1234.567,89`; `1.299,00`, `1,299.00` and `12,345,678` parse as before.
- **Manual orders and withdrawals in the admin** (review round 18, medium).
  - A manual order (and an edit of its lines) stores the product names in the customer's language, not the admin's.
  - The shipping and payment selects start on "— choose —"; an order the admin did not pick them for is refused
    instead of being saved with the first method of the list.
  - A withdrawal on an order already shipped or delivered tells the admin to wait for the return, not "do not ship".
- **`shipping.json` methods next to the store's own shipping rates** (review round 18, medium). Once a store had
  `shipping_rates` rows (the default install seeds Packeta, GLS and pickup), "Standard Delivery" and "Express
  Delivery" from `shipping.json` were neither offered nor accepted at checkout. They are now quoted alongside the
  store's rates, at their `shipping.json` prices and free-shipping thresholds; a store method with the same code wins.
- **Maintenance window after a refused or failed update step** (review round 18, high).
  - `php cli/update.php rollback` lifts maintenance only when the restore completed. A rollback refused without
    `--accept-data-loss`, for an unknown snapshot or for changed files no longer removes `.maintenance`, so a shop a
    failed update left closed stays closed. A rollback that stops after it began restoring files or the database
    keeps the shop on 503 (no mix of old and new files) and the marker names the command to re-run.
  - `php cli/update.php apply` likewise lifts maintenance only after a successful update; a refused or automatically
    rolled-back update restores the window it found (an earlier failed one stays).
  - Neither reopens the shop an updater killed half-way left closed: a rollback or update refused after it entered
    maintenance puts back the marker it found, content and age, not only a failed one.
  - `php cli/migrate.php apply` whose pre-migration backup fails (e.g. MySQL with an unusable `mariadb-dump`) ran no
    migration: the shop opens again instead of staying on 503 for good, or keeps an earlier failed run's window.
  - A lock another process holds on `.maintenance` keeps the shop closed for 24 hours at most.

- **Test suite isolation** (review round 18, medium). The suite's copy of `config.php` now also moves every private
  directory and log file (jobs, invoices, backups, updates, cache, downloads, logs, GDPR salt) into the suite's own
  temp directory: it had left about 330 jobs in the developer's `jobs/pending` for their worker to run with the real
  config, overwritten an invoice and written into their logs. `AppConfig` and `DataOverrides` follow the suite's
  environment variables only on the command line and only for a suite directory in the system temp directory, so a
  web request or a stray variable in production cannot point the shop at another config or data directory.
- **Small fixes** (review round 17, low).
  - Setting an order's payment id, a sign-in (`last_login_at`) and an e-mail verification move `updated_at`, so
    API v1 `updated_after` sees them.
  - The logger masks an XML `<password>`, a password after an escaped quote, and URL- or form-encoded card numbers.
  - Registration and the guest account offer no longer tell whether an e-mail address already has an account.
  - A refund credit note is found by its exact key; a partial shipment's e-mail shows that parcel's own tracking.
  - The worker rolls back only a transaction that is still open; API v1 `vat_rate` is always a number.
  - The bookshop run packs `bookshop-screenshots.zip` with 0644 entries.
  - Without `MAIL_FROM` the sender is `noreply@` the configured `app_url` domain instead of
    `noreply@localhost.invalid`.
  - UX: the admin order history shows actions in words, one skip link per page, the checkout no longer runs a
    method's name into its price ("Platba kartouZdarma"), and the breadcrumb is laid out as one separated line.

- **Small fixes** (review round 18, low).
  - The JS tool configs at the web root (`eslint.config.js`, `playwright.config.js`, `lighthouserc.js`, any
    `*.config.js`/`.mjs`/`.cjs`) are denied by `.htaccess`, `router.php` and `deploy/nginx.conf`. Update a custom
    nginx configuration accordingly.
  - The invoice link in the invoice e-mail opens the invoice page in the order's language, not in the language of the
    admin who issued or resent it.
  - Invoices and credit notes print their dates in the document language (`24. 10. 2026`, not `2026-10-24`); the
    delivery note shows the order's day on the store's calendar (it showed the UTC day) in the same format.
  - E-mails name the store in the From line (`"Slovenský obchod" <noreply@…>`), and without `MAIL_REPLY_TO` a reply
    goes to the store's contact address (`legal.email` in `stores.json`) instead of the noreply mailbox.
  - Admin → Stores and seller has a time zone list per store (saved as `timezone` in `stores.json`); it was file-only,
    so doctor's "store sets no timezone" warning could not be fixed from the admin.
  - The admin shows GDPR event actions, webhook incident states, audit log actions and the legal-text buttons in words
    ("Anonymized by an admin", "Terms & conditions (SK)") instead of their internal codes.
- **Developer docs and carrier data** (review round 17, medium).
  - The migration example in `docs/EXTENSIONS.md` creates its table with an `id` column; it named no primary-key
    column, so SQLite got a column called `INTEGER` and MySQL/MariaDB refused it (ERROR 1064). A test runs the
    example as written on both databases.
  - `docs/EXTENSIONS.md` documents a payment provider's `signature_header` (without it every notification is
    answered HTTP 400) and the `payment_methods` and `payment_method_countries` rows that offer it at checkout.
  - `php cli/theme-check.php` no longer warns 17 times per store: the root `layouts.json` lost its `category`,
    `cart`, `checkout` and `search` pages, which the storefront renders with fixed templates and never read.
    `docs/THEMES.md` says which pages are built from sections.
  - A carrier's `generateLabel()` receives the label fields of the order (`ShippingService::LABEL_ORDER_FIELDS`)
    instead of the whole row: no IP address, customer account, payment or ERP data.

- **Admin order acceptance fixes** (review round 17, medium).
  - Recording a payment as Paid by hand (for example after a failed card payment, or a bank transfer) moves a pending
    order to Paid, as a gateway capture does; the order used to stay Pending. Delivered cash-on-delivery orders stay
    Delivered; money recorded on a cancelled order still goes to a manual refund.
  - An e-book whose line was refunded through a return shows "Blocked" in the order detail instead of "Active"; the
    download itself was already refused.
  - A credit note has a variable symbol of its own: "CN-20260007" no longer carries the digits of invoice 20260007.
- **Updater respects the doctor** (review round 17, medium). `php cli/update.php check` and `apply` refuse every
  `config.php` FAIL of `doctor` (a private path such as `jobs_dir` inside the web root, a v1.x `stores.json` without
  `private_runtime_dir`) and show its warnings; `apply` used to install the package anyway. A MariaDB dump client
  (`mariadb-dump`, the Debian/Ubuntu default, also as `mysqldump`) against a MySQL server is refused before the dump
  with the generated columns it would break (`ERROR 3105` on restore) and the settings to change; the runbook says
  which client to install. A shop that still has `api.php` (before 2.1.0) gets a `payment_webhooks` warning from
  `doctor` and `check` with the new webhook URLs, also added to the runbook.
- **One money unit in the admin** (review round 17, medium). The product price was typed in euros, but variant
  prices, price lists, promotions and coupons, the B2B credit limit, supplier purchase prices and shipping and payment
  amounts still took cents ("1290" saved 1 290 €, not 12.90 €). Every admin money field now takes and shows the amount
  in the currency (12.90), with the currency on the label; stored values stay in cents. A new product in a store
  with another currency (HUF) is priced in that currency, not EUR.
- **Tests isolated from the developer's `config.php`** (review round 17, medium). The suite read the checkout's
  `config.php`: an enabled extension or an outbound webhook broke core tests, and the suite posted signed events to
  the developer's real webhook. It now runs on a copy of `config.php` (database, private directories, `app_url` and
  keys kept) with extensions, outbound webhooks, mail/ERP/carrier providers, integrations, alert recipients and
  payment gateway keys reset to `config.example.php`; the PHP processes the suite starts use the same copy. Shop code
  reads its configuration through `AppConfig::path()`.
- **Feeds and sitemaps after a sale** (review round 17, medium). Since v2.1.2 every sale, shipment or stock
  adjustment moved `products.updated_at`, which is part of the feed and sitemap fingerprint, so a busy shop rebuilt
  them after every order. A stock change now moves the new `products.stock_updated_at` (migration
  `20261105120000_product_stock_updated_at`); a product or variant going in or out of stock still rebuilds them,
  through a per-change catalog token instead of a same-second file time check. API v1 `updated_after` matches
  either column, so an integration still sees the stock change.
- **Maintenance window** (review round 17, medium).
  - The updater holds a lock on `.maintenance` while it runs: a reused PID no longer keeps the shop on 503 for up to
    24 h, and an update started from another container (docker compose, shared volume) keeps its window past 1 h.
    An unlocked marker of a process that still runs counts only with the same process start time.
  - A failed `php cli/migrate.php apply` (or an update whose automatic rollback fails) leaves the shop in maintenance
    instead of reopening it on a half-migrated schema; the next successful run lifts it.
- **Extension quarantine only for broken code** (review round 17, medium). Input that made an extension fail an
  allocation ("Out of memory") still quarantined it, payment provider included, for good. Only a parse, compile or
  class-linking error in an extension's file quarantines it now; memory, time and stack limits and exceptions never
  do. Without a writable `private_runtime_dir` there is no quarantine: the fallback file in the shared temp
  directory had a predictable name, so another account on the host could plant one.
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
- **A stock change by a CSV import no longer rebuilds the feeds** (review round 19, medium). A CSV import that moved
  a product's stock (5 → 7 pieces) bumped its `updated_at`, so every feed and sitemap was rebuilt; it now moves
  `stock_updated_at` only, and the feeds are rebuilt when a CSV import or an ERP product sync takes a product into or
  out of stock (the ERP sync did not mark them changed then). Low: a price saved within the same second as the
  change a feed was built after is in the feed at once (was: the old price for up to 15 minutes).
- **The test suite leaves the developer's database alone** (review round 19, medium). The suite config kept the
  developer's `db_sqlite_path`, so the processes the suite starts opened that database and marked its `event_outbox`
  rows delivered; a theme-check fixture appended its log lines to the developer's `application.jsonl`. The suite's
  database is now the one in its own private directory, child processes included.
- **Imported products are priced in their catalog's currency** (review round 19, medium). A product created by a CSV
  import, the API, a feed or an ERP sync in a HUF or CZK catalog was stored as EUR ("12 990 Ft" became 12 990,00 €)
  and could not be sold in its store; it now takes the currency of the store the catalog belongs to, and an existing
  product keeps its currency when it is imported again. Products already created this way keep their EUR price;
  check them in **Catalog → Products**. Also: an import price like "1.234 Kč" (one separator before three digits) in
  a CZK or HUF catalog is 1 234, not 1,23 (in a currency with cents it stays 1,234, see round 20); the shipping and payment pages show and check their amounts in the currency of the store owning
  the providers (a USD store using the default store's providers saw them as dollars).
- **Sign-up no longer loops** (review round 19, medium). The page after sign-up and the welcome mail said "you can sign
  in now", but an unconfirmed account cannot sign in, and every attempt mailed a new link that cancelled the earlier
  one, so the link from the first mail failed. The page now asks to confirm the address first; the welcome mail goes
  out after the confirmation; the five newest confirmation links stay valid together (24 hours). Low: the forgot
  password answer no longer takes longer for an address with an account (the mail goes out after the response), and a
  throttled mail sent after the response no longer logs "headers already sent".
- **Admin and documents in words** (review round 19, low). The audit log names what changed ("Payment link sent", not
  `payment_link_sent`), the order detail names the e-mail template and the invoice type ("Credit note"), and the
  template editor's title and its saved/reset message name the template. "Create and send" and "Ship" start with the
  carrier the customer chose (a parcel was saved without one). At 390 px the account's order table scrolls inside its
  card instead of the whole page, and the admin's store switcher comes first in the navigation with a readable width
  (default theme 2.3.3). The PDF credit note's dates sit under the title (a long title, e.g. "Factura rectificativa
  n.º …", ran into them). "A field is too long" now names the field and its maximum. An English delivery note names
  the country in English ("Slovakia", not "Slovensko").

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
