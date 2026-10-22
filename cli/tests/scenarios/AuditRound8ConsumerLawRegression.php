<?php

// R8 EU consumer law (core). Texts here are a technical baseline and must be reviewed by a lawyer.
// 1) The order button states the payment obligation (Directive 2011/83/EU art. 8(2)) in every shipped locale
//    and on the real storefront checkout page.
echo "\n--- Audit round 8: EU consumer law (order button, withdrawal, confirmation, digital consent, complaints) ---\n";

$r8lRoot = test_fixture_path('r8-consumer-law');
mkdir($r8lRoot);
mkdir($r8lRoot . '/sessions');
$r8lFixture = $r8lRoot . '/request.php';
file_put_contents($r8lFixture, <<<'PHP'
<?php
[, $phase, $action, $root, $target, $dir, $extra] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate.lock'))->apply();
    $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute(['default', 'r8lcat', 'Cat', 'r8lcat']);
    $cat = (int)$db->query("SELECT id FROM categories WHERE slug = 'r8lcat'")->fetchColumn();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'R8L-SKU', 'r8l-product', 'R8L Product', 2000, 50, 'active']);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 50, 0)')->execute([$warehouse, 'R8L-SKU']);
    exit(0);
}
$config = is_file($root . '/config.php') ? require $root . '/config.php' : require $root . '/config.example.php';
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.8.21';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['page' => 'checkout'];
$_POST = [];
Bootstrap::initLocale('/index.php');
Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'R8L-SKU'")->fetchColumn(), null, 1);
http_response_code(200);
ob_start();
register_shutdown_function(static function(): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR8LRESULT" . json_encode(['code' => http_response_code(), 'output' => $out], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});
require $root . '/index.php';
PHP);
$r8lRun = static function(array $args) use ($r8lFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r8lFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R8LRESULT');
    $result = $at === false ? null : json_decode(strtok(substr($raw, $at + 9), "\n"), true);
    return [$exit, is_array($result) ? $result : null, $at === false ? substr($raw, 0, 800) : substr($raw, 0, min($at, 800))];
};
$r8lApp = dirname(TEST_CLI_DIR);
$r8lDb = $r8lRoot . '/shop.sqlite';

// Payment-obligation wording per locale (lawyer to confirm the exact phrase).
$r8lButtonPhrase = [
    'sk' => 'povinnosťou platby', 'cs' => 'povinností platby', 'en' => 'obligation to pay', 'de' => 'zahlungspflichtig',
    'pl' => 'obowiązkiem zapłaty', 'hu' => 'fizetési kötelezettség', 'es' => 'obligación de pago',
];
$r8lFind = static function(array $lang, string $key) use (&$r8lFind): array {
    $found = [];
    foreach ($lang as $k => $v) {
        if (is_array($v)) $found = array_merge($found, $r8lFind($v, $key));
        elseif ($k === $key) $found[] = (string)$v;
    }
    return $found;
};
foreach ($r8lButtonPhrase as $r8lLocale => $r8lPhrase) {
    $r8lLang = require $r8lApp . '/lang/' . $r8lLocale . '.php';
    $r8lLabels = array_merge($r8lFind($r8lLang, 'place_order'), $r8lFind($r8lLang, 'submit_order'));
    $r8lBad = array_values(array_filter($r8lLabels, static fn(string $l): bool => !str_contains(mb_strtolower($l), $r8lPhrase)));
    assert_true($r8lLabels !== [] && $r8lBad === [], "R8 order button ({$r8lLocale}): every order-submit label states the payment obligation (" . json_encode($r8lBad, JSON_UNESCAPED_UNICODE) . ')');
}

[$r8lExit, , $r8lNoise] = $r8lRun(['setup', 'none', $r8lApp, $r8lDb, $r8lRoot, '{}']);
assert_equals([0, ''], [$r8lExit, trim($r8lNoise)], 'R8 consumer law: migrated fixture with one product');
[$r8lExit, $r8lRes, $r8lNoise] = $r8lRun(['run', 'checkout', $r8lApp, $r8lDb, $r8lRoot, '{}']);
$r8lButton = preg_match('~<button class="btn-checkout" type="submit">([^<]*)</button>~', (string)($r8lRes['output'] ?? ''), $r8lM) ? html_entity_decode($r8lM[1]) : '';
assert_true($r8lExit === 0 && str_contains(mb_strtolower($r8lButton), $r8lButtonPhrase['sk']),
    "R8 order button: the real checkout page submit button states the payment obligation ('{$r8lButton}' " . trim($r8lNoise) . ')');
