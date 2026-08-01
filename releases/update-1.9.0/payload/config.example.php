<?php
/**
 * BareBones Shop — Configuration TEMPLATE
 *
 * Copy this file to `config.php` and fill in your own values.
 * `config.php` is gitignored so your secrets never land in the repo.
 *   cp config.example.php config.php
 */
return [
    // Database: 'sqlite' or 'mysql'
    'db_driver' => 'sqlite',

    // Private runtime data must live outside the HTTP document root.
    'private_runtime_dir' => dirname(__DIR__) . '/bareboneseshop-private',

    // SQLite
    'db_sqlite_path' => dirname(__DIR__) . '/bareboneseshop-private/db/shop.sqlite',

    // MySQL/MariaDB (when db_driver = 'mysql')
    'db_host'     => '127.0.0.1',
    'db_port'     => 3306,
    'db_name'     => 'barebone_shop',
    'db_user'     => 'shop',
    'db_password' => '',
    'db_charset'  => 'utf8mb4',

    // Default locale & currency (stores.json is the source of truth — these are fallbacks only)
    'default_locale'   => 'en',
    'default_currency' => 'eur',
    'app_url'          => '', // public base URL, e.g. https://shop.example.com

    // Catalog
    'per_page' => 24,

    // Public catalog JSON cache used by the explicit .htaccess API rewrites.
    'cache_dir' => __DIR__ . '/cache',
    'cache_ttl' => 3600,
    // Private abuse-control databases and lock files.
    'private_cache_dir' => dirname(__DIR__) . '/bareboneseshop-private/cache',

    // Unpaid online orders are released only after this age and provider
    // confirmation of a terminal unpaid state. Keep above provider session TTL.
    'pending_order_expiry_seconds' => 172800,
    'pending_order_expiry_batch'   => 25,

    // Admin
    'admin_name'     => '', // optional admin brand; defaults to the translated BareBonesShop name
    'admin_user'     => 'admin',
    'admin_password' => '', // password_hash() output — NEVER plaintext
    'admin_email'    => '', // fallback recipient for paid-order notifications
    'merchant_notifications' => [
        'enabled' => true,
        'recipients' => [], // falls back to admin_email
        'templates' => ['paid' => 'merchant_order_paid'],
    ],

    // Security
    'trusted_proxies' => [],

    // Adapters
    'adapters' => [
        'search'      => 'FtsSearch',
        'queue'       => 'FileQueue',
        'payment'     => 'StripePayment',
        'vat_validator' => 'ViesVatIdValidator', // MockVatIdValidator for dev/tests
        'storage'     => 'LocalStorage',
        'integration' => null,
    ],

    // Dev only: route api.php?action=webhook_payment&provider=mock even when the
    // active payment adapter is not MockPayment. NEVER enable in production —
    // the mock webhook marks orders as paid. Pair with 'mock_webhook_secret'.
    'allow_mock_webhook' => false,
    'mock_webhook_secret' => '',

    // Carrier webhook HMAC secrets, keyed by carrier ID. Environment variables
    // such as SHIP_PACKETA_WEBHOOK_SECRET take precedence.
    'shipping_webhook_secrets' => [
        'packeta' => '',
        'gls' => '',
    ],

    // Stripe (when using StripePayment adapter)
    'stripe_secret_key'      => '',
    'stripe_publishable_key' => '',
    'stripe_webhook_secret'  => '',

    // Digital files are stored below this directory; digital_assets.storage_path
    // contains only a relative path such as ebooks/manual.pdf.
    'digital_assets_base_dir' => dirname(__DIR__) . '/private_downloads',

    // Deployments define the exact legal text and version they require. Optional
    // product_types/countries arrays make a checkbox context-specific.
    'checkout_consents' => [
        // 'digital_delivery' => [
        //     'version' => '2026-01-01', 'required' => true,
        //     'text' => 'I request immediate delivery of the digital content and acknowledge the loss of my withdrawal right once delivery begins.',
        //     'url' => '/terms', 'product_types' => ['downloadable'],
        // ],
    ],

    // Private runtime files
    'jobs_dir'            => dirname(__DIR__) . '/bareboneseshop-private/jobs',
    'invoice_storage_dir' => dirname(__DIR__) . '/bareboneseshop-private/invoices',
    'backup_storage_dir'  => dirname(__DIR__) . '/bareboneseshop-private/backups',
    'backup_log_file'     => dirname(__DIR__) . '/bareboneseshop-private/logs/backup.log',
    'update_storage_dir'  => dirname(__DIR__) . '/bareboneseshop-private/updates',
    'update_public_key'   => '', // Ed25519 public key (base64/hex or absolute key-file path)
    'gdpr_salt_file'      => dirname(__DIR__) . '/bareboneseshop-private/secrets/gdpr_salt',

    // TecDoc sidecar DB (read-only, for vehicle cascade lookups)
    'tecdoc_db_path' => __DIR__ . '/demo/tecdoc_enriched.db',

    // GDPR — prefer injecting this secret; otherwise it is generated in gdpr_salt_file.
    'gdpr_hash_salt' => '',
];
