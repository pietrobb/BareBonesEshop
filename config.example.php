<?php
/**
 * BareBones Shop — Configuration TEMPLATE
 *
 * Copy this file to `config.php` and fill in your own values.
 * `config.php` is gitignored so your secrets never land in the repo.
 *   cp config.example.php config.php
 * or let `php cli/setup.php install` create it with a generated admin_identity_key.
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
    // Unpaid offline orders (payment service_type => seconds) are cancelled and their stock
    // released after this age; the buyer gets the order_cancelled e-mail. Types not listed
    // (cod, invoice, offline) never auto-expire. Omitted key = bank_transfer after 14 days.
    'offline_order_expiry_seconds' => ['bank_transfer' => 1209600],
    // Customers can request a return this many days after delivery (EU withdrawal period: 14). 0 = no limit.
    // The admin can still open a return later (warranty, goodwill).
    'rma_return_window_days' => 14,

    // Admin
    'admin_name'     => '', // optional admin brand; defaults to the translated BareBonesShop name
    'admin_user'     => 'admin',
    'admin_password' => '', // legacy password_hash() fallback — NEVER plaintext
    // DB admin identities take precedence once at least one active account exists.
    // Inject a random 32-byte base64/hex key; it encrypts TOTP seeds and never belongs in DB exports.
    'admin_identity_key' => '',
    'admin_session_idle_seconds' => 1800,
    'admin_session_lifetime_seconds' => 28800,
    // External API v1: requests per minute per API key, across all source addresses.
    'api_client_rate_limit' => 600,
    // Checkout: per IP (10/min) and per session (5 per 15 min) always apply. Optional shop-wide cap on
    // checkout attempts per minute (0 = off); refused attempts never count towards it.
    'checkout_global_limit' => 0,
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
    // Off by default: a reminder is marketing mail, so it is only sent to shoppers who ticked the
    // checkout consent 'cart_reminder' (see checkout_consents below); carts are only stored with
    // that consent. Reminders carry List-Unsubscribe / one-click List-Unsubscribe-Post headers.
    'cart_reminder' => ['enabled' => false, 'delay_hours' => 24, 'max_age_hours' => 72],
    // Shop-owned public URL scheme; core routes stay reachable and 301 to the alias.
    // 'url_aliases' => ['/kniha/{slug}' => '/product/{slug}', '/knihy' => '/category/knihy', '/kosik' => '/?page=cart'],
    'url_aliases' => [],
    // Core drift guard (php cli/drift-guard.php check --notify, daily cron). Shop code lives in
    // themes/<shop> or config, so only real core edits are reported. recipients fall back to admin_email.
    'drift_guard' => ['recipients' => [], 'exclude' => []],
    // Merchant low / critical stock e-mail (worker). Available = warehouse_stock qty - reserved.
    // Thresholds are inclusive (5 left = low); 0 is always critical. Per-product overrides in admin/CSV.
    // Mails only on a downward crossing (ok→low→critical→0), re-arms after restock above low.
    'stock_alerts' => [
        'enabled'     => true,
        'low'         => 5,
        'critical'    => 2,
        'recipients'  => [],   // empty = merchant_notifications.recipients (then admin_email)
        'digest_hour' => null, // e.g. 7 = daily summary of everything at/below low at 07:00; null = off
        'locale'      => '',   // e-mail language; empty = stores.json default_locale
    ],

    // Security
    // Reverse proxies / load balancers (exact REMOTE_ADDR values): their X-Forwarded-For gives the client IP and their
    // X-Forwarded-Proto: https marks the request as https (HSTS, secure cookies) when TLS ends at the proxy.
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
        'payment' => [], // stripe/payu/gopay/comgate/thepay/mock are built in; register a custom gateway as code => ['class' => <your PaymentAdapter class>]
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
            'rates_timeout' => 5, // checkout waits on the rate call; a failing carrier is skipped
        ],
        'http_erp' => ['base_url' => '', 'api_key' => '', 'timeout' => 15],
    ],
    'operations' => [
        'worker_cron' => '', // e.g. * * * * *; install it in the platform scheduler too
        'monitor_cron' => '', // e.g. */5 * * * * php cli/monitor.php
        // Live nginx site config (absolute path, readable by PHP): doctor and staging-preflight warn
        // when its PHP location lacks the @redirect fallback that store-prefixed scripts need. '' = not checked.
        'nginx_config' => '',
        // Directory the web server serves when the shop lives in a subdirectory of it (absolute path).
        // '' = guessed from the directory name (public_html, httpdocs, htdocs, wwwroot, html, www, web);
        // doctor and staging-preflight fail private paths inside it.
        'document_root' => '',
    ],
    // Technical incidents only; normal checkout validation never sends mail.
    // Run worker + monitor cron. Mail 'log' is simulation, not delivery evidence.
    'incident_alerts' => [
        'enabled' => false,
        'recipients' => [], // merchant_notifications.recipients, then admin_email
        'locale' => '',
        'window_seconds' => 900,
        'checkout_failures' => 3,
        'cooldown_seconds' => 900,
        'lease_seconds' => 300,
        'heartbeat_max_age_seconds' => 300,
    ],
    'monitoring' => [
        'backup_rpo_seconds' => 86400,
        'queue_pending_warning' => 100,
        'failed_jobs_warning' => 1,
        'failed_mail_warning' => 1,
        'payment_incidents_critical' => 1,
        'webhook_incidents_warning' => 1,
        // Payment funnel alarm: >= min_started real online-payment orders (older than the
        // grace period, inside the window) with no gateway session or no payment is critical.
        'payment_funnel_window_seconds' => 86400,
        'payment_funnel_grace_seconds' => 1800,
        'payment_funnel_min_started' => 3,
        // Browser CSP reports (/api/v1/csp-report). A blocked form-action is critical once
        // reported by csp_form_action_critical distinct clients (IP hashes), or by
        // csp_form_action_reports_critical reports in total from >= 2 requests - that fallback only
        // applies while trusted_proxies is empty (behind an unlisted proxy/CDN every report has the
        // same IP) and one client CAN raise it. 0 disables a threshold.
        'csp_window_seconds' => 86400,
        'csp_form_action_critical' => 2,
        'csp_form_action_reports_critical' => 5,
        'csp_violations_warning' => 20,
        // Post-deploy smoke (cli/smoke-checkout.php) sends this in X-BareBones-Synthetic; its order is
        // flagged synthetic, sends no mail and is closed right after the gateway redirect.
        // At least 32 random characters; keep it in deployment secrets. Empty = disabled.
        'synthetic_token' => '',
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
        //     // https only; 'allow_insecure_http' => true accepts http:// for a local dev receiver
        // ],
    ],
    // The worker delivers outbox events before queued jobs; one sweep stops claiming new events
    // after this many seconds, and an endpoint that timed out twice in a sweep waits for the next one.
    'outbox_sweep_budget_seconds' => 20,

    // Extensions to load from extensions/<code>/extension.php (docs/EXTENSIONS.md). They add
    // providers, domain event listeners and storefront data without editing core files;
    // updates never overwrite extensions/ and the drift guard does not report it.
    'extensions' => [
        // 'acme-loyalty',
    ],

    // Dev only: accept /api/v1/webhooks/payment/mock even when the
    // active payment adapter is not MockPayment. NEVER enable in production —
    // the mock webhook marks orders as paid. Pair with 'mock_webhook_secret'.
    'allow_mock_webhook' => false,
    'mock_webhook_secret' => '',

    // Carrier webhook HMAC secrets, keyed by carrier ID. Environment variables
    // such as SHIP_PACKETA_WEBHOOK_SECRET take precedence.
    // Env names write "-" as "_": carrier http-carrier reads SHIP_HTTP_CARRIER_WEBHOOK_SECRET.
    // Signature = hex HMAC-SHA256 of the raw body; a carrier that sends X-Shipping-Timestamp
    // (Unix seconds, ±300 s) signs "<timestamp>.<raw body>" instead.
    'shipping_webhook_secrets' => [
        'packeta' => '',
        'gls' => '',
    ],
    // true = reject carrier webhooks without a signed X-Shipping-Timestamp (replay protection);
    // keep false while a carrier can only sign the body.
    'shipping_webhook_require_timestamp' => false,

    // Stripe (when using StripePayment adapter)
    'stripe_secret_key'      => '',
    'stripe_publishable_key' => '',
    'stripe_webhook_secret'  => '',

    // GoPay (provider code gopay). Notifications need no secret: each one is verified by an
    // authenticated payment inquiry. Env PAY_GOPAY_* overrides these values.
    'gopay_goid'          => '',
    'gopay_client_id'     => '',
    'gopay_client_secret' => '',
    'gopay_env'           => 'sandbox', // 'production' uses gate.gopay.cz

    // Comgate (provider code comgate). Set the push notification URL in the Comgate client portal to
    // <app_url>/api/v1/webhooks/payment/comgate; every push is checked against the
    // merchant/secret and confirmed by the status API. Env PAY_COMGATE_* overrides these values.
    'comgate_merchant' => '',
    'comgate_secret'   => '',
    'comgate_test'     => true, // false creates real (production) payments

    // ThePay (provider code thepay). Allow the shop server's IP in the ThePay administration
    // (Implementation). Notifications need no secret: each one is confirmed by the signed payment
    // detail call. Env PAY_THEPAY_* overrides these values.
    'thepay_merchant_id' => '',
    'thepay_project_id'  => '',
    'thepay_password'    => '',
    'thepay_env'         => 'demo', // 'production' uses api.thepay.cz / gate.thepay.cz

    // PayU (provider code payu). Env PAY_PAYU_* overrides these values.
    'payu_client_id'     => '',
    'payu_client_secret' => '',
    'payu_second_key'    => '',
    'payu_pos_id'        => '',
    'payu_env'           => 'sandbox', // 'production' uses secure.payu.com

    // Digital files are stored below this directory; digital_assets.storage_path
    // contains only a relative path such as ebooks/manual.pdf.
    // An interrupted download may resume (HTTP Range) for this long without counting another download.
    'digital_download_resume_minutes' => 30,
    // Kept with the other private runtime data (the Docker sample's private volume; created by setup.php apply).
    'digital_assets_base_dir' => dirname(__DIR__) . '/bareboneseshop-private/downloads',

    // Product image uploads written by the LocalStorage adapter and served at uploads_url. File
    // backups (cli/backup.php, unless config/backup.json sets "include_files": false) and
    // cli/restore.php --files use this directory too.
    'uploads_dir' => __DIR__ . '/uploads',
    'uploads_url' => '/uploads',

    // Deployments define the exact legal text and version they require. Optional
    // product_types/countries arrays make a checkbox context-specific.
    'checkout_consents' => [
        // Optional (never required) consent for the abandoned-cart reminder; without it no cart
        // reminder is stored or sent. The key must be 'cart_reminder'.
        // 'cart_reminder' => [
        //     'version' => '2026-10-16', 'required' => false,
        //     'text' => 'Send me one e-mail reminder if I do not finish this order.',
        // ],
        // 'text' is one string or a per-locale map; a relative 'url' gets the store prefix (/cs/?page=terms).
        // 'digital_delivery' => [
        //     'version' => '2026-01-01', 'required' => true,
        //     'text' => [
        //         'en' => 'I request immediate delivery of the digital content and acknowledge the loss of my withdrawal right once delivery begins.',
        //         'sk' => 'Žiadam o okamžité dodanie digitálneho obsahu a beriem na vedomie, že jeho začatím strácam právo na odstúpenie od zmluvy.',
        //     ],
        //     'url' => '/?page=terms', 'product_types' => ['downloadable'],
        // ],
    ],

    // Queue backend: file (zero dependency), database (durable/concurrent), or redis.
    'queue' => [
        'backend' => 'file',
        'lease_seconds' => 300,
        'redis' => [
            'host' => '127.0.0.1', 'port' => 6379, 'database' => 0,
            'password' => '', 'timeout' => 2.0, 'prefix' => 'bbe:queue:',
            // Completed job hashes expire after this; the daily cleanup trims the completed index.
            'completed_ttl_seconds' => 604800,
        ],
    ],

    // Private runtime files
    'jobs_dir'            => dirname(__DIR__) . '/bareboneseshop-private/jobs',
    'invoice_storage_dir' => dirname(__DIR__) . '/bareboneseshop-private/invoices',
    'backup_storage_dir'  => dirname(__DIR__) . '/bareboneseshop-private/backups',
    'backup_storage_adapter' => 'LocalBackupStorage', // MountedBackupStorage requires an operator-mounted off-site target + sentinel
    'backup_log_file'     => dirname(__DIR__) . '/bareboneseshop-private/logs/backup.log',
    // Keeps the pre-restore MySQL dump (0600) when a restore fails after dropping db_name; default <private_runtime_dir>/restore-recovery
    'backup_recovery_dir' => dirname(__DIR__) . '/bareboneseshop-private/restore-recovery',
    'structured_log_file' => dirname(__DIR__) . '/bareboneseshop-private/logs/application.jsonl',
    'structured_log_max_bytes' => 10485760, // rotate application.jsonl at 10 MB …
    'structured_log_max_files' => 5,        // … and keep application.jsonl.1 … .5
    'metrics_log_file'    => dirname(__DIR__) . '/bareboneseshop-private/logs/metrics.jsonl',
    'update_storage_dir'  => dirname(__DIR__) . '/bareboneseshop-private/updates',
    'update_public_key'   => '', // Ed25519 public key (base64/hex or absolute key-file path)
    'update_snapshots_keep' => 3, // newest pre-update snapshots kept (full database copies); older ones are deleted after an update
    // Required only for MySQL/MariaDB packages containing migrations. Reserve
    // this disposable schema name and grant db_user access, but do not create it;
    // it must end with _update_probe. The updater refuses to overwrite an existing schema.
    'update_mysql_probe_database' => '',
    'update_mysql_dump_binary'    => 'mysqldump', // use mariadb-dump where appropriate
    'update_mysql_client_binary'  => 'mysql',     // use mariadb where appropriate
    // MySQL/MariaDB backup restore (php cli/restore.php ... --i-stopped-writers) imports the dump here
    // first and verifies it before replacing db_name. Must end with _restore_scratch; default
    // <db_name>_restore_scratch. db_user needs CREATE/DROP on it (and on db_name) — see OPERATIONS_RUNBOOK.md.
    'backup_mysql_restore_scratch_database' => '',
    'gdpr_salt_file'      => dirname(__DIR__) . '/bareboneseshop-private/secrets/gdpr_salt',

    // GDPR — prefer injecting this secret; otherwise it is generated in gdpr_salt_file.
    'gdpr_hash_salt' => '',
];
