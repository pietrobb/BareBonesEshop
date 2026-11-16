<?php

// Review round 12 (R38, low), "Buy later":
// 1. Two devices of one account saving the same line at once: both found no row (UPDATE, then SELECT 1) and both
//    INSERTed; the second broke UNIQUE(customer_id, store_id, item_key) uncaught, HTTP 500.
// 2. Under reverse charge the cart shows prices net of the seller's VAT, but "Was" showed the saved catalog price with
//    the VAT in it: a raise from 123 € to 135.30 € read "110,00 € — Was 123,00 €".
// Both through the shop's storefront handler (index.php), on an isolated migrated database of each driver.
echo "\n--- R38 buy later: two devices saving at once, the \"was\" price under reverse charge ---\n";

$r38sRoot = test_fixture_path('save-later-r38');
mkdir($r38sRoot);
mkdir($r38sRoot . '/sessions');
// The other device's save lands between this request's "is the line there?" and its INSERT.
file_put_contents($r38sRoot . '/statement.php', <<<'PHP'
<?php
class R38SStatement extends PDOStatement {
    /** @var null|callable(): void */
    public static $hook = null;
    public static bool $fired = false;
    protected function __construct() {}
    public function fetchColumn(int $column = 0): mixed {
        $value = parent::fetchColumn($column);
        if (self::$hook !== null && str_starts_with(ltrim($this->queryString), 'SELECT 1 FROM saved_for_later_items')) {
            $hook = self::$hook;
            self::$hook = null;
            self::$fired = true;
            $hook();
        }
        return $value;
    }
}
PHP);
$r38sFixture = $r38sRoot . '/request.php';
file_put_contents($r38sFixture, <<<'PHP'
<?php
[, $driver, $phase, $root, $target, $dir, $extra] = $argv;
require $root . '/src/autoload.php';
$connect = static function() use ($driver, $target): PDO {
    $db = $driver === 'mysql'
        ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
        : new PDO('sqlite:' . $target);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $db;
};
$db = $connect();
if ($driver === 'mysql') {
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    $db->exec("USE `{$target}`");
}
$db->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['R38SStatement']);
Db::init($db, $driver);
$args = json_decode($extra, true) ?: [];
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r38s', 'R38S', 'r38s', 0)");
    $cat = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, weight_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'R38S-MUG', 'r38s-mug', 'R38S Mug', 13530, 100, 'active', 500]);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 100, 0)')->execute([$warehouse, 'R38S-MUG']);
    $db->prepare("INSERT INTO customers (store_id, email, password_hash, name, status, email_verified_at) VALUES ('default', 'r38s@example.test', ?, 'R38S', 'active', '2026-01-01 00:00:00')")
        ->execute([password_hash('R38s-pass-1234', PASSWORD_BCRYPT, ['cost' => 4])]);
    $db->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES ('ATU38383839', 'AT', 'valid', 'R38S GmbH', ?, 86400)")
        ->execute([gmdate('Y-m-d H:i:s')]);
    exit(0);
}
$config = require AppConfig::path($root);
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.38.4';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=cart';
Bootstrap::initLocale('/index.php');
$stores = Bootstrap::stores();
$stores['stores']['default']['legal']['country'] = 'SK';
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$product = (int)$db->query("SELECT id FROM products WHERE sku = 'R38S-MUG'")->fetchColumn();
$customer = (int)$db->query("SELECT id FROM customers WHERE email = 'r38s@example.test'")->fetchColumn();
$key = 'p_' . $product;
$r38sState = [];
if ($phase === 'race') {
    [$ok] = Customers::login('r38s@example.test', 'R38s-pass-1234');
    $r38sState['login'] = $ok;
    Cart::add($product, null, 2);
    // The account's phone saves one of the same mug in between.
    R38SStatement::$hook = static function() use ($connect, $driver, $target, $customer, $product, $key): void {
        $other = $connect();
        if ($driver === 'mysql') $other->exec("USE `{$target}`");
        $other->prepare("INSERT INTO saved_for_later_items (customer_id, store_id, item_key, product_id, qty, saved_price, saved_currency) VALUES (?, 'default', ?, ?, 1, 13530, 'eur')")
            ->execute([$customer, $key, $product]);
    };
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_POST = ['cart_action' => 'save_for_later', 'cart_key' => $key, 'csrf_token' => Security::csrfToken()];
    $_GET = [];
} else {
    // A guest buying for an Austrian company with a valid VAT ID (reverse charge); the mug was saved at 123 €.
    $_SESSION[CheckoutService::DRAFT_KEY] = ['values' => ['company' => 'R38S GmbH', 'vat_id' => 'ATU38383839', 'name' => 'R38S', 'city' => 'Wien', 'zip' => '1010', 'street' => 'Ring 2'],
        'country' => 'AT', 'billing_country' => '', 'billing_different' => false, 'customer_id' => 0];
    $_SESSION['saved_for_later']['default'][$key] = ['product_id' => $product, 'variant_id' => null, 'qty' => 1, 'saved_price' => 12300, 'saved_currency' => 'eur', 'variant_sku' => null];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['page' => 'cart'];
}
http_response_code(200);
ob_start();
register_shutdown_function(static function() use ($db, $customer, &$r38sState): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    $rows = $db->query('SELECT item_key, qty FROM saved_for_later_items WHERE customer_id = ' . $customer)->fetchAll();
    echo "\nR38SRESULT" . json_encode($r38sState + ['code' => http_response_code(), 'output' => $out, 'rows' => $rows,
        'raced' => R38SStatement::$fired, 'was_net' => I18n::format('saved_for_later_was', ['price' => I18n::price(10000, 'eur')]), 'was_gross' => I18n::format('saved_for_later_was', ['price' => I18n::price(12300, 'eur')]), 'net_now' => I18n::price(11000, 'eur')], JSON_INVALID_UTF8_SUBSTITUTE);
});
require $root . '/index.php';
PHP);
$r38sRun = static function(array $args) use ($r38sFixture, $r38sRoot): array {
    $process = proc_open(array_merge([PHP_BINARY, '-d', 'auto_prepend_file=' . $r38sRoot . '/statement.php', $r38sFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R38SRESULT');
    return [$exit, $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true), substr($raw, 0, min($at === false ? 900 : $at, 900))];
};
$r38sApp = dirname(TEST_CLI_DIR);
$r38sTargets = [];
try {
    foreach (['sqlite', 'mysql'] as $r38sDriver) {
        if ($r38sDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { print "  [SKIP] R38 buy later native MySQL (set TEST_MYSQL_DSN)\n"; continue; }
        $r38sTarget = $r38sDriver === 'mysql' ? 'bbe_r38s_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test' : $r38sRoot . '/shop.sqlite';
        $r38sTargets[] = [$r38sDriver, $r38sTarget];
        [$exit, , $noise] = $r38sRun([$r38sDriver, 'setup', $r38sApp, $r38sTarget, $r38sRoot, '{}']);
        assert_equals([0, ''], [$exit, trim($noise)], "R38 buy later {$r38sDriver}: isolated migrated fixture");

        [$exit, $race, $noise] = $r38sRun([$r38sDriver, 'race', $r38sApp, $r38sTarget, $r38sRoot, '{}']);
        $answer = json_decode((string)($race['output'] ?? ''), true);
        assert_true(!empty($race['login']) && !empty($race['raced']) && (int)($race['code'] ?? 0) === 200 && !empty($answer['ok']),
            "R38 buy later {$r38sDriver}: a save while the other device saved the same line answers OK, not 500 (got " . json_encode([$race['code'] ?? null, $answer, trim($noise)]) . ')');
        assert_equals(1, count($race['rows'] ?? []),
            "R38 buy later {$r38sDriver}: the account keeps the line once");
        assert_equals(2, (int)($race['rows'][0]['qty'] ?? 0), "R38 buy later {$r38sDriver}: the later save (2 mugs from this cart) is the one kept");

        [$exit, $page, $noise] = $r38sRun([$r38sDriver, 'was', $r38sApp, $r38sTarget, $r38sRoot, '{}']);
        $html = html_entity_decode(strip_tags((string)($page['output'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = preg_replace('~\s+~u', ' ', str_replace("\u{a0}", ' ', $html));
        $norm = static fn(string $s): string => str_replace("\u{a0}", ' ', $s);
        assert_true($exit === 0 && str_contains($html, $norm((string)($page['net_now'] ?? '?'))),
            "R38 buy later {$r38sDriver}: under reverse charge the saved line shows today's price net of VAT ({$noise})");
        assert_true(str_contains($html, $norm((string)($page['was_net'] ?? '?'))) && !str_contains($html, $norm((string)($page['was_gross'] ?? '?'))),
            "R38 buy later {$r38sDriver}: \"Was\" is the saved price on the same net basis, not the gross catalog price (" . json_encode([$page['was_net'] ?? null, 'gross shown' => str_contains($html, $norm((string)($page['was_gross'] ?? '?')))], JSON_UNESCAPED_UNICODE) . ')');
    }
} finally {
    foreach ($r38sTargets as [$r38sDriver, $r38sTarget]) if ($r38sDriver === 'mysql') $r38sRun(['mysql', 'teardown', $r38sApp, $r38sTarget, $r38sRoot, '{}']);
}
