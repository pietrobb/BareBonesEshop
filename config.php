<?php
/**
 * BareBones Shop — Configuration
 * Single file, all settings.
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

    // Default locale & currency
    'default_locale'   => 'sk',
    'default_currency' => 'eur',

    // Catalog
    'per_page' => 24,

    // Cache
    'cache_dir' => __DIR__ . '/cache',
    'cache_ttl' => 3600,

    // Admin
    'admin_user'     => 'admin',
    'admin_password' => '', // password_hash() output — NEVER plaintext

    // Security
    'trusted_proxies' => [],

    // Adapters
    'adapters' => [
        'search'      => 'FtsSearch',
        'queue'       => 'FileQueue',
        'payment'     => 'StripePayment',
        'storage'     => 'LocalStorage',
        'integration' => null,
    ],

    // Stripe (when using StripePayment adapter)
    'stripe_secret_key'      => '',
    'stripe_publishable_key' => '',
    'stripe_webhook_secret'  => '',

    // Jobs
    'jobs_dir' => __DIR__ . '/jobs',
];
