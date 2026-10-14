# Extensions

Shop-specific code lives in `extensions/<code>/`, not in core files. An extension can add
providers, react to domain events and add storefront view-model data through the same
registries the core uses. Updates never write to `extensions/` (`Updater` treats it as a
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
- **Failure is per extension and all-or-nothing.** A missing directory, a manifest that does
  not match, an exception in `register()`, a missing or wrong provider class, or a namespace
  outside the extension's own is reported, and none of that extension's registrations are
  used. The shop and the other extensions keep running. Reasons are in the structured log
  (`extension.load_failed`), in `Extensions::errors()` and as a failing `extensions` check in
  `php cli/setup.php doctor`.
- Extensions run with the same privileges as core code. Review them like core changes.
