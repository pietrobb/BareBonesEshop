<?php

// A14.2 / usability 6,9: the rendered storefront names every form control (search, cart/product quantity, remove
// button) and every product image carries a meaningful alt — also when the catalog row stores alt:"" (feeds do).
// Pages come from the real storefront entrypoints on SQLite; tests/e2e/accessibility.spec.js runs axe on them.
echo "\n--- Audit A14.2: named search/quantity controls and meaningful product image alt ---\n";

$a142Root = test_fixture_path('storefront-a11y');
mkdir($a142Root);
mkdir($a142Root . '/sessions');
$a142Fixture = $a142Root . '/request.php';
file_put_contents($a142Fixture, <<<'PHP'
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
    $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['default', 'a142-cat', 'A142 Category', null, 'a142-cat', 0]);
    $cat = (int)$db->lastInsertId();
    $images = json_encode([['url' => '/assets/img/slider1.jpg', 'alt' => ''], '/assets/img/slider2.jpg', '/assets/img/slider3.jpg']);
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, images, image_alts) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'A142-SKU', 'a142-product', 'A142 Lamp', 2000, 50, 'active', $images, json_encode(['/assets/img/slider3.jpg' => 'Lamp switched on'])]);
    $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['default', 'a142-desk', 'A142 Desk', null, 'a142-desk', 0]);
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', (int)$db->lastInsertId(), 'A142-DESK', 'a142-desk-lamp', 'A142 Desk Lamp', 1000, 50, 'active']);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    foreach (['A142-SKU', 'A142-DESK'] as $sku) $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 50, 0)')->execute([$warehouse, $sku]);
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
$_SERVER['REMOTE_ADDR'] = '127.0.14.2';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_METHOD'] = 'GET';
[$uri, $_GET, $entry] = match ($page) {
    'home' => ['/', [], 'index.php'],
    'category' => ['/index.php?page=category&slug=a142-cat', ['page' => 'category', 'slug' => 'a142-cat'], 'index.php'],
    'search' => ['/index.php?page=search&q=lamp', ['page' => 'search', 'q' => 'lamp'], 'index.php'],
    'search_price_asc' => ['/index.php?page=search&q=lamp&sort=price_asc', ['page' => 'search', 'q' => 'lamp', 'sort' => 'price_asc'], 'index.php'],
    'search_price_desc' => ['/index.php?page=search&q=lamp&sort=price_desc', ['page' => 'search', 'q' => 'lamp', 'sort' => 'price_desc'], 'index.php'],
    'search_pmax' => ['/index.php?page=search&q=lamp&pmax=15', ['page' => 'search', 'q' => 'lamp', 'pmax' => '15'], 'index.php'],
    'search_cat' => ['/index.php?page=search&q=lamp', ['page' => 'search', 'q' => 'lamp', 'sort' => 'price_asc', 'cat' => (string)$db->query("SELECT id FROM categories WHERE slug = 'a142-cat'")->fetchColumn()], 'index.php'],
    'suggest' => ['/api.php?action=search_suggest&q=lam', ['action' => 'search_suggest', 'q' => 'lam'], 'api.php'],
    'cart' => ['/index.php?page=cart', ['page' => 'cart'], 'index.php'],
    'checkout' => ['/index.php?page=checkout', ['page' => 'checkout'], 'index.php'],
    'product' => ['/product.php?slug=a142-product', ['slug' => 'a142-product'], 'product.php'],
};
$_SERVER['REQUEST_URI'] = $uri;
$_POST = [];
Bootstrap::initLocale('/' . $entry);
if (in_array($page, ['cart', 'checkout'], true)) Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'A142-SKU'")->fetchColumn(), null, 2);
http_response_code(200);
ob_start();
register_shutdown_function(static function(): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nA142RESULT" . json_encode(['code' => http_response_code(), 'output' => $out], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});
require $root . '/' . $entry;
PHP);
$a142Run = static function(array $args) use ($a142Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a142Fixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'A142RESULT');
    $result = $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true);
    return [$exit, is_array($result) ? $result : null, $at === false ? substr($raw, 0, 800) : substr($raw, 0, min($at, 800))];
};
// Accessible-name check for the rendered markup: a visible form control needs aria-label/aria-labelledby, a
// <label for> pointing at its id, or an enclosing <label>; an <img> needs a non-empty alt unless it is hidden.
$a142Unnamed = static function(string $html): array {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);
    $labelled = [];
    foreach ($xpath->query('//label[@for]') as $label) {
        if (trim($label->textContent) !== '') $labelled[$label->getAttribute('for')] = true;
    }
    $missing = [];
    foreach ($xpath->query('//input|//select|//textarea|//button') as $el) {
        $type = strtolower($el->getAttribute('type'));
        if ($el->nodeName === 'input' && in_array($type, ['hidden', 'submit', 'button', 'reset', 'image'], true)) continue;
        if ($el->nodeName === 'button' && preg_match('~[\p{L}\p{N}]~u', $el->textContent) === 1) continue;
        if ($el->nodeName === 'button' && $xpath->query('.//img[normalize-space(@alt) != ""]', $el)->length > 0) continue;
        if (trim($el->getAttribute('aria-label')) !== '' || trim($el->getAttribute('aria-labelledby')) !== '') continue;
        if ($el->getAttribute('id') !== '' && isset($labelled[$el->getAttribute('id')])) continue;
        if ($xpath->query('ancestor::label', $el)->length > 0) continue;
        $missing[] = $el->nodeName . '[name=' . $el->getAttribute('name') . ' text=' . trim($el->textContent) . ']';
    }
    foreach ($xpath->query('//img') as $img) {
        if ($img->getAttribute('aria-hidden') === 'true' || $img->getAttribute('role') === 'presentation') continue;
        if (trim($img->getAttribute('alt')) === '') $missing[] = 'img[src=' . $img->getAttribute('src') . ']';
    }
    return $missing;
};
$a142App = dirname(TEST_CLI_DIR);
$a142Db = $a142Root . '/shop.sqlite';
try {
    [$a142Exit, , $a142Noise] = $a142Run(['setup', 'none', $a142App, $a142Db, $a142Root]);
    assert_equals([0, ''], [$a142Exit, trim($a142Noise)], 'A14.2: migrated fixture with a product whose first photo has alt:"" and third a stored alt');
    $a142Html = [];
    foreach (['home', 'category', 'search', 'product', 'cart', 'checkout'] as $a142Page) {
        [$a142Exit, $a142Result, $a142Noise] = $a142Run(['run', $a142Page, $a142App, $a142Db, $a142Root]);
        $a142Out = (string)($a142Result['output'] ?? '');
        assert_true($a142Exit === 0 && ($a142Result['code'] ?? 0) === 200 && !str_contains($a142Out, 'Fatal error') && !str_contains($a142Out, 'Uncaught'),
            "A14.2 {$a142Page}: the real storefront page renders (exit {$a142Exit} " . trim($a142Noise)
            . ($a142Exit !== 0 || ($a142Result['code'] ?? 0) !== 200 ? ' HTTP ' . ($a142Result['code'] ?? '?') . ' ' . substr(trim(strip_tags($a142Out)), 0, 400) : '') . ')');
        $a142Html[$a142Page] = $a142Out;
        assert_equals([], $a142Unnamed($a142Out), "A14.2 {$a142Page}: every form control has an accessible name and every image a non-empty alt");
    }
    assert_true(preg_match('~<label class="sr-only" for="([^"]+)">[^<]+</label>\s*<input id="\1" type="search" name="q"~', $a142Html['home']) === 1,
        'A14.2 home: the header search field has a (visually hidden) label tied to it');
    assert_true(preg_match('~name="quantity"[^>]*aria-label="[^"]+: A142 Lamp"~', $a142Html['cart']) === 1,
        'A14.2 cart: the quantity field is named after the product it changes');
    assert_true(preg_match('~class="btn-remove"[^>]*aria-label="[^"]+: A142 Lamp"~', $a142Html['cart']) === 1,
        'A14.2 cart: the ✕ remove button says which product it removes');
    assert_true(preg_match('~<label class="sr-only" for="(qty-\d+)">[^<]+</label><input type="number"[^>]*id="\1"~', $a142Html['product']) === 1,
        'A14.2 product: the quantity field next to "add to cart" has a label');
    preg_match_all('~<img src="[^"]*slider(\d)[^"]*" alt="([^"]*)"~', $a142Html['product'], $a142Alts, PREG_SET_ORDER);
    $a142AltMap = [];
    foreach ($a142Alts as [, $a142N, $a142Alt]) $a142AltMap[$a142N][] = $a142Alt;
    assert_equals(['1' => ['A142 Lamp', 'A142 Lamp'], '2' => ['A142 Lamp'], '3' => ['Lamp switched on']], $a142AltMap,
        'A14.2 product: an empty or missing alt falls back to the product name, a stored alt wins — hero and thumbnails');

    // Search results: sort and filters (price, category facet) on the real search page, without JS.
    $a142Names = static function(string $html): array {
        preg_match_all('~<h3[^>]*>\s*(?:<a[^>]*>)?\s*(A142 (?:Desk )?Lamp)\s*<~', $html, $m);
        return $m[1];
    };
    $a142Search = [];
    foreach (['search_price_asc', 'search_price_desc', 'search_pmax', 'search_cat', 'suggest'] as $a142Page) {
        [$a142Exit, $a142Result, $a142Noise] = $a142Run(['run', $a142Page, $a142App, $a142Db, $a142Root]);
        $a142Search[$a142Page] = (string)($a142Result['output'] ?? '');
        assert_true($a142Exit === 0 && ($a142Result['code'] ?? 0) === 200, "A14.2 {$a142Page}: request succeeds (exit {$a142Exit} " . trim($a142Noise) . ' HTTP ' . ($a142Result['code'] ?? '?') . ')');
    }
    $a142Plain = $a142Html['search'];
    assert_true(preg_match('~<label for="search-sort">[^<]+</label> <select id="search-sort" name="sort" data-submit-on-change><option value="relevance" selected>~', $a142Plain) === 1
        && str_contains($a142Plain, '<option value="price_asc" >') && str_contains($a142Plain, '<option value="newest" >'),
        'A14.2 search: a labelled sort select (relevance first and selected by default, price/name/newest)');
    assert_true(preg_match('~<form class="sidebar search-filters" method="get"><input type="hidden" name="page" value="search"><input type="hidden" name="q" value="lamp">~', $a142Plain) === 1
        && preg_match('~<input type="radio" name="cat" value="\d+" > A142 Category \(1\)</label>~', $a142Plain) === 1
        && preg_match('~<input type="radio" name="cat" value="\d+" > A142 Desk \(1\)</label>~', $a142Plain) === 1
        && str_contains($a142Plain, 'name="pmin"') && str_contains($a142Plain, 'name="pmax"'),
        'A14.2 search: a filter form keeps the query and offers the categories of the matches with counts and a price range');
    assert_equals(['A142 Desk Lamp', 'A142 Lamp'], $a142Names($a142Search['search_price_asc']), 'A14.2 search: sort=price_asc lists the cheaper lamp first');
    assert_equals(['A142 Lamp', 'A142 Desk Lamp'], $a142Names($a142Search['search_price_desc']), 'A14.2 search: sort=price_desc lists the dearer lamp first');
    assert_true(str_contains($a142Search['search_price_desc'], '<option value="price_desc" selected>'), 'A14.2 search: the chosen sort stays selected');
    assert_equals(['A142 Desk Lamp'], $a142Names($a142Search['search_pmax']), 'A14.2 search: pmax=15 (whole currency) keeps only the 10.00 lamp');
    assert_true(str_contains($a142Search['search_pmax'], 'name="pmax" min="0" value="15"'), 'A14.2 search: the price filter keeps its value');
    assert_equals(['A142 Lamp'], $a142Names($a142Search['search_cat']), 'A14.2 search: the category filter keeps only matches from that category');
    $a142Suggest = json_decode(trim($a142Search['suggest']), true);
    $a142SuggestByName = is_array($a142Suggest) ? array_column($a142Suggest, 'url', 'name') : [];
    assert_true(str_ends_with((string)($a142SuggestByName['A142 Lamp'] ?? ''), '/product/a142-product') && str_ends_with((string)($a142SuggestByName['A142 Desk Lamp'] ?? ''), '/product/a142-desk-lamp'),
        'A14.2 suggest: the API hands the theme ready product links, so the autocomplete never builds paths (' . substr($a142Search['suggest'], 0, 300) . ')');

    $a142Js = (string)file_get_contents($a142App . '/themes/default/assets/storefront.js');
    $a142Header = (string)file_get_contents($a142App . '/themes/default/blocks/storefront/header.html') . (string)file_get_contents($a142App . '/themes/default/blocks/global/header.html');
    assert_true(substr_count($a142Header, 'data-search-suggest="{{t.search_suggestions}}"') === 2 && str_contains($a142Js, "querySelectorAll('input[data-search-suggest]').forEach(bindSearchSuggest)")
        && str_contains($a142Js, "setAttribute('role', 'combobox')") && str_contains($a142Js, 'url.origin !== window.location.origin') && str_contains($a142Js, 'option.textContent = String(suggestion.name)'),
        'A14.2 autocomplete: both theme headers opt in, the theme script builds an ARIA combobox from same-origin links with textContent (no HTML from the API)');
} finally {
    $a142Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a142Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a142Tree as $a142Path) $a142Path->isDir() ? @rmdir($a142Path->getPathname()) : @unlink($a142Path->getPathname());
    @rmdir($a142Root);
}
