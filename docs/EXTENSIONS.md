# Extensions

Shop-specific code lives in `extensions/<code>/`, not in core files. An extension can add
providers, react to domain events, add storefront view-model data, its own storefront pages
and JSON API actions, and its own database tables or columns through the same registries and
migration runner the core uses. The core stays generic: anything that serves one kind of shop
(for example an auto-parts vehicle lookup) belongs in an extension. Updates never write to `extensions/` (`Updater` treats it as a
protected deployment path and `UpdatePackageBuilder` never packages it), the drift guard
(`CoreIntegrity`) does not hash it, and `.htaccess` / `deploy/nginx.conf` deny web access to it.

## Enabling

Only codes listed in `config.php` are loaded, in that order:

```php
'extensions' => ['acme-loyalty'],
```

A code is 2–31 characters of `a-z`, `0-9` and `-`, starting with a letter, and equals the
directory name.

## Layout

```
extensions/acme-loyalty/
  extension.php            manifest, returns an array
  src/AcmeLoyaltyMail.php  classes, autoloaded by class name
  migrations/              optional, own tables/columns (see Database)
  templates/               optional, the extension's own markup
```

```php
<?php
return [
    'code' => 'acme-loyalty',
    'name' => 'Acme loyalty points',
    'version' => '1.0.0',
    'register' => static function (ExtensionApi $api): void {
        // Provider: class from src/, must implement the type's interface (MailAdapter here).
        $api->provider('mail', 'acme-mail', AcmeLoyaltyMail::class, ['send']);

        // Domain event listener: "order.created", "order.*" or "*" (see src/DomainEvents.php).
        $api->on('order.created', 'points', static function (array $event): void {
            AcmeLoyaltyPoints::award((int)$event['data']['order_id'], (string)$event['store_id']);
        });

        // Storefront data: product/home/cms_page view models, under "<code>" or "<code>.<key>".
        $api->storefront('product', 'acme-loyalty.points', static fn(array $context): array =>
            ['earn' => intdiv((int)$context['source']['price'], 100)]);

        // Storefront page /?page=acme-loyalty.points inside the theme layout.
        $api->page('points', static fn(array $query): array => [
            'title' => 'My points',
            'content' => Template::process((string)file_get_contents(__DIR__ . '/templates/points.html'),
                ['points' => AcmeLoyaltyPoints::forCustomer()]),
        ]);

        // JSON API action /api/v1/storefront/acme-loyalty.balance.
        $api->api('balance', static fn(array $query): array => ['points' => AcmeLoyaltyPoints::forCustomer()]);

        // State-changing action: POST only, the session CSRF token is enforced by the core.
        $api->api('redeem', static fn(array $query): array => AcmeLoyaltyPoints::redeem(), ['method' => 'POST']);
    },
];
```

## Contract

- **Providers** are selected like core ones (`provider_defaults`, store config, `Adapters::payment('code')`).
  An extension cannot replace a core or configured provider code. Constructors that take a
  parameter receive the deployment config, as for core adapters.
- **Listeners** receive the CloudEvents envelope that outbound webhooks get. They run in
  `cli/worker.php` after the domain transaction committed, at least once. Each listener is
  its own delivery endpoint `ext:<code>:<name>` in `webhook_deliveries` (":" appears in neither
  part, so two listeners never share an endpoint; a duplicate fails the extension's load): a throwing listener
  makes the event retry with backoff (up to `Outbox::MAX_ATTEMPTS`), and listeners that
  already succeeded are not run again. Listeners must be idempotent per event `id`.
- **Storefront** providers return an array, which templates read from
  `$model['extensions'][<code>][...]`; an empty array adds nothing.
- **Texts.** Do not add keys to the core's `lang/` files: an update replaces them. Keep an extension's texts
  in its own folder (for example `extensions/<code>/lang/<locale>.php` returning an array) and pick the
  language with `I18n::locale()`, falling back to English. Core texts are available through `I18n::t()`.
  A theme that styles an extension's page ships its own texts (`themes/<name>/lang/`, see
  [THEMES.md](THEMES.md#texts-and-languages)).
- **Pages** answer `/?page=<code>.<name>` and return `['title' => string, 'content' => string]`.
  The content is HTML placed into the active theme's layout, so render data through
  `Template::process()` / `Template::render()` (they escape `{{ }}`). Core routes cannot be replaced.
- **API actions** answer `/api/v1/storefront/<code>.<name>` with the returned array as JSON, under the
  same session, locale and per-action rate limit as core actions. Validate input like core
  handlers (`Security::int()`, `Security::clean()`). The third argument of `$api->api()` takes
  options: `'method' => 'GET'|'POST'` limits the action to that method (others get HTTP 405), and
  every non-GET/HEAD request must carry the session CSRF token (`X-CSRF-Token` header or a
  `csrf_token` field, HTTP 403 otherwise). Declare state-changing actions `'method' => 'POST'`
  so a GET link cannot trigger them. `'csrf' => false` turns the token check off for one action;
  use it only for a server-to-server call that authenticates its caller itself (a signed webhook).
  Upgrading from the `ext-<code>-<name>` listener endpoints: a listener of an event that was
  still being retried during the upgrade may run once more, which the idempotency rule above absorbs.
- **Failure is per extension and all-or-nothing.** A missing directory, a manifest that does
  not match, an exception in `register()`, a missing or wrong provider class, or a namespace
  outside the extension's own is reported, and none of that extension's registrations are
  used. The shop and the other extensions keep running. Reasons are in the structured log
  (`extension.load_failed`), in `Extensions::errors()` and as a failing `extensions` check in
  `php cli/setup.php doctor`.
- **Fatal errors quarantine the extension.** Some errors cannot be caught inside PHP: a provider
  class that misses one of its interface's methods, a class declared twice, an incompatible method
  signature. The request that hits one fails (HTTP 500), and the extension whose file raised it is
  quarantined: the following requests, workers and CLI commands skip it like any broken extension,
  and the reason (message, file, line) is in `Extensions::errors()`, the doctor and the log
  (`extension.quarantined`). The quarantine lives in `<private_runtime_dir>/extension-quarantine.json`
  (without that directory, in the system temp directory) and lifts by itself as soon as one of the
  extension's PHP files changes; deleting the entry retries the unchanged extension. Running out of
  memory or time does not quarantine.
- Extensions run with the same privileges as core code. Review them like core changes.

## Provider contracts

The interfaces are in `adapters/`; the shipped implementations in `adapters/contrib/` are working
examples. Amounts are always integers in minor units (cents) of the order's currency.

### Payment (`PaymentAdapter`)

- `createSession(array $order): array` is called when the customer submits the checkout and from
  "pay again" / admin payment links. `$order` has `order_id`, `order_number`, `total`, `currency`
  (lowercase), `customer_email`, `customer_name`, `locale`, `items`, `shipping_cost`, `success_url`,
  `cancel_url` and `notify_url` (`/api/v1/webhooks/payment/<provider code>`). Return
  `['url' => <where the browser goes>, 'session_id' => <your reference>]`; the session id is stored
  as the order's payment reference. A URL on another origin needs `HostedCheckoutAdapter` and its
  `redirectOrigins()`, or the checkout's CSP blocks the redirect.
- Notifications arrive at `/api/v1/webhooks/payment/<provider code>` (the code may contain `-`).
  Implement exactly one way to trust them:
  `SignedWebhookPaymentAdapter::verifySignature($rawBody, $signatureHeader)` (the request is
  rejected without it), or `PullVerifiedNotificationAdapter::verifiedEvent($request)`, which asks
  your provider's API instead of believing the request.
- `handleWebhook(array $payload)` / `verifiedEvent()` return
  `['event_type' => ..., 'event_id' => <stable provider id>, 'data' => [...]]`. Event types the
  state machine acts on: `payment.paid`, `payment.failed`, `refund.succeeded`, `payment.disputed`;
  others are recorded and ignored. `data` names the order with `order_number` (or `order_id`) and
  your `payment_id`, plus `amount` and `currency`: a `payment.paid` whose amount or currency differs
  from the order is not booked and raises an incident. Each `event_id` is applied once.
- `refund(string $paymentId, int $amount)` receives the provider payment id (`payment_id` from
  your events, the session id until one arrived) and returns
  `['status' => 'succeeded'|'pending'|'failed', 'refund_id' => ...]`.
  `IdempotentRefundAdapter::refundWithIdempotency()` is used instead when implemented, and is
  required for retry-safe refunds.
- `getStatus(string $paymentId): string` is read by the expiry sweep and pay-again: `expired`,
  `cancelled`/`canceled`, `failed`, `timeouted`, `rejected`, `error` mean "can no longer be paid";
  anything else keeps the order waiting.
- Optional: `CancellablePaymentAdapter::cancelPayment()` voids an unpaid session when the order is
  cancelled; `PaymentStatusEventAdapter::statusEvent()` lets the sweep book a payment whose
  notification never arrived.

### Shipping (`ShippingAdapter`)

- **Where prices come from.** When the store has shipping rates in the admin (Shipping → zones,
  methods and rates; the migrations seed defaults) for the cart's country and currency, those rows
  are the quote and **`getRates()` is not called**. `getRates($params)` is asked only when that table
  has no matching row (or the store charges in another currency than its provider prices). Its
  `$params` and return shape are documented in `adapters/ShippingAdapter.php`; a throwing carrier is
  skipped (logged) and the others are still offered.
- `generateLabel(array $order)` runs from the admin order detail ("Labels"), offered for the carrier
  of the order's shipping method; return `['awb', 'label_data', 'carrier']`.
  `ShippingLifecycleAdapter` adds pickup points and `cancelLabel()`.
- `trackShipment($awb)` is **not polled** by the core: parcel status reaches the shop only through
  tracking webhooks (below). `ShippingService::trackShipment($carrier, $awb)` calls it on demand,
  e.g. from an extension's own job. `carrierId()` must equal the code stored on labels and
  shipments and used in the tracking webhook URL.

### Tracking webhooks

`POST /api/v1/webhooks/shipping/<carrier>` takes a JSON body signed with HMAC-SHA256 (hex) of
`<timestamp>.<raw body>` with the carrier's secret (`SHIP_<CARRIER>_WEBHOOK_SECRET` or
`shipping_webhook_secrets[<carrier>]`, at least 16 characters); the signature is in
`X-Shipping-Signature` (`X-Packeta-Signature`, `X-GLS-Signature` for those carriers) and the Unix
timestamp in `X-Shipping-Timestamp` (±5 minutes). The core format of the body is
`{"awb": "...", "status": "..."}`. A carrier adapter that implements `TrackingWebhookAdapter`
receives the verified body in `parseTrackingWebhook($payload, $rawBody)` and returns that
`['awb' => ..., 'status' => ...]` from its own format. Statuses: `created`, `in_transit`,
`out_for_delivery`, `ready_for_pickup`, `failed`, `delivered`. An AWB no label or shipment carries
is answered 404 (409 when several match), so the carrier redelivers it; a body without an AWB or
with another status is acknowledged and changes nothing. A carrier whose own signature scheme differs needs a small relay
that verifies it and re-signs as above.

## Database

New product fields need no code: define attributes in the admin (Attributes, assigned to categories). Their
values are stored in `product_specifications`, shown on the product page and offered as category
filters (`Products::parametricSearch()` / `Products::specFilterValues()`).

For its own tables or columns an extension ships migrations in `extensions/<code>/migrations/`,
in the core format:

```php
<?php
// extensions/acme-loyalty/migrations/20261014130000_points.php
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $id = $isSqlite ? 'INTEGER PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $db->exec("CREATE TABLE IF NOT EXISTS ext_acme_loyalty_points ({$id}, customer_id INTEGER NOT NULL, points INTEGER NOT NULL DEFAULT 0)");
    }
};
```

- `php cli/migrate.php` applies the core migrations, then the pending migrations of every enabled
  extension in config order (one pre-migration backup covers both). `php cli/migrate.php status`
  lists them as `<code>/<file>`.
- They are recorded in `extension_migrations`, forward-only and checksummed like core ones: an
  applied file that changes stops the run. Write a new migration instead.
- Name tables `ext_<code>_…` (with `-` as `_`). A column added to a core table must be named
  `ext_<code>_…` and be nullable or have a default, so core inserts keep working.
- Run `php cli/migrate.php` after enabling or changing an extension; core updates do not touch
  extension migrations.
