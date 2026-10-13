<?php
require_once __DIR__ . '/../../cli/cli-only.php';

$root = dirname(__DIR__, 2);
$configPath = $root . '/config.php';
if (is_file($configPath)) {
    fwrite(STDERR, "Refusing to replace existing config.php; run G12 qualification only in a fresh checkout.\n");
    exit(1);
}

$driver = strtolower((string)(getenv('E2E_DB_DRIVER') ?: 'sqlite'));
if (!in_array($driver, ['sqlite', 'mysql'], true)) {
    throw new InvalidArgumentException('E2E_DB_DRIVER must be sqlite or mysql');
}
$runtime = (string)(getenv('E2E_RUNTIME_DIR') ?: (sys_get_temp_dir() . '/barebones-e2e-runtime'));
$absolute = str_starts_with($runtime, '/') || str_starts_with($runtime, '\\\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $runtime);
if (!$absolute || !str_contains(strtolower(str_replace('\\', '/', $runtime)), 'barebones-e2e')) {
    throw new RuntimeException('E2E runtime must be an absolute disposable path containing barebones-e2e');
}

$config = require $root . '/config.example.php';
$config['db_driver'] = $driver;
$config['private_runtime_dir'] = $runtime;
$config['db_sqlite_path'] = $runtime . '/db/shop.sqlite';
$config['private_cache_dir'] = $runtime . '/cache';
$config['jobs_dir'] = $runtime . '/jobs';
$config['invoice_storage_dir'] = $runtime . '/invoices';
$config['backup_storage_dir'] = $runtime . '/backups';
$config['backup_log_file'] = $runtime . '/logs/backup.log';
$config['structured_log_file'] = $runtime . '/logs/application.jsonl';
$config['update_storage_dir'] = $runtime . '/updates';
$config['gdpr_salt_file'] = $runtime . '/secrets/gdpr_salt';
$config['app_url'] = (string)(getenv('E2E_BASE_URL') ?: 'http://127.0.0.1:8080');
$config['admin_identity_key'] = (string)(getenv('BBE_ADMIN_IDENTITY_KEY') ?: base64_encode(str_repeat('E', 32)));
$config['adapters']['payment'] = 'MockPayment';
$config['provider_defaults']['payment'] = 'mock';
$config['allow_mock_webhook'] = true;
$config['mock_webhook_secret'] = (string)(getenv('E2E_MOCK_WEBHOOK_SECRET') ?: 'barebones-e2e-webhook-secret-2026');
$config['mock_result'] = 'success';
// Card payments leave the shop origin like Stripe Checkout (tests/e2e/mock-gateway.php).
$config['mock_checkout_url'] = rtrim((string)(getenv('E2E_GATEWAY_URL') ?: 'http://127.0.0.1:8099'), '/') . '/checkout';

if ($driver === 'mysql') {
    $database = (string)(getenv('E2E_DB_NAME') ?: 'barebones_e2e_test');
    if (!preg_match('/^[A-Za-z0-9_]+_e2e_test$/', $database)) {
        throw new RuntimeException('Disposable MySQL/MariaDB database must end with _e2e_test');
    }
    $config['db_host'] = (string)(getenv('E2E_DB_HOST') ?: '127.0.0.1');
    $config['db_port'] = (int)(getenv('E2E_DB_PORT') ?: 3306);
    $config['db_name'] = $database;
    $config['db_user'] = (string)(getenv('E2E_DB_USER') ?: 'root');
    $config['db_password'] = (string)(getenv('E2E_DB_PASSWORD') ?: '');
}

foreach (['db', 'cache', 'jobs', 'invoices', 'backups', 'logs', 'updates', 'secrets'] as $directory) {
    $path = $runtime . '/' . $directory;
    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException("Cannot create disposable E2E directory: {$path}");
    }
}

$php = "<?php\n// Generated only in a disposable G12 qualification checkout.\nreturn " . var_export($config, true) . ";\n";
if (file_put_contents($configPath, $php, LOCK_EX) === false) {
    throw new RuntimeException('Cannot write disposable E2E config.php');
}
echo "Prepared disposable {$driver} E2E configuration at {$runtime}\n";
