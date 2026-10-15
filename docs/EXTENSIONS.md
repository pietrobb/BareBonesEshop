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

        // JSON API action /api/?action=acme-loyalty.balance.
        $api->api('balance', static fn(array $query): array => ['points' => AcmeLoyaltyPoints::forCustomer()]);
    },
];
```

## Contract

- **Providers** are selected like core ones (`provider_defaults`, store config, `Adapters::payment('code')`).
  An extension cannot replace a core or configured provider code. Constructors that take a
  parameter receive the deployment config, as for core adapters.
- **Listeners** receive the CloudEvents envelope that outbound webhooks get. They run in
  `cli/worker.php` after the domain transaction committed, at least once. Each listener is
  its own delivery endpoint `ext-<code>-<name>` in `webhook_deliveries`: a throwing listener
  makes the event retry with backoff (up to `Outbox::MAX_ATTEMPTS`), and listeners that
  already succeeded are not run again. Listeners must be idempotent per event `id`.
- **Storefront** providers return an array, which templates read from
  `$model['extensions'][<code>][...]`; an empty array adds nothing.
- **Pages** answer `/?page=<code>.<name>` and return `['title' => string, 'content' => string]`.
  The content is HTML placed into the active theme's layout, so render data through
  `Template::process()` / `Template::render()` (they escape `{{ }}`). Core routes cannot be replaced.
- **API actions** answer `/api/?action=<code>.<name>` with the returned array as JSON, under the
  same session, locale and per-action rate limit as core actions. Validate input like core
  handlers (`Security::int()`, `Security::clean()`); state-changing actions must check CSRF.
- **Failure is per extension and all-or-nothing.** A missing directory, a manifest that does
  not match, an exception in `register()`, a missing or wrong provider class, or a namespace
  outside the extension's own is reported, and none of that extension's registrations are
  used. The shop and the other extensions keep running. Reasons are in the structured log
  (`extension.load_failed`), in `Extensions::errors()` and as a failing `extensions` check in
  `php cli/setup.php doctor`.
- Extensions run with the same privileges as core code. Review them like core changes.

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
