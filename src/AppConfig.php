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

    public static function path(string $root): string
    {
        $dir = getenv('BBE_SUITE_DATA_DIR');
        $suiteRoot = getenv('BBE_SUITE_APP_ROOT');
        if (is_string($dir) && $dir !== '' && is_string($suiteRoot) && $suiteRoot !== ''
            && is_file($dir . '/' . self::SUITE_FILE) && realpath($root) === $suiteRoot) {
            return $dir . '/' . self::SUITE_FILE;
        }
        return $root . '/config.php';
    }

    /**
     * The suite's config: the developer's config (database, private directories, app_url, keys stay, the suite runs
     * in this environment) with everything that reaches outside the machine reset to config.example.php.
     */
    public static function suiteConfigForTesting(array $config, array $example): array
    {
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
