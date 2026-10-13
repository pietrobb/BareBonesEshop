<?php

// A14.1 / usability 5: the rendered cart, checkout and product pages carry no inline grid columns (the theme CSS
// stacks them below 800px), and subcategories in the header menu open from a <details> toggle (tap, keyboard,
// no JS) instead of hover only. Pages come from the real storefront entrypoints on SQLite; the 360/1280px
// layout itself is checked in a browser by tests/e2e/responsive.spec.js.
echo "\n--- Audit A14.1: responsive cart/checkout/product markup and touch/keyboard category menu ---\n";

$a141Root = test_fixture_path('storefront-responsive');
mkdir($a141Root);
mkdir($a141Root . '/sessions');
$a141Fixture = $a141Root . '/request.php';
file_put_contents($a141Fixture, <<<'PHP'
<?php
[, $phase, $page, $root, $target, $dir] = $argv;
ini_set('display_errors', 'stderr');
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate.lock'))->apply();
    $cat = $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, ?, ?, ?)');
    $cat->execute(['default', 'a141-parent', 'A141 Parent', null, 'a141-parent', 0]);
    $parent = (int)$db->lastInsertId();
    $cat->execute(['default', 'a141-child', 'A141 Child', $parent, 'a141-parent/a141-child', 1]);
    $child = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, images) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $child, 'A141-SKU', 'a141-product', 'A141 Product', 2000, 50, 'active', json_encode(['/assets/img/slider1.jpg', '/assets/img/slider2.jpg'])]);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 50, 0)')->execute([$warehouse, 'A141-SKU']);
    Import::recountCategories($db);
    exit(0);
}

$config = is_file($root . '/config.php') ? require $root . '/config.php' : require $root . '/config.example.php';
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.14.1';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_METHOD'] = 'GET';
[$uri, $_GET, $entry] = match ($page) {
    'home' => ['/', [], 'index.php'],
    'cart' => ['/index.php?page=cart', ['page' => 'cart'], 'index.php'],
    'checkout' => ['/index.php?page=checkout', ['page' => 'checkout'], 'index.php'],
    'product' => ['/product.php?slug=a141-product', ['slug' => 'a141-product'], 'product.php'],
};
$_SERVER['REQUEST_URI'] = $uri;
$_POST = [];
Bootstrap::initLocale('/' . $entry);
if ($page !== 'home') Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'A141-SKU'")->fetchColumn(), null, 2);
http_response_code(200);
ob_start();
register_shutdown_function(static function(): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nA141RESULT" . json_encode(['code' => http_response_code(), 'output' => $out], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});
require $root . '/' . $entry;
PHP);
$a141Run = static function(array $args) use ($a141Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a141Fixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'A141RESULT');
    $result = $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true);
    return [$exit, is_array($result) ? $result : null, $at === false ? substr($raw, 0, 800) : substr($raw, 0, min($at, 800))];
};
$a141App = dirname(TEST_CLI_DIR);
$a141Db = $a141Root . '/shop.sqlite';
try {
    [$a141Exit, , $a141Noise] = $a141Run(['setup', 'none', $a141App, $a141Db, $a141Root]);
    assert_equals([0, ''], [$a141Exit, trim($a141Noise)], 'A14.1: migrated fixture with a parent/child category and a product with two photos');
    $a141Html = [];
    foreach (['home', 'cart', 'checkout', 'product'] as $a141Page) {
        [$a141Exit, $a141Result, $a141Noise] = $a141Run(['run', $a141Page, $a141App, $a141Db, $a141Root]);
        $a141Out = (string)($a141Result['output'] ?? '');
        assert_true($a141Exit === 0 && ($a141Result['code'] ?? 0) === 200 && !str_contains($a141Out, 'Fatal error') && !str_contains($a141Out, 'Uncaught'),
            "A14.1 {$a141Page}: the real storefront page renders (exit {$a141Exit} " . trim($a141Noise)
            . ($a141Exit !== 0 || ($a141Result['code'] ?? 0) !== 200 ? ' HTTP ' . ($a141Result['code'] ?? '?') . ' ' . substr(trim(strip_tags($a141Out)), 0, 400) : '') . ')');
        $a141Html[$a141Page] = $a141Out;
        preg_match_all('~style="[^"]*grid-template-columns[^"]*"~', $a141Out, $a141Inline);
        assert_equals([], $a141Inline[0], "A14.1 {$a141Page}: no inline grid-template-columns (the stylesheet decides the columns per screen width)");
    }
    assert_true(str_contains($a141Html['cart'], '<div class="cart-layout">') && str_contains($a141Html['cart'], '<div class="table-scroll"><table class="cart-table">')
        && str_contains($a141Html['cart'], 'class="col-price"') && str_contains($a141Html['cart'], 'A141 Product'),
        'A14.1 cart: the cart uses the responsive layout class, a scroll box for the table and a hideable price column');
    assert_true(str_contains($a141Html['checkout'], '<div class="checkout-layout">') && str_contains($a141Html['checkout'], 'class="checkout-form"'),
        'A14.1 checkout: the checkout uses the responsive layout class');
    assert_true(str_contains($a141Html['product'], '<article class="product-detail">') && str_contains($a141Html['product'], 'class="product-detail__hero"')
        && str_contains($a141Html['product'], 'class="product-detail__thumbs"'), 'A14.1 product: the product page renders its gallery and info blocks');

    $a141Css = (string)file_get_contents($a141App . '/themes/default/assets/storefront.css');
    assert_true(str_contains($a141Css, '@media(max-width:800px){.cart-layout,.checkout-layout,.product-layout,.product-detail{grid-template-columns:minmax(0,1fr)}'),
        'A14.1 css: below 800px the cart, checkout and product layouts collapse to one column');
    assert_true(preg_match('~[,}]\.product-detail\{display:grid;grid-template-columns:repeat\(2,minmax\(0,1fr\)\)~', $a141Css) === 1
        && preg_match('~\.product-detail__hero\{[^}]*width:100%;height:auto~', $a141Css) === 1,
        'A14.1 css: the product page has a two-column grid and its photo is scaled to the column, so a large image cannot widen the page');

    $a141Home = $a141Html['home'];
    assert_true(preg_match('~<div class="nav-item"><a href="[^"]*a141-parent[^"]*">A141 Parent</a><details class="nav-sub"><summary aria-label="A141 Parent: (?!subcategories)[^"]+">'
        . '</summary><div class="nav-dropdown"><a href="[^"]*a141-child[^"]*">A141 Child</a></div></details></div>~u', $a141Home) === 1,
        'A14.1 menu: subcategories sit in a <details> whose named <summary> toggle opens them by tap, keyboard or without JS ('
        . (preg_match('~<div class="nav-item">.{0,400}~s', $a141Home, $a141Nav) ? $a141Nav[0] : 'no nav-item in ' . substr(strip_tags($a141Home), 0, 200)) . ')');
    assert_true(str_contains($a141Css, '.nav-sub[open]>.nav-dropdown{display:block}') && !str_contains($a141Css, '.nav-item:hover .nav-dropdown{display:block}'),
        'A14.1 css: the open state, not hover, shows the subcategory list');
} finally {
    $a141Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a141Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a141Tree as $a141Path) $a141Path->isDir() ? @rmdir($a141Path->getPathname()) : @unlink($a141Path->getPathname());
    @rmdir($a141Root);
}
