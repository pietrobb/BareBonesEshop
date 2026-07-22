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

    // SQLite
    'db_sqlite_path' => __DIR__ . '/db/shop.sqlite',

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

    // Cache
    'cache_dir' => __DIR__ . '/cache',
    'cache_ttl' => 3600,

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

    // Stripe (when using StripePayment adapter)
    'stripe_secret_key'      => '',
    'stripe_publishable_key' => '',
    'stripe_webhook_secret'  => '',
    'stripe_checkout' => [
        // Included as metadata[app] on Checkout Sessions and PaymentIntents.
        // When non-empty, Stripe webhooks without this exact value are ignored.
        'metadata_app' => 'barebones', // customize when one Stripe account serves multiple applications
        'managed_payments' => [
            'enabled' => false,
            'tax_behavior' => 'inclusive', // BareBones catalog prices include VAT by default
            'excluded_billing_countries' => ['SK', 'CZ'],
            'eligible_product_types' => ['downloadable', 'digital'],
        ],
        // Stripe Product tax codes. Resolution order: line item override, SKU,
        // product type, default. Managed Payments requires every line to resolve.
        'tax_codes' => [
            'default' => '',
            'by_sku' => [],
            'by_product_type' => [
                // 'downloadable' => 'txcd_10103000',
            ],
            'shipping' => '',
        ],
    ],

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

    // Jobs
    'jobs_dir' => __DIR__ . '/jobs',

    // TecDoc sidecar DB (read-only, for vehicle cascade lookups)
    'tecdoc_db_path' => __DIR__ . '/demo/tecdoc_enriched.db',

    // GDPR — secret salt for hashing PII (emails, IPs) in gdpr_events / cookie_consent.
    // If left empty, GdprService::hashPii() autogenerates one on first call and persists
    // it to config/.gdpr_salt. Hard-fail if persisted file exists but is empty (corruption).
    'gdpr_hash_salt' => '',
];
