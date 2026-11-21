<?php

// Review round 51 (high): a restore set copies of config.php and the themes aside next to the live ones, in the web
// root (config.php.from-backup, themes.from-backup/), and no web server rule denied them: the old server's database
// password, admin_identity_key and gateway keys were served with 200. Migration 20261113190000 summed every other open
// order of the year for each open order (OssThreshold::recordOrder() decided a crossing eagerly): 3 000 orders took
// 143 s on SQLite, 1 000 took 500 s on MySQL.
echo "\n--- Audit round 51: restored copies outside the web root, linear oss_threshold_totals migration ---\n";
$r51hRoot = dirname(TEST_CLI_DIR);
$r51hBase = test_fixture_path('r51-highs');
@mkdir($r51hBase, 0777, true);

// 1. *.from-backup is denied by the bundled rules: .htaccess (router.php applies it) and nginx.conf.
$r51hWeb = $r51hBase . '/web';
@mkdir($r51hWeb . '/themes.from-backup/default', 0777, true);
copy($r51hRoot . '/router.php', $r51hWeb . '/router.php');
copy($r51hRoot . '/.htaccess', $r51hWeb . '/.htaccess');
file_put_contents($r51hWeb . '/config.php.from-backup', "<?php return ['db_pass' => 'r51-secret'];");
file_put_contents($r51hWeb . '/themes.from-backup/default/style.css', 'body{}');
file_put_contents($r51hWeb . '/index.php', '<?php echo "shop";');
$r51hProbe = stream_socket_server('tcp://127.0.0.1:0');
$r51hPort = (int)substr(strrchr((string)stream_socket_get_name($r51hProbe, false), ':'), 1);
fclose($r51hProbe);
$r51hServer = test_spawn_server([PHP_BINARY, '-S', "127.0.0.1:{$r51hPort}", 'router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $r51hBase . '/server.log', 'a'], 2 => ['file', $r51hBase . '/server.log', 'a']], $r51hPipes, $r51hWeb);
try {
    for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $r51hPort) === false; $i++) usleep(100000);
    foreach (['/config.php.from-backup', '/CONFIG.PHP.FROM-BACKUP', '/themes.from-backup/default/style.css', '/themes.from-backup/'] as $r51hPath) {
        $r51hHeaders = @get_headers("http://127.0.0.1:{$r51hPort}{$r51hPath}");
        assert_true(is_array($r51hHeaders) && str_contains((string)$r51hHeaders[0], '403'),
            "R51 high 1: {$r51hPath} answers 403 (" . (is_array($r51hHeaders) ? $r51hHeaders[0] : 'no answer') . ')');
    }
} finally {
    if (is_resource($r51hServer)) { proc_terminate($r51hServer); proc_close($r51hServer); }
}
$r51hNginx = (string)file_get_contents($r51hRoot . '/deploy/nginx.conf');
assert_true(substr_count($r51hNginx, 'location ~* (?:^|/)[^/]*\.from-backup(?:/|$) { return 403; }') === 2,
    'R51 high 1: nginx denies *.from-backup at the server level and under /uploads/');
assert_true(!str_contains((string)file_get_contents($r51hRoot . '/src/BackupService.php'), "rtrim(\$root, '/\\\\') . '.from-backup'"),
    'R51 high 1: the restore no longer derives a set-aside path from the live source (next to it, in the web root)');

// 2. Migration 20261113190000 on 3 000 open distance sales without a net runs in one pass (was quadratic).
$r51hChild = $r51hBase . '/oss.php';
file_put_contents($r51hChild, <<<'PHP'
<?php
[, $root, $dir, $driver, $count] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO('sqlite:' . $dir . '/oss-' . getmypid() . '.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$target = 'bbe_r51h_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
if ($driver === 'mysql') { $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4"); $db->exec("USE `{$target}`"); }
Db::init($db, $driver);
try {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '-' . getmypid() . '.lock'))->apply();
    $stores = Bootstrap::stores();
    $stores['stores']['default']['oss_enabled'] = false;
    $stores['stores']['default']['legal']['country'] = 'SK';
    (new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
    Bootstrap::initStoreContext('/', '', 'default');
    $year = (int)ShopTime::date('Y');
    $stmt = $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
        total, net_total, currency, status, payment_status, payment_method, shipping_method, store_id, vat_scenario, created_at)
        VALUES (?, 'R51H', 'r51h@example.test', '', '{\"country\":\"AT\"}', 120, 0, 20, 0, 120, 100, 'eur', 'pending', 'pending', 'bank_transfer', 'gls', 'default', 'oss', ?)");
    $db->beginTransaction();
    for ($i = 0; $i < (int)$count; $i++) $stmt->execute(['R51H-' . $i, $year . '-01-02 12:00:00']);
    $db->commit();
    // As the 2.1.2 -> 2.1.3 path runs it: orders without a net, the activations table (a later migration) not there yet.
    $db->exec('UPDATE orders SET oss_net = NULL');
    $db->exec('DROP TABLE oss_threshold_activations');
    Db::forgetSchema($db);
    $start = microtime(true);
    // As Migrator::apply() runs it: in one transaction on SQLite, none on MySQL.
    if ($driver === 'sqlite') $db->beginTransaction();
    (require $root . '/migrations/20261113190000_oss_threshold_totals.php')->up($db, $driver === 'sqlite');
    if ($db->inTransaction()) $db->commit();
    $seconds = microtime(true) - $start;
    $left = (int)$db->query('SELECT COUNT(*) FROM orders WHERE oss_net IS NULL')->fetchColumn();
    $sum = (int)$db->query('SELECT SUM(oss_net) FROM orders')->fetchColumn();
    echo 'R51HRESULT' . json_encode(['seconds' => round($seconds, 2), 'left' => $left, 'sum' => $sum]);
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if ($driver === 'mysql') $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
PHP);
$r51hCount = 3000;
foreach (['sqlite', 'mysql'] as $r51hDriver) {
    if ($r51hDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { print "  [SKIP] R51 oss migration native MySQL (set TEST_MYSQL_DSN)\n"; continue; }
    $r51hProcess = proc_open([PHP_BINARY, $r51hChild, $r51hRoot, $r51hBase, $r51hDriver, (string)$r51hCount], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r51hOut);
    fclose($r51hOut[0]);
    $r51hRaw = stream_get_contents($r51hOut[1]) . "\n" . stream_get_contents($r51hOut[2]);
    fclose($r51hOut[1]); fclose($r51hOut[2]);
    $r51hExit = proc_close($r51hProcess);
    $r51hAt = strrpos($r51hRaw, 'R51HRESULT');
    $r51hResult = $r51hAt === false ? null : json_decode(strtok(substr($r51hRaw, $r51hAt + 10), "\n"), true);
    assert_true($r51hExit === 0 && is_array($r51hResult), "R51 high 2 {$r51hDriver}: the isolated migration completes (" . ($r51hAt === false ? substr($r51hRaw, 0, 1500) : '') . ')');
    if (!is_array($r51hResult)) continue;
    assert_true($r51hResult['left'] === 0 && $r51hResult['sum'] === 100 * $r51hCount,
        "R51 high 2 {$r51hDriver}: every open order gets its net (" . json_encode($r51hResult) . ')');
    // Quadratic it took ~143 s (SQLite) and ~4 500 s (MySQL) here; linear ~0.5 s and ~11 s.
    assert_true($r51hResult['seconds'] < ($r51hDriver === 'sqlite' ? 15 : 90), "R51 high 2 {$r51hDriver}: {$r51hCount} open orders migrate in one pass, not one year sum per order ({$r51hResult['seconds']} s)");
    print "R51 oss migration {$r51hDriver}: " . json_encode($r51hResult) . "\n";
}
