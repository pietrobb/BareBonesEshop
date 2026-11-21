<?php

// Review round 21 (R51, backup and personal data, low):
//  - a GDPR erasure kept the carrier labels (name, address, phone on the printable label and its link) and the
//    browser user-agent of the checkout consent for the 10-year retention;
//  - on MySQL the erasure deleted the API replay responses with a DELETE whose WHERE scanned the table, locking every
//    row it read until the erasure committed (API requests waited);
//  - cli/restore.php onto a new MySQL/MariaDB server created db_name before the refusals and the "yes" (a declined or
//    refused run left an empty database behind).
echo "\n--- Audit round 51: GDPR carrier labels and consent user-agent, API replay erasure locks, restore creates db_name late ---\n";

$r51bDb = Db::get();
$r51bEmail = 'r51b-erase-' . bin2hex(random_bytes(3)) . '@example.test';
$r51bAwb = 'R51B-AWB-' . bin2hex(random_bytes(4));
try {
    $r51bDb->prepare("INSERT INTO customers (email, password_hash, name, phone) VALUES (?, 'x', 'R51B Person', '+421900005151')")->execute([$r51bEmail]);
    $r51bCustomer = (int)$r51bDb->lastInsertId();
    $r51bOrderSql = $r51bDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_id, customer_name, customer_email, shipping_addr,
            payment_method, subtotal, total, currency, store_id, locale) VALUES (?, 'delivered', 'paid', ?, ?, ?, '{}', 'card', 1000, 1000, 'eur', 'default', 'sk')");
    $r51bOrderSql->execute(['R51B-' . bin2hex(random_bytes(3)), $r51bCustomer, 'R51B Person', $r51bEmail]);
    $r51bOrder = (int)$r51bDb->lastInsertId();
    $r51bOrderSql->execute(['R51B-K-' . bin2hex(random_bytes(3)), null, 'R51B Other', 'r51b-other@example.test']);
    $r51bOther = (int)$r51bDb->lastInsertId();
    $r51bConsent = $r51bDb->prepare("INSERT INTO order_consents (order_id, consent_key, version, consent_text, accepted, ip_hash, user_agent) VALUES (?, 'terms', '1', 'Terms', 1, 'h', ?)");
    $r51bConsent->execute([$r51bOrder, 'Mozilla/5.0 R51B']);
    $r51bConsent->execute([$r51bOther, 'Mozilla/5.0 R51B other']);
    $r51bLabel = $r51bDb->prepare("INSERT INTO shipping_labels (order_id, store_id, carrier, awb, status, label_data) VALUES (?, 'default', 'packeta', ?, 'created', ?)");
    $r51bLabel->execute([$r51bOrder, $r51bAwb, base64_encode('%PDF label R51B Person, Return 51, +421900005151')]);
    $r51bLabel->execute([$r51bOther, $r51bAwb . '-K', base64_encode('%PDF label R51B Other')]);
    $r51bRma = $r51bDb->prepare("INSERT INTO rma_requests (store_id, rma_number, order_id, customer_id, status, reason) VALUES ('default', ?, ?, ?, 'approved', 'r')");
    $r51bRma->execute(['R51B-RMA-' . bin2hex(random_bytes(3)), $r51bOrder, $r51bCustomer]);
    $r51bRmaId = (int)$r51bDb->lastInsertId();
    $r51bRma->execute(['R51B-RMA-K-' . bin2hex(random_bytes(3)), $r51bOther, null]);
    $r51bOtherRma = (int)$r51bDb->lastInsertId();
    $r51bReturnLabel = $r51bDb->prepare("INSERT INTO return_labels (store_id, rma_id, provider, label_reference, tracking_number, label_url, idempotency_key)
        VALUES ('default', ?, 'packeta', ?, ?, ?, ?)");
    $r51bReturnLabel->execute([$r51bRmaId, 'R51B-REF', 'R51B-TRACK', 'https://carrier.example/label/r51b-person', 'r51b-' . bin2hex(random_bytes(4))]);
    $r51bReturnLabel->execute([$r51bOtherRma, 'R51B-REF-K', 'R51B-TRACK-K', 'https://carrier.example/label/r51b-other', 'r51b-k-' . bin2hex(random_bytes(4))]);

    GdprService::anonymizeCustomer($r51bCustomer, 'admin');

    $r51bRead = static fn(string $sql, array $params) => (static function () use ($r51bDb, $sql, $params) { $s = $r51bDb->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_NUM); })();
    assert_equals([['', 'terms'], ['Mozilla/5.0 R51B other', 'terms']], $r51bRead('SELECT user_agent, consent_key FROM order_consents WHERE order_id IN (?, ?) ORDER BY order_id', [$r51bOrder, $r51bOther]),
        'R51 GDPR: the consent of the erased customer\'s order stays as proof without the browser user-agent (was: kept 10 years); another order\'s stays');
    assert_equals([['', $r51bAwb, 'packeta'], [base64_encode('%PDF label R51B Other'), $r51bAwb . '-K', 'packeta']],
        $r51bRead('SELECT label_data, awb, carrier FROM shipping_labels WHERE order_id IN (?, ?) ORDER BY order_id', [$r51bOrder, $r51bOther]),
        'R51 GDPR: the carrier label (name, address, phone) of the erased customer\'s order is erased, the parcel number and carrier stay; another order\'s label stays');
    assert_equals([[null, 'R51B-TRACK'], ['https://carrier.example/label/r51b-other', 'R51B-TRACK-K']],
        $r51bRead('SELECT label_url, tracking_number FROM return_labels WHERE rma_id IN (?, ?) ORDER BY rma_id', [$r51bRmaId, $r51bOtherRma]),
        'R51 GDPR: the link to the erased customer\'s printable return label is erased, its tracking number stays; another return\'s stays');
    assert_true(GdprService::PII_REGISTRY['order_consents']['erase'] === 'scrub' && isset(GdprService::PII_REGISTRY['shipping_labels'], GdprService::PII_REGISTRY['return_labels']),
        'R51 GDPR: the PII registry lists the carrier labels and scrubs the consent user-agent');
} catch (Throwable $e) {
    assert_true(false, 'R51 GDPR labels and consents (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    if (isset($r51bOrder, $r51bOther)) {
        foreach (['order_consents', 'shipping_labels'] as $r51bTable) $r51bDb->exec("DELETE FROM {$r51bTable} WHERE order_id IN ({$r51bOrder}, {$r51bOther})");
        $r51bDb->exec("DELETE FROM return_labels WHERE rma_id IN (SELECT id FROM rma_requests WHERE order_id IN ({$r51bOrder}, {$r51bOther}))");
        $r51bDb->exec("DELETE FROM rma_requests WHERE order_id IN ({$r51bOrder}, {$r51bOther})");
    }
}

$r51bRoot = test_fixture_path('r51-backup-gdpr');
mkdir($r51bRoot);
$r51bPhp = static function (array $args, string $stdin = '', ?array $env = null): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
// The erasure of a customer with API replay responses while another connection holds an unrelated replay row.
file_put_contents($r51bRoot . '/locks.php', <<<'PHP'
<?php
[, $phase, $root, $target] = $argv;
require $root . '/src/autoload.php';
$connect = static function (): PDO {
    $db = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $db;
};
$a = $connect();
if ($phase === 'teardown') { $a->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
$a->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
$a->exec("USE `{$target}`");
Db::init($a, 'mysql');
(new Migrator($a, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
Bootstrap::initStoreContext('/', '', 'default');
$a->exec("INSERT INTO customers (email, password_hash, name) VALUES ('r51b-lock@example.test', 'x', 'R51B Lock')");
$customer = (int)$a->lastInsertId();
$a->exec("INSERT INTO api_clients (key_id, token_hash, store_id, name, scopes) VALUES ('r51b-lock', 'h', 'default', 'R51B ERP', '[]')");
$client = (int)$a->lastInsertId();
$idem = $a->prepare("INSERT INTO api_idempotency (store_id, client_id, idempotency_key, request_hash, response_status, response_body) VALUES ('default', ?, ?, 'h', 201, ?)");
$idem->execute([$client, 'r51b-other', json_encode(['order' => ['customer_email' => 'someone-else@example.test']])]);
$other = (int)$a->lastInsertId();
$idem->execute([$client, 'r51b-erase', json_encode(['order' => ['customer_email' => 'r51b-lock@example.test']])]);
$b = $connect();
$b->exec("USE `{$target}`");
$b->beginTransaction();
// By primary key: only that row is locked (an API request replaying or storing its own response).
$b->exec("UPDATE api_idempotency SET response_status = 200 WHERE id = {$other}");
$a->exec('SET SESSION innodb_lock_wait_timeout = 1');
try { GdprService::anonymizeCustomer($customer, 'admin'); $out = 'erased'; } catch (Throwable $e) { $out = 'blocked: ' . $e->getMessage() . ' ' . substr($e->getTraceAsString(), 0, 400); }
$b->rollBack();
echo json_encode([$out, $a->query("SELECT idempotency_key FROM api_idempotency ORDER BY idempotency_key")->fetchAll(PDO::FETCH_COLUMN)]);
PHP);
if (getenv('TEST_MYSQL_DSN')) {
    $r51bTarget = 'bbe_r51b_locks_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
    try {
        [$r51bCode, $r51bOut] = $r51bPhp([$r51bRoot . '/locks.php', 'run', dirname(TEST_CLI_DIR), $r51bTarget]);
        assert_equals([0, ['erased', ['r51b-other']]], [$r51bCode, json_decode($r51bOut, true) ?? $r51bOut],
            'R51 mysql GDPR: the erasure deletes the API replay response naming the customer without waiting on another client\'s row (was: lock wait timeout)');
    } finally {
        $r51bPhp([$r51bRoot . '/locks.php', 'teardown', dirname(TEST_CLI_DIR), $r51bTarget]);
    }

    // cli/restore.php onto a server without db_name: refused or declined, nothing is created.
    $r51bMissing = 'bbe_r51b_missing_' . bin2hex(random_bytes(4));
    $r51bData = sys_get_temp_dir() . '/' . AppConfig::SUITE_DIR_PREFIX . 'r51b-' . bin2hex(random_bytes(4));
    mkdir($r51bData, 0700);
    preg_match('/host=([^;]+)/', (string)getenv('TEST_MYSQL_DSN'), $r51bHost);
    preg_match('/port=(\d+)/', (string)getenv('TEST_MYSQL_DSN'), $r51bPort);
    $r51bConfig = (array)require AppConfig::path(dirname(TEST_CLI_DIR));
    file_put_contents($r51bData . '/' . AppConfig::SUITE_FILE, '<?php return ' . var_export(['db_driver' => 'mysql', 'db_host' => $r51bHost[1] ?? '127.0.0.1',
        'db_port' => (int)($r51bPort[1] ?? 3306), 'db_name' => $r51bMissing, 'db_charset' => 'utf8mb4',
        'db_user' => (string)getenv('TEST_MYSQL_USER'), 'db_password' => (string)getenv('TEST_MYSQL_PASS')] + $r51bConfig, true) . ';');
    $r51bServer = new PDO(preg_replace('/;?dbname=[^;]*/', '', (string)getenv('TEST_MYSQL_DSN')), (string)getenv('TEST_MYSQL_USER'), (string)getenv('TEST_MYSQL_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r51bExists = static fn(): int => (int)$r51bServer->query('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $r51bServer->quote($r51bMissing))->fetchColumn();
    $r51bEnv = ['BBE_SUITE_DATA_DIR' => $r51bData, 'BBE_SUITE_APP_ROOT' => (string)realpath(dirname(TEST_CLI_DIR))] + getenv();
    try {
        [$r51bCode, $r51bOut] = $r51bPhp([TEST_CLI_DIR . '/restore.php', '2026/11/20/eshop-r51b.sql'], '', $r51bEnv);
        assert_true($r51bCode === 1 && str_contains($r51bOut, 'REFUSED') && $r51bExists() === 0,
            "R51 restore: onto a server without db_name and without --i-stopped-writers it is refused and creates nothing (was: an empty db_name stayed; exit {$r51bCode}: " . substr(trim($r51bOut), 0, 300) . ')');
        [$r51bCode, $r51bOut] = $r51bPhp([TEST_CLI_DIR . '/restore.php', '2026/11/20/eshop-r51b.sql', '--i-stopped-writers'], "no\n", $r51bEnv);
        assert_true($r51bCode === 1 && str_contains($r51bOut, 'Aborted') && $r51bExists() === 0,
            "R51 restore: answering \"no\" to the restore into a missing db_name creates nothing (was: created before the question; exit {$r51bCode}: " . substr(trim($r51bOut), 0, 300) . ')');
    } finally {
        $r51bServer->exec('DROP DATABASE IF EXISTS `' . $r51bMissing . '`');
        @unlink($r51bData . '/' . AppConfig::SUITE_FILE);
        @rmdir($r51bData);
    }
} else {
    print "  [SKIP] R51 mysql GDPR replay locks and restore into a missing db_name (set TEST_MYSQL_DSN)\n";
}
foreach (glob($r51bRoot . '/*') ?: [] as $r51bFile) unlink($r51bFile);
rmdir($r51bRoot);
