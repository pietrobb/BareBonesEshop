<?php
declare(strict_types=1);

/**
 * The config.php an application root reads. Normally <root>/config.php.
 *
 * The test suite runs on its own copy of the developer's config.php with every outbound integration taken out
 * (cli/tests/TestHarness.php), so a test never loads an enabled extension, posts to a real outbound webhook, mails
 * through a real provider or calls a payment gateway with real keys (review round 17). The processes the suite starts
 * (php -S servers, workers, CLI scripts) inherit it through the environment, but only for the same application root:
 * a fixture shop the suite builds in a temporary directory keeps its own config.php.
 */
final class AppConfig
{
    public const SUITE_FILE = 'suite-config.php';

    public const SUITE_DIR_PREFIX = 'barebones-suite-data-';

    public static function path(string $root): string
    {
        $dir = self::suiteDataDir($root);
        if ($dir !== null && is_file($dir . '/' . self::SUITE_FILE)) return $dir . '/' . self::SUITE_FILE;
        return $root . '/config.php';
    }

    /**
     * The test suite's data directory, inherited through the environment by the processes the suite starts, for this
     * same application root. R18: only on the command line (the suite, its php -S servers and CLI children) and only a
     * suite directory in the system temp directory: a web request or a stray variable in a production environment can
     * no longer point the shop at another config.php or data directory.
     */
    public static function suiteDataDir(string $root): ?string
    {
        if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) return null;
        $dir = getenv('BBE_SUITE_DATA_DIR');
        $suiteRoot = getenv('BBE_SUITE_APP_ROOT');
        if (!is_string($dir) || $dir === '' || !is_string($suiteRoot) || $suiteRoot === '' || realpath($root) !== $suiteRoot) return null;
        $real = realpath($dir);
        $temp = realpath(sys_get_temp_dir());
        if ($real === false || $temp === false || dirname($real) !== $temp || !str_starts_with(basename($real), self::SUITE_DIR_PREFIX)) return null;
        return $dir;
    }

    /**
     * The suite's config: the developer's config (database, app_url, keys stay, the suite runs in this environment) with
     * everything that reaches outside the machine reset to config.example.php. With $privateDir every private directory
     * and log file moves under it (R18: the suite left ~330 jobs in the developer's jobs/pending for their worker to run
     * with the real config, overwrote an invoice and wrote into their logs). R19: the SQLite database file too (the
     * suite's processes marked the developer's event_outbox rows delivered); the suite's own database is that file.
     */
    public static function suiteConfigForTesting(array $config, array $example, ?string $privateDir = null): array
    {
        if ($privateDir !== null) {
            $privateDir = rtrim(str_replace('\\', '/', $privateDir), '/');
            $config['private_runtime_dir'] = $privateDir;
            foreach (self::SUITE_PRIVATE_PATHS as $key => $relative) $config[$key] = $privateDir . '/' . $relative;
        }
        foreach (self::SUITE_RESET as $key) {
            if (array_key_exists($key, $example)) {
                $config[$key] = $example[$key];
            } else {
                unset($config[$key]);
            }
        }
        foreach (['integration', 'metrics'] as $adapter) {
            if (isset($config['adapters']) && is_array($config['adapters'])) {
                $config['adapters'][$adapter] = $example['adapters'][$adapter] ?? null;
            }
        }
        if (isset($config['provider_defaults']) && is_array($config['provider_defaults'])) {
            $config['provider_defaults']['mail'] = $example['provider_defaults']['mail'] ?? 'log';
        }
        return $config;
    }

    /** Where the suite's copy keeps what config.php puts in the private directory (relative to the suite's one). */
    private const SUITE_PRIVATE_PATHS = [
        'db_sqlite_path' => 'db/shop.sqlite',
        'jobs_dir' => 'jobs', 'invoice_storage_dir' => 'invoices', 'backup_storage_dir' => 'backups',
        'backup_log_file' => 'logs/backup.log', 'backup_recovery_dir' => 'restore-recovery',
        'structured_log_file' => 'logs/application.jsonl', 'metrics_log_file' => 'logs/metrics.jsonl',
        'update_storage_dir' => 'updates', 'private_cache_dir' => 'cache', 'digital_assets_base_dir' => 'downloads',
        'gdpr_salt_file' => 'secrets/gdpr_salt',
    ];

    /** Extensions, outbound webhooks, mail/ERP/carrier providers and integrations, gateway credentials, alert mail. */
    private const SUITE_RESET = [
        'extensions', 'outbound_webhooks', 'providers', 'integrations',
        'merchant_notifications', 'incident_alerts', 'drift_guard', 'stock_alerts', 'cart_reminder',
        'stripe_secret_key', 'stripe_publishable_key', 'stripe_webhook_secret',
        'gopay_goid', 'gopay_client_id', 'gopay_client_secret', 'gopay_env',
        'comgate_merchant', 'comgate_secret', 'comgate_test',
        'thepay_merchant_id', 'thepay_project_id', 'thepay_password', 'thepay_env',
        'payu_client_id', 'payu_client_secret', 'payu_second_key', 'payu_pos_id', 'payu_env',
        'shipping_webhook_secrets',
    ];
}
