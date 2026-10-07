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
    'admin_password' => '', // legacy password_hash() fallback — NEVER plaintext
    // DB admin identities take precedence once at least one active account exists.
    // Inject a random 32-byte base64/hex key; it encrypts TOTP seeds and never belongs in DB exports.
    'admin_identity_key' => '',
    'admin_session_idle_seconds' => 1800,
    'admin_session_lifetime_seconds' => 28800,
    'admin_email'    => '', // fallback recipient for merchant order notifications
    'merchant_notifications' => [
        'enabled' => true,
        'recipients' => [], // falls back to admin_email
        // created = every new order incl. unpaid bank transfer; paid = payment confirmed
        'events' => ['created' => true, 'paid' => true],
        'templates' => ['created' => 'merchant_order_created', 'paid' => 'merchant_order_paid'],
    ],
    // One reminder per abandoned cart (worker cron). Needs app_url for the recovery/opt-out links.
    // Carts older than max_age_hours are never reminded, so enabling it causes no mail burst.
    'cart_reminder' => ['enabled' => true, 'delay_hours' => 24, 'max_age_hours' => 72],
    // Core drift guard (php cli/drift-guard.php check --notify, daily cron). Shop code lives in
    // themes/<shop> or config, so only real core edits are reported. recipients fall back to admin_email.
    'drift_guard' => ['recipients' => [], 'exclude' => []],

    // Security
    'trusted_proxies' => [],

    // Adapters
    'adapters' => [
        'search'      => 'FtsSearch',
        'queue'       => 'FileQueue',
        'payment'     => 'StripePayment', // legacy default, retained for compatibility
        'vat_validator' => 'ViesVatIdValidator', // MockVatIdValidator for dev/tests
        'storage'     => 'LocalStorage',
        'metrics'     => null, // JsonlMetricAlert for optional metrics/alert export
        'integration' => null, // legacy single ERP adapter
    ],

    // Stable provider codes. Built-in payment/shipping/mail/tax providers are
    // registered automatically; add or override deployment-specific adapters here.
    'provider_defaults' => [
        'payment' => 'stripe',
        'mail' => 'log',
        'tax' => 'vat',
        // 'erp' => 'money',
    ],
    'providers' => [
        'payment' => [], // e.g. 'gopay' => 'GoPayPayment'
        'shipping' => [],
        'mail' => [],
        'tax' => [],
        'erp' => [], // e.g. 'money' => ['class' => 'MoneyErpAdapter', 'capabilities' => ['orders', 'stock']]
    ],
    // Generic versioned HTTPS contracts. Keep API keys in deployment secrets,
    // never in the repository, logs, database, or setup output.
    'integrations' => [
        'http_shipping' => [
            'base_url' => '', 'api_key' => '', 'carrier_code' => 'http-carrier',
            'supports_cod' => false, 'timeout' => 15,
        ],
        'http_erp' => ['base_url' => '', 'api_key' => '', 'timeout' => 15],
    ],
    'operations' => [
        'worker_cron' => '', // e.g. * * * * *; install it in the platform scheduler too
        'monitor_cron' => '', // e.g. */5 * * * * php cli/monitor.php
    ],
    'monitoring' => [
        'backup_rpo_seconds' => 86400,
        'queue_pending_warning' => 100,
        'failed_jobs_warning' => 1,
        'failed_mail_warning' => 1,
        'payment_incidents_critical' => 1,
        'webhook_incidents_warning' => 1,
    ],

    // Signed outgoing webhooks. Secrets remain in deployment config/environment;
    // event_outbox and delivery audit rows never contain them.
    'outbound_webhooks' => [
        // 'erp_primary' => [
        //     'url' => 'https://integration.example/webhooks/commerce',
        //     'secret' => '', // minimum 32 bytes; inject outside version control
        //     'events' => ['order.*', 'payment.*', 'stock.*'],
        //     'stores' => ['default'],
        //     'active' => false,
        // ],
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

    // Queue backend: file (zero dependency), database (durable/concurrent), or redis.
    'queue' => [
        'backend' => 'file',
        'lease_seconds' => 300,
        'redis' => [
            'host' => '127.0.0.1', 'port' => 6379, 'database' => 0,
            'password' => '', 'timeout' => 2.0, 'prefix' => 'bbe:queue:',
        ],
    ],

    // Private runtime files
    'jobs_dir'            => dirname(__DIR__) . '/bareboneseshop-private/jobs',
    'invoice_storage_dir' => dirname(__DIR__) . '/bareboneseshop-private/invoices',
    'backup_storage_dir'  => dirname(__DIR__) . '/bareboneseshop-private/backups',
    'backup_storage_adapter' => 'LocalBackupStorage', // MountedBackupStorage requires an operator-mounted off-site target + sentinel
    'backup_log_file'     => dirname(__DIR__) . '/bareboneseshop-private/logs/backup.log',
    'structured_log_file' => dirname(__DIR__) . '/bareboneseshop-private/logs/application.jsonl',
    'metrics_log_file'    => dirname(__DIR__) . '/bareboneseshop-private/logs/metrics.jsonl',
    'update_storage_dir'  => dirname(__DIR__) . '/bareboneseshop-private/updates',
    'update_public_key'   => '', // Ed25519 public key (base64/hex or absolute key-file path)
    // Required only for MySQL/MariaDB packages containing migrations. Reserve
    // this disposable schema name and grant db_user access, but do not create it;
    // it must end with _update_probe. The updater refuses to overwrite an existing schema.
    'update_mysql_probe_database' => '',
    'update_mysql_dump_binary'    => 'mysqldump', // use mariadb-dump where appropriate
    'update_mysql_client_binary'  => 'mysql',     // use mariadb where appropriate
    'gdpr_salt_file'      => dirname(__DIR__) . '/bareboneseshop-private/secrets/gdpr_salt',

    // TecDoc sidecar DB (read-only, for vehicle cascade lookups)
    'tecdoc_db_path' => __DIR__ . '/demo/tecdoc_enriched.db',

    // GDPR — prefer injecting this secret; otherwise it is generated in gdpr_salt_file.
    'gdpr_hash_salt' => '',
];
