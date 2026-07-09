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

    // Catalog
    'per_page' => 24,

    // Cache
    'cache_dir' => __DIR__ . '/cache',
    'cache_ttl' => 3600,

    // Admin
    'admin_user'     => 'admin',
    'admin_password' => '', // password_hash() output — NEVER plaintext
    'admin_email'    => '', // notification email for new orders

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

    // Jobs
    'jobs_dir' => __DIR__ . '/jobs',

    // TecDoc sidecar DB (read-only, for vehicle cascade lookups)
    'tecdoc_db_path' => __DIR__ . '/demo/tecdoc_enriched.db',

    // GDPR — secret salt for hashing PII (emails, IPs) in gdpr_events / cookie_consent.
    // If left empty, GdprService::hashPii() autogenerates one on first call and persists
    // it to config/.gdpr_salt. Hard-fail if persisted file exists but is empty (corruption).
    'gdpr_hash_salt' => '',
];
