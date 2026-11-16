<?php

// R38 (low, OSS): one snapshot per count; an admin edit counted exactly under the seller's lock; open orders by the
// store's calendar; documents by their date of supply; a failed count refuses instead of charging home VAT; stale
// conversion rates warn; a cancelled invoice leaves its year total in the same transaction.
echo "\n--- R38 OSS low: snapshot, manual edit, store calendar, date of supply, failed count, rates, atomic cancel ---\n";
$r38lRoot = test_fixture_path('oss-low-r38');
mkdir($r38lRoot);
mkdir($r38lRoot . '/sessions');
$r38lFixture = $r38lRoot . '/request.php';
file_put_contents($r38lFixture, <<<'PHP'
<?php
[, $driver, $phase, $root, $target, $dir, $extra] = $argv;
require $root . '/src/autoload.php';
$connect = static function() use ($driver, $target): PDO {
    $pdo = $driver === 'mysql'
        ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
        : new PDO('sqlite:' . $target);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if ($driver === 'sqlite') $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
};
$db = $connect();
if ($driver === 'mysql') {
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    $db->exec("USE `{$target}`");
} else {
    $db->exec('PRAGMA journal_mode = WAL'); // as Db::get() opens the shop's database
}
Db::init($db, $driver);
$args = json_decode($extra, true) ?: [];
const R38L_VAT_ID = 'SK2038380001';
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '-' . basename($target) . '.lock'))->apply();
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r38l', 'R38L', 'r38l', 0)");
    $cat = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, weight_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'R38L-MUG', 'r38l-mug', 'R38L Mug', 12300, 100, 'active', 500]);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 100, 0)')->execute([$warehouse, 'R38L-MUG']);
    $db->exec("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, requires_online, active, sort_order)
        VALUES ('default', 'r38l_pay', 'manual', 'R38L payment', '', 'offline', 0, 0, 0, 0, 0, 1, 990)");
    foreach (['SK', 'AT'] as $country) $db->prepare("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('default', 'r38l_pay', ?)")->execute([$country]);
    exit(0);
}
$stores = Bootstrap::stores();
$stores['stores']['default']['oss_enabled'] = false;
$stores['stores']['default']['currency'] = 'eur';
$stores['stores']['default']['timezone'] = 'Europe/Bratislava';
$stores['stores']['default']['legal'] = array_merge((array)($stores['stores']['default']['legal'] ?? []), ['country' => 'SK', 'ic_dph' => R38L_VAT_ID, 'seller' => '']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
$year = (int)gmdate('Y');
$totalsRow = static function(int $y, int $net, string $currency = 'eur') use ($db): void {
    $db->prepare("INSERT INTO oss_threshold_totals (store_id, year, currency, net) VALUES ('default', ?, ?, ?)")->execute([$y, $currency, $net]);
};
$n = 0;
// A paid distance sale to an Austrian consumer, as Orders::create() records it (recordOrder() in its transaction).
$order = static function(string $createdAt, int $net, string $currency = 'eur') use ($db, &$n): int {
    $n++;
    $db->beginTransaction();
    $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, net_total, currency, status, payment_status, payment_method, store_id, vat_scenario, created_at)
        VALUES (?, 'R38L', 'r38l@example.test', '{\"country\":\"AT\"}', ?, ?, ?, ?, 'paid', 'paid', 'bank_transfer', 'default', 'domestic_subthreshold', ?)")
        ->execute(['R38L-O' . $n, $net, $net, $net, $currency, $createdAt]);
    $id = (int)$db->lastInsertId();
    OssThreshold::recordOrder($db, $id);
    $db->commit();
    return $id;
};
// Its invoice, as InvoiceService inserts it (OssThreshold::record() in the same transaction).
$invoice = static function(PDO $writer, int $orderId, string $issuedAt, ?string $deliveryDate, int $net, string $currency = 'eur', string $type = 'invoice', ?int $source = null) use (&$n): int {
    $n++;
    $writer->beginTransaction();
    $writer->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, delivery_date, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type, refers_to_invoice_id)
        VALUES (?, 'default', ?, ?, ?, '', ?, 0, ?, ?, 'issued', ?, 'domestic_subthreshold', ?, ?)")
        ->execute([$orderId, 'R38L-' . $n, $issuedAt, $deliveryDate, $type === 'invoice' ? $net : -$net, $type === 'invoice' ? $net : -$net, $currency, 'r38l-' . $n, $type, $source]);
    $id = (int)$writer->lastInsertId();
    OssThreshold::record($writer, $id);
    $writer->commit();
    return $id;
};
$exceeded = static function(string $nowUtc, int $saleNet = 0): bool {
    ShopTime::setNowForTesting(strtotime($nowUtc . ' UTC'));
    Bootstrap::initStoreContext('/', '', 'default');
    VatService::reset();
    return OssThreshold::exceeded($saleNet);
};
$incidents = static fn(): array => $db->query("SELECT severity FROM operational_incidents WHERE code = 'tax.oss_threshold_failed' ORDER BY occurred_at")->fetchAll(PDO::FETCH_COLUMN);
$breakCount = static function(bool $broken) use ($db): void {
    $db->exec($broken ? 'ALTER TABLE oss_threshold_totals RENAME COLUMN net TO r38l_net' : 'ALTER TABLE oss_threshold_totals RENAME COLUMN r38l_net TO net');
    Db::forgetSchema($db);
};
$checks = [];
$out = static function() use (&$checks): void { echo 'R38LRESULT' . json_encode(['checks' => $checks]); };
try {
    if ($phase === 'snapshot') {
        // 9 600 EUR invoiced this year and an open order of 500 EUR: 10 100 EUR whenever it is read.
        $now = $year . '-06-01 12:00:00';
        ShopTime::setNowForTesting(strtotime($now . ' UTC'));
        Bootstrap::initStoreContext('/', '', 'default');
        $open = $order($year . '-05-01 10:00:00', 50000);
        $totalsRow($year, 960000);
        // The doctor's (or a checkout's) count on its own connection; the open order is invoiced right after the
        // year's totals were read, before the open orders are (a real second writer, committed in between).
        $reader = $connect();
        if ($driver === 'mysql') $reader->exec("USE `{$target}`");
        R38LStatement::$hook = static function(R38LStatement $stmt) use ($db, $invoice, $open, $year): bool {
            if (!str_contains($stmt->queryString, 'FROM oss_threshold_totals') || !in_array($year, $stmt->params, true)) return false;
            $invoice($db, $open, $year . '-06-01', $year . '-06-01', 50000);
            return true;
        };
        $reader->setAttribute(PDO::ATTR_STATEMENT_CLASS, [R38LStatement::class]);
        $worst = OssThreshold::worstYear($reader, 'default', $stores['stores']['default'], $stores['stores']);
        $checks[] = ['the invoice was issued between the reads of one count', R38LStatement::$fired];
        $checks[] = ['the count reads one snapshot: 10 100 EUR (was 9 600 EUR, the order neither open nor invoiced)', $worst['sum'] === 1010000];
        $checks[] = ['read again: 10 100 EUR', OssThreshold::worstYear($reader, 'default', $stores['stores']['default'], $stores['stores'])['sum'] === 1010000];
        $checks[] = ['the reader holds no transaction afterwards', !$reader->inTransaction() && $reader->query('SELECT 1')->fetchColumn() == 1];
    } elseif ($phase === 'calendar') {
        // 9 600 EUR invoiced this year; an order placed at 00:30 on 1 January in Bratislava (23:30 UTC on 31 December).
        $totalsRow($year, 960000);
        ShopTime::setNowForTesting(strtotime($year . '-01-01 00:00:00 UTC'));
        $order(($year - 1) . '-12-31 23:30:00', 50000);
        $checks[] = ['the order of 1 January (Bratislava) counts in this year: 10 100 EUR, the next consumer pays their VAT', $exceeded($year . '-06-01 12:00:00')];
        // An order at 23:30 Bratislava on 31 December of last year (22:30 UTC) is not a sale of this year.
        ShopTime::setNowForTesting(strtotime(($year - 1) . '-12-31 22:30:00 UTC'));
        $order(($year - 1) . '-12-31 22:30:00', 2000000);
        ShopTime::setNowForTesting(strtotime($year . '-06-01 12:00:00 UTC'));
        $worst = OssThreshold::worstYear($db, 'default', $stores['stores']['default'], $stores['stores']);
        $checks[] = ['the doctor counts this year 10 100 EUR exactly: the first order in, the second out', $worst['sum'] === 1010000 && $worst['year'] === $year];
    } elseif ($phase === 'supply') {
        // Last year 9 800 EUR; goods supplied on 30 December for 500 EUR, invoiced on 2 January.
        ShopTime::setNowForTesting(strtotime(($year - 1) . '-06-01 12:00:00 UTC'));
        $first = $order(($year - 1) . '-06-01 10:00:00', 980000);
        $invoice($db, $first, ($year - 1) . '-06-01', ($year - 1) . '-06-01', 980000);
        ShopTime::setNowForTesting(strtotime($year . '-01-02 12:00:00 UTC'));
        $late = $order(($year - 1) . '-12-30 10:00:00', 50000);
        $lateInvoice = $invoice($db, $late, $year . '-01-02', ($year - 1) . '-12-30', 50000);
        $totals = static fn(): array => array_map(static fn(array $r): array => [(int)$r['year'], (int)$r['net']], $db->query("SELECT year, net FROM oss_threshold_totals WHERE net <> 0 ORDER BY year")->fetchAll());
        $checks[] = ['the invoice of 2 January counts in the year of its supply (30 December)', $totals() === [[$year - 1, 1030000]]];
        $checks[] = ['last year passed 10 000 EUR: a consumer in Austria pays Austrian VAT this year', $exceeded($year . '-03-01 12:00:00')];
        $checks[] = ['the cache audit agrees with the documents', OssThresholdCache::check($db)['mismatches'] === 0];
        // Its credit note (issued in February) lowers the year of the supply too.
        ShopTime::setNowForTesting(strtotime($year . '-02-10 12:00:00 UTC'));
        $invoice($db, $late, $year . '-02-10', $year . '-02-10', 50000, 'eur', 'credit_note', $lateInvoice);
        $checks[] = ['its credit note counts in the year of the supply', $totals() === [[$year - 1, 980000]]];
        // An install upgraded from c1fe7a9 cached the invoice in its issue year; the R38 migration recounts it.
        $db->exec('DELETE FROM oss_threshold_totals');
        $totalsRow($year - 1, 980000);
        $totalsRow($year, 50000);
        $db->exec("UPDATE invoices SET status = 'cancelled' WHERE type = 'credit_note'");
        $checks[] = ['the c1fe7a9 cache (by issue date) is reported by the audit', OssThresholdCache::check($db)['mismatches'] === 2];
        (require $root . '/migrations/20261115120000_oss_credit_year_vat_payer.php')->up($db, $driver === 'sqlite');
        $checks[] = ['the migration recounts it by the date of supply', $totals() === [[$year - 1, 1030000]] && OssThresholdCache::check($db)['mismatches'] === 0];
    } elseif ($phase === 'cancel') {
        ShopTime::setNowForTesting(strtotime($year . '-04-01 12:00:00 UTC'));
        Bootstrap::initStoreContext('/', '', 'default');
        $id = $invoice($db, $order($year . '-04-01 10:00:00', 50000), $year . '-04-01', $year . '-04-01', 50000);
        // The year total cannot be written (a full disk, a lost connection): nothing of the cancellation may stay.
        $db->exec($driver === 'mysql'
            ? "CREATE TRIGGER r38l_fail BEFORE UPDATE ON oss_threshold_totals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'r38l totals unavailable'"
            : "CREATE TRIGGER r38l_fail BEFORE UPDATE ON oss_threshold_totals BEGIN SELECT RAISE(ABORT, 'r38l totals unavailable'); END");
        $failed = false;
        try { InvoiceService::cancel($id, 'R38L'); } catch (Throwable $e) { $failed = str_contains($e->getMessage(), 'r38l totals unavailable'); }
        $status = static fn(): string => (string)$db->query('SELECT status FROM invoices WHERE id = ' . $id)->fetchColumn();
        $net = static fn(): int => (int)$db->query("SELECT SUM(net) FROM oss_threshold_totals WHERE year = {$year}")->fetchColumn();
        $audited = static fn(): int => (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE entity = 'invoice_cancelled' AND entity_id = {$id}")->fetchColumn();
        $checks[] = ['the failed cancellation reports its error', $failed];
        $checks[] = ['the invoice stays issued and counted (was: cancelled, still in the year total)', $status() === 'issued' && $net() === 50000 && $audited() === 0];
        $checks[] = ['no transaction is left open', !$db->inTransaction()];
        $db->exec('DROP TRIGGER r38l_fail');
        InvoiceService::cancel($id, 'R38L');
        $checks[] = ['cancelled again: status, year total and audit entry change together', $status() === 'cancelled' && $net() === 0 && $audited() === 1];
    } elseif ($phase === 'rates') {
        ShopTime::setNowForTesting(strtotime('2026-11-20 12:00:00 UTC'));
        Bootstrap::initStoreContext('/', '', 'default');
        $invoice($db, $order('2026-11-20 10:00:00', 5000000, 'czk'), '2026-11-20', '2026-11-20', 5000000, 'czk');
        $check = static fn(): array => Setup::ossThresholdCheck($db, 'default', $stores['stores']['default'], $stores['stores']);
        $fresh = $check();
        $checks[] = ['CZK sales counted at rates of 2026-11-01 pass in November 2026', $fresh['status'] === 'pass'];
        ShopTime::setNowForTesting(strtotime('2027-03-01 12:00:00 UTC'));
        $invoice($db, $order('2027-03-01 10:00:00', 5000000, 'czk'), '2027-03-01', '2027-03-01', 5000000, 'czk');
        $stale = $check();
        $checks[] = ['in March 2027 the doctor warns that the fixed CZK rate is 120 days old (was: pass)', $stale['status'] === 'warn'
            && str_contains($stale['message'], 'CZK') && str_contains($stale['message'], '120 days') && str_contains($stale['action'], 'eur_exchange_rates_as_of')];
    } elseif ($phase === 'failure') {
        $totalsRow($year, 100000);
        $breakCount(true);
        $abroad = null;
        $error = '';
        try { $abroad = $exceeded($year . '-06-01 12:00:00', 5000); } catch (Throwable $e) { $error = get_class($e); }
        $checks[] = ['a failed count is an error, not the seller VAT (was: false)', $abroad === null && $error === 'OssThresholdUnavailableException'];
        $checks[] = ['and a critical incident', $incidents() === ['critical']];
        $breakCount(false);
        $checks[] = ['counted again once the database works', $exceeded($year . '-06-01 12:00:00', 5000) === false];
    }
    $out();
    exit(0);
} catch (Throwable $e) {
    $checks[] = ['phase ' . $phase . ' ran: ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false];
    $out();
    exit(0);
} finally {
    if (!in_array($phase, ['checkout', 'manual'], true)) ShopTime::setNowForTesting(null);
}
PHP);
// Statement class of the snapshot phase's reader: runs the writer once after a statement was read to its end.
file_put_contents($r38lRoot . '/statement.php', <<<'PHP'
<?php
class R38LStatement extends PDOStatement {
    /** @var null|callable(self): bool */
    public static $hook = null;
    public static bool $fired = false;
    public array $params = [];
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        $this->params = $params ?? [];
        return parent::execute($params);
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $rows = parent::fetchAll($mode, ...$args);
        if (self::$hook !== null && !self::$fired && (self::$hook)($this)) self::$fired = true;
        return $rows;
    }
}
PHP);
// The checkout and manual-order phases run the shop's real request handlers (index.php, ManualOrder).
$r38lHandler = $r38lRoot . '/handler.php';
file_put_contents($r38lHandler, <<<'PHP'
<?php
[, $driver, $phase, $root, $target, $dir, $extra] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') $db->exec("USE `{$target}`");
Db::init($db, $driver);
$args = json_decode($extra, true) ?: [];
$config = require AppConfig::path($root);
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_id('r38l' . substr(md5($target), 0, 20));
session_start();
$_SERVER['REMOTE_ADDR'] = '127.0.38.1';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
$stores = Bootstrap::stores();
$stores['stores']['default']['oss_enabled'] = false;
$stores['stores']['default']['timezone'] = 'Europe/Bratislava';
$stores['stores']['default']['legal'] = array_merge((array)($stores['stores']['default']['legal'] ?? []), ['country' => 'SK', 'ic_dph' => 'SK2038380001', 'seller' => '']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$year = (int)ShopTime::date('Y');
if (isset($args['totals'])) {
    $db->exec('DELETE FROM oss_threshold_totals');
    $db->prepare("INSERT INTO oss_threshold_totals (store_id, year, currency, net) VALUES ('default', ?, 'eur', ?)")->execute([$year, (int)$args['totals']]);
}
// R38 (low, failed count): the year totals cannot be read for this request.
$broken = !empty($args['broken']);
if ($broken) { $db->exec('ALTER TABLE oss_threshold_totals RENAME COLUMN net TO r38l_net'); Db::forgetSchema($db); }
$restore = static function() use ($db, $broken): void {
    if ($broken) { $db->exec('ALTER TABLE oss_threshold_totals RENAME COLUMN r38l_net TO net'); Db::forgetSchema($db); }
};
$orders = static fn(): array => $db->query('SELECT id, total, vat_scenario, oss_net FROM orders ORDER BY id')->fetchAll();
$incidents = static fn(): array => $db->query("SELECT severity FROM operational_incidents WHERE code = 'tax.oss_threshold_failed'")->fetchAll(PDO::FETCH_COLUMN);
$before = count($orders());
$buyer = ['name' => 'R38L Kundin', 'email' => 'r38l@example.test', 'phone' => '+43660000038', 'street' => 'Ring 1', 'city' => 'Wien', 'zip' => '1010',
    'country' => 'AT', 'payment' => 'r38l_pay', 'payment_method' => 'r38l_pay', 'shipping_method' => 'gls'];
if ($phase === 'manual-create' || $phase === 'manual-edit') {
    $_SESSION['admin_user'] = 'r38l-admin';
    if ($phase === 'manual-create') {
        $result = ManualOrder::create(ManualOrder::parseLines('R38L-MUG 1')['lines'], $buyer, false);
    } else {
        $holder = null;
        if (!empty($args['hold_lock'])) {
            // Another order of the same seller holds the threshold lock (Orders::create(), guardOrder()).
            $holder = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '');
            $held = $holder->prepare('SELECT GET_LOCK(?, 0)');
            $held->execute([OssThreshold::sellerLockName($db, StoreContext::current()->id(), StoreContext::current()->config())]);
            if ((int)$held->fetchColumn() !== 1) throw new RuntimeException('R38L: could not hold the seller lock');
        }
        $started = microtime(true);
        try {
            $result = ManualOrder::editItems((int)$args['order_id'], ManualOrder::parseLines('R38L-MUG 1')['lines']);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'error' => 'exception', 'detail' => $e->getMessage()];
        }
        $result['seconds'] = round(microtime(true) - $started, 1);
        $holder = null;
    }
    $restore();
    echo 'R38LRESULT' . json_encode(['result' => $result, 'orders' => $orders(), 'new_orders' => array_slice($orders(), $before), 'incidents' => $incidents()]);
    exit(0);
}
$_SESSION = [];
Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'R38L-MUG'")->fetchColumn(), null, 1);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['page' => 'checkout'];
$_POST = ($args['post'] ?? []) + $buyer + ['csrf_token' => Security::csrfToken()];
http_response_code(200);
ob_start();
register_shutdown_function(static function() use ($orders, $before, $restore, $incidents): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    $restore();
    echo "\nR38LRESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => array_slice($orders(), $before), 'incidents' => $incidents()], JSON_INVALID_UTF8_SUBSTITUTE);
});
require $root . '/index.php';
PHP);
$r38lRun = static function(string $script, array $args) use ($r38lRoot): array {
    $process = proc_open(array_merge([PHP_BINARY, '-d', 'auto_prepend_file=' . $r38lRoot . '/statement.php', $script], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R38LRESULT');
    return [$exit, $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true), substr($raw, 0, min($at === false ? 800 : $at, 800))];
};
$r38lApp = dirname(TEST_CLI_DIR);
foreach (['sqlite', 'mysql'] as $r38lDriver) {
    if ($r38lDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { print "  [SKIP] R38 OSS low native MySQL (set TEST_MYSQL_DSN)\n"; continue; }
    foreach (['snapshot', 'calendar', 'supply', 'cancel', 'rates', 'failure', 'checkout', 'manual'] as $r38lPhase) {
        $r38lTarget = $r38lDriver === 'mysql' ? 'bbe_r38l_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test' : $r38lRoot . '/shop-' . $r38lPhase . '.sqlite';
        $r38lLabel = "R38 OSS low {$r38lDriver}/{$r38lPhase}";
        try {
            [$r38lExit, , $r38lNoise] = $r38lRun($r38lFixture, [$r38lDriver, 'setup', $r38lApp, $r38lTarget, $r38lRoot, '{}']);
            assert_equals([0, ''], [$r38lExit, trim($r38lNoise)], "{$r38lLabel}: isolated migrated fixture");
            $r38lCall = static function(string $r38lScript, string $r38lPhase, array $r38lArgs = []) use ($r38lRun, $r38lDriver, $r38lApp, $r38lTarget, $r38lRoot, $r38lLabel): array {
                [$r38lExit, $r38lResult, $r38lNoise] = $r38lRun($r38lScript, [$r38lDriver, $r38lPhase, $r38lApp, $r38lTarget, $r38lRoot, json_encode($r38lArgs)]);
                assert_true($r38lExit === 0 && is_array($r38lResult), "{$r38lLabel}/{$r38lPhase}: completes ({$r38lNoise})");
                return $r38lResult ?? [];
            };
            if ($r38lPhase === 'checkout') {
                // Under 10 000 EUR, but the count fails: the page is shown, the order is refused, the merchant alerted.
                $r38lPage = $r38lCall($r38lHandler, 'checkout', ['totals' => 100000, 'broken' => true, 'post' => ['checkout_refresh' => '1']]);
                $r38lHtml = (string)($r38lPage['output'] ?? '');
                $r38lQuoted = preg_match('~name="quoted_total" value="(\d+)"~', $r38lHtml, $r38lM) ? (int)$r38lM[1] : null;
                $r38lConsents = preg_match_all('~name="consents\[([a-z_]+)\]" value="1"~', $r38lHtml, $r38lM) ? array_fill_keys($r38lM[1], '1') : [];
                assert_true(($r38lPage['code'] ?? 0) === 200 && $r38lQuoted !== null, "{$r38lLabel}: the checkout page is shown while the count fails (HTTP " . ($r38lPage['code'] ?? '?') . ')');
                $r38lPost = ['shipping_method' => 'gls', 'quoted_total' => (string)$r38lQuoted, 'consents' => $r38lConsents, 'checkout' => '1'];
                $r38lRefused = $r38lCall($r38lHandler, 'checkout', ['broken' => true, 'post' => $r38lPost]);
                $r38lMessage = preg_match('~<p id="checkout-error"[^>]*>([^<]+)</p>~', (string)($r38lRefused['output'] ?? ''), $r38lM) ? html_entity_decode($r38lM[1]) : '';
                assert_true(($r38lRefused['new_orders'] ?? null) === [] && (str_contains($r38lMessage, 'nedá určiť') || str_contains($r38lMessage, 'cannot be determined')),
                    "{$r38lLabel}: no order on an unknown VAT, the buyer is told why (was: placed at the seller VAT) (" . $r38lMessage . ' ' . json_encode($r38lRefused['new_orders'] ?? null) . ')');
                assert_true(in_array('critical', $r38lRefused['incidents'] ?? [], true), "{$r38lLabel}: the failed count is a critical incident (" . json_encode($r38lRefused['incidents'] ?? null) . ')');
                $r38lPlaced = $r38lCall($r38lHandler, 'checkout', ['post' => $r38lPost]);
                assert_true(count($r38lPlaced['new_orders'] ?? []) === 1 && ($r38lPlaced['new_orders'][0]['vat_scenario'] ?? '') === 'domestic_subthreshold',
                    "{$r38lLabel}: the count works again, the order is placed at the seller VAT (" . json_encode($r38lPlaced['new_orders'] ?? null) . ')');
            } elseif ($r38lPhase === 'manual') {
                // 9 850 EUR so far; a manual order of 100 EUR net to Austria stays under 10 000 EUR.
                $r38lCreated = $r38lCall($r38lHandler, 'manual-create', ['totals' => 985000]);
                $r38lOrder = $r38lCreated['new_orders'][0] ?? [];
                assert_true(($r38lCreated['result']['ok'] ?? false) && ($r38lOrder['vat_scenario'] ?? '') === 'domestic_subthreshold' && (int)($r38lOrder['oss_net'] ?? 0) >= 10000,
                    "{$r38lLabel}: manual order at the seller VAT (" . json_encode($r38lCreated) . ')');
                $r38lEdited = $r38lCall($r38lHandler, 'manual-edit', ['order_id' => (int)($r38lOrder['id'] ?? 0)]);
                $r38lAfter = array_values(array_filter($r38lEdited['orders'] ?? [], static fn(array $o): bool => (int)$o['id'] === (int)($r38lOrder['id'] ?? 0)))[0] ?? [];
                assert_true(($r38lEdited['result']['ok'] ?? false) && ($r38lAfter['vat_scenario'] ?? '') === 'domestic_subthreshold' && (int)($r38lAfter['oss_net'] ?? -1) === (int)($r38lOrder['oss_net'] ?? -2),
                    "{$r38lLabel}: editing its lines counts them once: still under 10 000 EUR (was: counted twice, refused) (" . json_encode($r38lEdited['result'] ?? null) . ')');
                if ($r38lDriver === 'mysql') {
                    $r38lWaited = $r38lCall($r38lHandler, 'manual-edit', ['order_id' => (int)($r38lOrder['id'] ?? 0), 'hold_lock' => true]);
                    assert_true(($r38lWaited['result']['error'] ?? '') === 'exception' && str_contains((string)($r38lWaited['result']['detail'] ?? ''), 'OSS threshold lock') && ($r38lWaited['result']['seconds'] ?? 0) >= 9,
                        "{$r38lLabel}: the edit waits for the seller's threshold lock like a new order (was: decided without it) (" . json_encode($r38lWaited['result'] ?? null) . ')');
                }
                $r38lFailed = $r38lCall($r38lHandler, 'manual-create', ['broken' => true]);
                assert_true(($r38lFailed['result']['error'] ?? '') === 'checkout.error.vat_unavailable' && ($r38lFailed['new_orders'] ?? null) === [],
                    "{$r38lLabel}: a manual order on a failed count is refused, not placed at the seller VAT (" . json_encode($r38lFailed['result'] ?? null) . ')');
            } else {
                $r38lResult = $r38lCall($r38lFixture, $r38lPhase);
                assert_true(($r38lResult['checks'] ?? []) !== [], "{$r38lLabel}: checks ran");
                foreach ($r38lResult['checks'] ?? [] as [$r38lCheck, $r38lOk]) assert_true($r38lOk, "{$r38lLabel}: {$r38lCheck}");
            }
        } finally {
            if ($r38lDriver === 'mysql') $r38lRun($r38lFixture, ['mysql', 'teardown', $r38lApp, $r38lTarget, $r38lRoot, '{}']);
        }
    }
}
