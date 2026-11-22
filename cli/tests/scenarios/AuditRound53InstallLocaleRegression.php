<?php

// Review round 53 (medium 3 and UI lows): a shop installed in CZK kept the default store's shipping and payment prices in
// euro cents (GLS 2.99 Kč, free shipping from 50 Kč, cash on delivery 3 Kč) and the setup doctor said nothing.
echo "\n--- Audit round 53: install in another currency prices shipping and payment in it ---\n";

$r53iRoot = test_fixture_path('r53-install-locale');
mkdir($r53iRoot);
file_put_contents($r53iRoot . '/child.php', <<<'PHP'
<?php
[, $root, $currency] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . __DIR__ . "/shop-{$currency}.sqlite");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
(new Migrator($db, $root . '/migrations', __DIR__ . "/migrate-{$currency}.lock"))->apply();
Bootstrap::initStoreContext('/', '', 'default');
$stores = ['default_store' => 'default', 'stores' => ['default' => ['currency' => $currency, 'provider_scope' => 'default']]];
$figures = static function () use ($db): array {
    $one = static fn(string $sql): mixed => $db->query($sql)->fetchColumn();
    return [
        'gls' => (int)$one("SELECT MIN(price) FROM shipping_rates WHERE store_id = 'default' AND method_code = 'gls'"),
        'free_above' => (int)$one("SELECT MAX(free_above) FROM shipping_methods WHERE store_id = 'default'"),
        'cod_fee' => (int)$one("SELECT fixed_fee FROM payment_methods WHERE store_id = 'default' AND code = 'cod'"),
        'cod_countries' => (int)$one("SELECT COUNT(*) FROM payment_method_countries WHERE store_id = 'default' AND method_code = 'cod'"),
    ];
};
$out = ['doctor_before' => method_exists('Setup', 'providerPricesCheck') ? Setup::providerPricesCheck($db, 'default', $stores['stores']['default'])['status'] ?? null : null];
if (method_exists('WebInstaller', 'priceInStoreCurrency')) WebInstaller::priceInStoreCurrency($db, $stores);
$out += $figures();
$out['doctor_after'] = method_exists('Setup', 'providerPricesCheck') ? Setup::providerPricesCheck($db, 'default', $stores['stores']['default'])['status'] ?? null : null;
$out['cz_kept'] = (int)$db->query("SELECT COUNT(*) FROM shipping_rates WHERE store_id = 'cz'")->fetchColumn();
echo 'r53i ' . json_encode($out) . "\n";
PHP);
$r53iRun = static function (string $currency) use ($r53iRoot): array {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($r53iRoot . '/child.php') . ' ' . escapeshellarg(dirname(TEST_CLI_DIR)) . ' ' . escapeshellarg($currency) . ' 2>&1', $out);
    foreach ($out as $line) {
        if (str_starts_with($line, 'r53i ')) return (array)json_decode(substr($line, 5), true);
    }
    return ['error' => substr(implode(' | ', $out), 0, 400)];
};

$r53iCzk = $r53iRun('czk');
assert_equals(['gls' => 9900, 'free_above' => 200000, 'cod_fee' => 3900],
    array_intersect_key($r53iCzk, ['gls' => 1, 'free_above' => 1, 'cod_fee' => 1]),
    'R53 install: a CZK shop gets GLS from 99 Kč, free shipping from 2 000 Kč and cash on delivery for 39 Kč (' . json_encode($r53iCzk) . ')');
assert_true(($r53iCzk['cod_countries'] ?? 0) > 0 && ($r53iCzk['cz_kept'] ?? 0) > 0, 'R53 install: the payment countries come along and the CZ price list stays');
assert_equals(['warn', 'pass'], [$r53iCzk['doctor_before'] ?? null, $r53iCzk['doctor_after'] ?? null],
    'R53 doctor: euro-cent shipping prices in a CZK store warn; the CZK price list passes');
$r53iEur = $r53iRun('eur');
assert_equals([399, 'pass', 'pass'], [$r53iEur['gls'] ?? null, $r53iEur['doctor_before'] ?? null, $r53iEur['doctor_after'] ?? null],
    'R53 install: a EUR shop keeps its EUR prices and the doctor passes (' . json_encode($r53iEur) . ')');
assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/WebInstaller.php'), 'self::priceInStoreCurrency(Db::get(), $stores);'),
    'R53 install: the installer prices the default store in its currency before it switches the payment methods');
