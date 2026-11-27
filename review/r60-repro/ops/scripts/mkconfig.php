<?php
// usage: php mkconfig.php <shopRoot> <privateDir> <sqlite|mysql> <dbname> <updateDir|-> <port>
[, $root, $priv, $driver, $db, $upd, $port] = $argv;
$config = require $root . '/config.example.php';
$config['db_driver'] = $driver;
$config['private_runtime_dir'] = $priv;
$config['db_sqlite_path'] = $priv . '/db/shop.sqlite';
foreach (['private_cache_dir' => 'cache', 'jobs_dir' => 'jobs', 'invoice_storage_dir' => 'invoices', 'backup_storage_dir' => 'backups', 'backup_recovery_dir' => 'restore-recovery', 'digital_assets_base_dir' => 'downloads'] as $k => $d) $config[$k] = $priv . '/' . $d;
$config['backup_log_file'] = $priv . '/logs/backup.log';
$config['structured_log_file'] = $priv . '/logs/application.jsonl';
$config['metrics_log_file'] = $priv . '/logs/metrics.jsonl';
$config['gdpr_salt_file'] = $priv . '/secrets/gdpr_salt';
$config['update_storage_dir'] = $upd === '-' ? $priv . '/updates' : $upd;
$config['update_public_key'] = '';
$config['app_url'] = 'http://127.0.0.1:' . $port;
$config['admin_identity_key'] = base64_encode(str_repeat('E', 32));
$config['adapters']['payment'] = 'MockPayment';
$config['provider_defaults']['payment'] = 'mock';
$config['allow_mock_webhook'] = true;
$config['mock_webhook_secret'] = 'barebones-e2e-webhook-secret-2026';
$config['mock_result'] = 'success';
if ($driver === 'mysql') {
    $config['db_host'] = '127.0.0.1'; $config['db_port'] = 3306; $config['db_name'] = $db;
    $config['db_user'] = 'bbe_ci'; $config['db_password'] = 'bbe-ci-pass';
    $config['update_mysql_dump_binary'] = getenv('DUMPBIN') ?: '/tmp/claude-0/-home-user-BareBonesEshop/0d748840-4251-5787-841e-a11f82aad9b2/scratchpad/r60/ops/bin/mysqldump';
    $config['update_mysql_client_binary'] = getenv('CLIBIN') ?: '/tmp/claude-0/-home-user-BareBonesEshop/0d748840-4251-5787-841e-a11f82aad9b2/scratchpad/r60/ops/bin/mysql';
}
foreach (['db', 'cache', 'jobs', 'invoices', 'backups', 'logs', 'secrets'] as $d) @mkdir($priv . '/' . $d, 0750, true);
file_put_contents($root . '/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");
echo "config written\n";
