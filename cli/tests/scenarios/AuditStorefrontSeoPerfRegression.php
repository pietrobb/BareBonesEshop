<?php

// Storefront SEO / performance audit: canonical + robots meta for pages without a view model, hreflang and the
// language switcher built from the canonical request (subdirectory installs, no tracking/filter params), JSON-LD
// for the current store, absolute og:image / Product URLs, store-prefixed browser API, batched listing cards,
// cached and split sitemap, streamed store-scoped merchant feeds, section *url validation, legal page date and
// the cart shipping country. Storefront pages render in a subprocess against a migrated SQLite fixture.
echo "\n--- Audit storefront SEO/performance: canonical, robots, hreflang, JSON-LD, API store context, N+1, sitemap, feeds ---\n";

$sfbRoot = test_fixture_path('storefront-seo-perf');
mkdir($sfbRoot);
mkdir($sfbRoot . '/sessions');
mkdir($sfbRoot . '/cache');
mkdir($sfbRoot . '/src');
$sfbFixture = $sfbRoot . '/request.php';
file_put_contents($sfbFixture, <<<'PHP'
<?php
[, $phase, $action, $root, $target, $dir, $optionsJson] = $argv;
$options = json_decode($optionsJson, true, 32, JSON_THROW_ON_ERROR);
if ($action === 'product_model') {
    // StorefrontViewModel/Seo read <root>/config.php themselves: load copies next to a fixture config.php.
    require $dir . '/src/StorefrontViewModel.php';
    require $dir . '/src/Seo.php';
}
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . $target, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Db::init($db, 'sqlite');
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate.lock'))->apply();
    $category = $db->prepare("INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES ('default', ?, ?, NULL, ?, 0)");
    $category->execute(['sfb-cat', 'SFB Category', 'sfb-cat']);
    $categoryId = (int)$db->lastInsertId();
    $product = $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, currency, stock, status, images, translations, updated_at)
        VALUES ('default', ?, ?, ?, ?, ?, ?, 5, 'active', ?, ?, '2026-01-01 00:00:00')");
    for ($i = 1; $i <= 30; $i++) $product->execute([$categoryId, 'SFB-' . $i, 'sfb-p-' . $i, 'SFB Product ' . $i, 1000 + $i, 'eur', '["sfb-' . $i . '.jpg"]', '{}']);
    $product->execute([$categoryId, 'SFB-FEW', 'sfb-few', 'SFB Few', 900, 'eur', '[]', '{}']);
    $product->execute([$categoryId, 'SFB-MAIN', 'sfb-main', 'SFB Main', 1500, '', '["sfb-main.jpg"]',
        json_encode(['cs' => ['slug' => 'sfb-main-cs', 'name' => 'SFB Hlavni']], JSON_THROW_ON_ERROR)]);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    if ($warehouse > 0) $db->exec("INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) SELECT {$warehouse}, sku, 5, 0 FROM products WHERE sku LIKE 'SFB-%'");
    $ids = $db->query("SELECT id, slug FROM products WHERE slug LIKE 'sfb-%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $ids = array_flip($ids);
    // Registered like Import/ProductEditor do: the storefront resolves a translated slug through slug_registry.
    $db->prepare("INSERT INTO slug_registry (slug, locale, entity, entity_id, store_id) VALUES ('sfb-main-cs', 'cs', 'product', ?, 'default')")->execute([$ids['sfb-main']]);
    $relation = $db->prepare("INSERT INTO product_related_products (product_id, related_product_id, relation_type, sort_order) VALUES (?, ?, ?, ?)");
    for ($i = 1; $i <= 30; $i++) {
        $relation->execute([$ids['sfb-main'], $ids['sfb-p-' . $i], $i % 2 ? 'related' : 'accessory', $i]);
        if ($i <= 5) $relation->execute([$ids['sfb-few'], $ids['sfb-p-' . $i], $i % 2 ? 'related' : 'accessory', $i]);
    }
    // The CZ store shows sfb-main at a promotional 7.00: the feed must export that price, not the 15.00 base.
    $db->prepare("INSERT INTO promotions (store_id, code, name, scope, priority, stacking, conditions, actions, active)
        VALUES ('cz', 'SFB-PROMO', 'SFB promo', 'catalog', 1, 'exclusive', ?, ?, 1)")
        ->execute([json_encode(['product_ids' => [$ids['sfb-main']]]), json_encode(['type' => 'fixed_price', 'price' => 700])]);
    exit(0);
}

$config = is_file($root . '/config.php') ? require $root . '/config.php' : require $root . '/config.example.php';
$rawAppUrl = rtrim((string)($config['app_url'] ?? ''), '/');
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => (string)($options['app_url'] ?? '')] + $config);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate.sqlite'));
Cache::setDir($dir . '/cache');
$emit = static function(array $result): void { echo "\nSFBRESULT" . json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR); };
$productId = static fn(string $slug): int => (int)$db->query("SELECT id FROM products WHERE slug = " . $db->quote($slug))->fetchColumn();

if ($action === 'page') {
    ini_set('session.use_cookies', '0');
    session_save_path($dir . '/sessions');
    session_start();
    $_SESSION = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REMOTE_ADDR'] = '127.0.40.1';
    $_SERVER['HTTP_HOST'] = 'shop.example';
    $_SERVER['SCRIPT_NAME'] = (string)$options['script_name'];
    $_SERVER['REQUEST_URI'] = (string)$options['uri'];
    $_GET = (array)($options['get'] ?? []);
    if (!empty($options['cart'])) {
        Bootstrap::initLocale((string)$options['uri']);
        Cart::add($productId('sfb-p-1'), null, 1);
    }
    http_response_code(200);
    ob_start();
    register_shutdown_function(static function() use ($emit, $rawAppUrl): void {
        $out = '';
        while (ob_get_level() > 0) $out = ob_get_clean() . $out;
        $emit(['output' => $out, 'status' => http_response_code(), 'store' => StoreContext::current()->id(),
            'locale' => I18n::locale(), 'raw_app_url' => $rawAppUrl, 'today' => date('Y-m-d')]);
    });
    require $root . '/' . $options['script'];
    exit(0);
}

$store = (string)($options['store'] ?? 'default');
Bootstrap::initStoreContext($store === 'cz' ? '/cs/' : '/', '', $store);
I18n::setLocale((string)($options['locale'] ?? 'en'));
$_SERVER['SCRIPT_NAME'] = (string)($options['script_name'] ?? '/index.php');

if ($action === 'queries') {
    // The same rows the storefront passes in: raw product rows (category listing) and the product page model.
    $counter = new QueryCountingPDO('sqlite:' . $target, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    Db::init($counter, 'sqlite');
    $rows = $counter->query("SELECT * FROM products WHERE slug LIKE 'sfb-p-%' ORDER BY id")->fetchAll();
    $measure = static function(callable $work) use ($counter): int {
        $work();
        $counter->resetQueryCount();
        $work();
        return $counter->queryCount();
    };
    $few = Products::bySlug('sfb-few');
    $main = Products::bySlug('sfb-main');
    $emit([
        'cards_5' => $measure(static fn() => StorefrontViewModel::productCards(array_slice($rows, 0, 5))),
        'cards_30' => $measure(static fn() => StorefrontViewModel::productCards($rows)),
        'card_count_30' => count(StorefrontViewModel::productCards($rows)),
        'product_5' => $measure(static fn() => StorefrontViewModel::product($few)),
        'product_30' => $measure(static fn() => StorefrontViewModel::product($main)),
        'relations_30' => array_sum(array_map('count', StorefrontViewModel::product($main)['product']['relations'])),
        'single_card_purchasable' => StorefrontViewModel::productCard($rows[0])['availability']['purchasable'],
        'batched_card_purchasable' => StorefrontViewModel::productCards([$rows[0]])[0]['availability']['purchasable'],
    ]);
    exit(0);
}

if ($action === 'product_model') {
    $model = StorefrontViewModel::product(Products::bySlug('sfb-main'));
    $emit(['seo' => $model['page']['seo'], 'head' => Template::viewModelSeoHead($model, 'SFB')]);
    exit(0);
}

if ($action === 'sitemap') {
    Sitemap::setMaxUrlsForTesting(null);
    $full = Sitemap::xml('https://shop.example/shop');
    Sitemap::setMaxUrlsForTesting(10);
    $index = Sitemap::xml('https://shop.example/shop');
    preg_match_all('#<loc>https://shop\.example/shop/sitemap-(\d+)\.xml</loc>#', $index, $parts);
    $locs = [];
    $sizes = [];
    foreach ($parts[1] as $part) {
        preg_match_all('#<loc>([^<]+)</loc>#', Sitemap::xml('https://shop.example/shop', (int)$part), $m);
        $sizes[] = count($m[1]);
        $locs = array_merge($locs, $m[1]);
    }
    $outOfRange = false;
    try { Sitemap::xml('https://shop.example/shop', count($parts[1]) + 1); } catch (OutOfRangeException) { $outOfRange = true; }
    preg_match_all('#<loc>([^<]+)</loc>#', $full, $fullLocs);
    $mainEntries = array_values(array_filter(explode('<url>', $full), static fn(string $entry): bool => str_contains($entry, '/product/sfb-main')));
    // Cached per store: a second request reuses the file, a product save (updated_at) rebuilds it.
    $calls = 0;
    $writer = static function($out) use (&$calls): void { $calls++; fwrite($out, "<urlset>{$calls}</urlset>"); };
    $send = static function() use ($writer): string { ob_start(); PublicFeedCache::send('sitemap-0.xml', 'https://shop.example/shop', $writer); return (string)ob_get_clean(); };
    $first = $send();
    $second = $send();
    $callsAfterTwo = $calls;
    $db->exec("UPDATE products SET updated_at = '2099-01-01 00:00:00' WHERE slug = 'sfb-p-2'");
    $third = $send();
    $emit([
        'full_is_urlset' => str_contains($full, '<urlset'), 'index_is_index' => str_contains($index, '<sitemapindex'),
        'part_sizes' => $sizes, 'part_locs' => $locs, 'full_locs' => $fullLocs[1], 'out_of_range' => $outOfRange,
        'cache' => [$first, $second, $third], 'calls_after_two' => $callsAfterTwo, 'calls' => $calls, 'main_entries' => $mainEntries,
        'cache_file' => is_file($dir . '/cache/stores/default/storefront/public/sitemap-0.xml'),
    ]);
    exit(0);
}

if ($action === 'feed') {
    $google = MarketingFeed::google('https://shop.example/shop', 'cs');
    $meta = MarketingFeed::meta('https://shop.example/shop', 'cs');
    $emit(['google' => $google, 'meta' => $meta, 'rows_is_generator' => MarketingFeed::rows('cs') instanceof Generator]);
    exit(0);
}

if ($action === 'fix_slugs') {
    $repair = SlugRepair::run($db);
    $emit(['repair' => $repair, 'main' => (string)$db->query("SELECT slug FROM products WHERE sku = 'SFB-MAIN'")->fetchColumn(),
        'cat' => (string)$db->query("SELECT slug FROM categories WHERE name = 'SFB Category'")->fetchColumn(),
        'aliases' => $db->query("SELECT entity, slug FROM slug_registry WHERE locale = 'previous' AND slug IN ('sfb-main', 'sfb-cat') ORDER BY entity")->fetchAll(PDO::FETCH_KEY_PAIR)]);
    exit(0);
}

if ($action === 'cart_country') {
    $emit(['country' => CheckoutService::formState([], false)['country']]);
    exit(0);
}
PHP);

$sfbRun = static function(string $phase, string $action, array $options = []) use ($sfbFixture, $sfbRoot): array {
    $process = proc_open([PHP_BINARY, $sfbFixture, $phase, $action, dirname(TEST_CLI_DIR), $sfbRoot . '/shop.sqlite', $sfbRoot, json_encode($options, JSON_THROW_ON_ERROR)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'SFBRESULT');
    $result = $at === false ? null : json_decode(substr($raw, $at + 9), true);
    return [$exit, is_array($result) ? $result : [], $at === false ? substr($raw, 0, 1200) : trim(substr($raw, 0, min($at, 1200)))];
};
// Rendered hrefs carry the installation's configured app_url when one is set; compare their base-relative form.
$sfbLocal = static function(string $href, array $page, string $base = ''): string {
    $app = (string)($page['raw_app_url'] ?? '');
    return $app !== '' && str_starts_with($href, $app) ? $base . substr($href, strlen($app)) : $href;
};
$sfbAttr = static function(string $html, string $pattern): array {
    preg_match_all($pattern, $html, $m);
    return array_map(static fn(string $value): string => html_entity_decode($value, ENT_QUOTES | ENT_HTML5), $m[1]);
};
$sfbPage = static function(array $options) use ($sfbRun): array {
    [, $page, $noise] = $sfbRun('run', 'page', $options);
    if (!isset($page['output'])) $page = ['output' => '', 'status' => 0, 'noise' => $noise];
    return $page;
};

try {
    [$sfbExit, , $sfbNoise] = $sfbRun('setup', 'setup');
    assert_equals([0, ''], [$sfbExit, $sfbNoise], 'Storefront SEO: migrated fixture with 30 listing products, relations and a CZ promotion');

    // --- 1/2: category page in a subdirectory install under the /cs store, with sort, filter and tracking parameters ---
    $sfbCategory = $sfbPage(['script' => 'index.php', 'script_name' => '/shop/index.php',
        'uri' => '/shop/cs/?page=category&slug=sfb-cat&sort=name_desc&spec%5BColor%5D%5B%5D=red&utm_source=news&pg=2',
        'get' => ['page' => 'category', 'slug' => 'sfb-cat', 'sort' => 'name_desc', 'spec' => ['Color' => ['red']], 'utm_source' => 'news', 'pg' => '2']]);
    $sfbHtml = (string)$sfbCategory['output'];
    $sfbHead = (string)strstr($sfbHtml, '</head>', true);
    $sfbCanonical = array_map(static fn(string $href): string => $sfbLocal($href, $sfbCategory, '/shop'), $sfbAttr($sfbHead, '#<link rel="canonical" href="([^"]*)"#'));
    assert_equals(['/shop/cs/category/sfb-cat?pg=2'], $sfbCanonical, 'Storefront SEO: a category page has one canonical without sort/filter/tracking, keeping its page number (' . ($sfbCategory['noise'] ?? '') . ')');
    assert_equals(['index,follow'], $sfbAttr($sfbHead, '#<meta name="robots" content="([^"]*)"#'), 'Storefront SEO: the category page is indexable');
    $sfbAlternates = array_map(static fn(string $href): string => $sfbLocal($href, $sfbCategory, '/shop'), $sfbAttr($sfbHead, '#<link rel="alternate" hreflang="[^"]+" href="([^"]*)"#'));
    assert_true(in_array('/shop/category/sfb-cat?pg=2', $sfbAlternates, true) && in_array('/shop/cs/category/sfb-cat?pg=2', $sfbAlternates, true),
        'Storefront SEO: hreflang alternates point at each store\'s canonical category URL (' . implode(' ', $sfbAlternates) . ')');
    $sfbSwitcher = $sfbAttr((string)(preg_match('#<div class="lang-switch">(.*?)</div>#s', $sfbHtml, $m) ? $m[1] : ''), '#href="([^"]*)"#');
    assert_true($sfbSwitcher !== [] && in_array('/shop/cs/?page=category&slug=sfb-cat&pg=2', $sfbSwitcher, true) && in_array('/shop/?page=category&slug=sfb-cat&pg=2', $sfbSwitcher, true),
        'Storefront SEO: the language switcher keeps the base path once and only canonical parameters (' . implode(' ', $sfbSwitcher) . ')');
    foreach (array_merge($sfbAlternates, $sfbSwitcher) as $sfbHref) {
        assert_true(!str_contains($sfbHref, '/shop/shop') && !str_contains($sfbHref, 'utm_source') && !str_contains($sfbHref, 'sort=') && !str_contains($sfbHref, 'spec'),
            "Storefront SEO: alternate/switcher link {$sfbHref} has no doubled base path, tracking, sort or filter parameter");
    }
    $sfbTrail = array_values(array_filter(array_map(static fn(string $json): array => json_decode(html_entity_decode($json), true) ?: [],
        $sfbAttr($sfbHead, '#<script type="application/ld\+json"[^>]*>(.*?)</script>#s')), static fn(array $ld): bool => ($ld['@type'] ?? '') === 'BreadcrumbList'));
    assert_equals('/shop/cs/category/sfb-cat', $sfbLocal((string)($sfbTrail[0]['itemListElement'][1]['item'] ?? ''), $sfbCategory, '/shop'), 'Storefront SEO: the category BreadcrumbList links the store-prefixed canonical category');

    // --- 4: the browser runtime calls the API and posts cart actions in the page's store context ---
    $sfbRuntime = preg_match('#window\.BareBonesStorefront=(\{.*?\});#s', $sfbHtml, $m) ? json_decode($m[1], true) : [];
    assert_equals(['/shop/cs/api/v1/storefront', '/shop/cs/'], [$sfbRuntime['apiUrl'] ?? null, $sfbRuntime['currentUrl'] ?? null], 'Storefront API: a /cs page calls /shop/cs/api/v1/storefront and posts to /shop/cs/');
    assert_true(isset($sfbRuntime['text']['shippingError'], $sfbRuntime['text']['paymentError']) && $sfbRuntime['text']['shippingError'] !== 'shipping_load_error',
        'Checkout JS: translated shipping/payment load-error texts reach the runtime config');
    assert_equals('api/v1/index.php', UrlAliases::dispatch('/cs/api/v1/storefront/cart'), 'Storefront API: /cs/api/v1/storefront/cart is routed (redirect.php fallback, router.php, nginx) to the API');
    assert_equals(null, UrlAliases::dispatch('/cs/api.php'), 'Storefront API: the removed /cs/api.php is not routed');
    assert_equals(['action' => 'cart', 'get' => []], ApiRoute::resolve('/cs/api/v1/storefront/cart', '', ['cz' => ['url_prefix' => '/cs'], 'czb' => ['url_prefix' => '/cs/b2b']]), 'Storefront API: the API strips the store prefix before routing');
    $sfbApi = $sfbPage(['script' => 'redirect.php', 'script_name' => '/redirect.php', 'uri' => '/cs/api/v1/storefront/cart', 'get' => ['path' => '/cs/api/v1/storefront/cart']]);
    assert_equals(['cz', 'cs'], [$sfbApi['store'] ?? null, $sfbApi['locale'] ?? null], 'Storefront API: /cs/api/v1/storefront/cart runs in the CZ store and Czech locale');
    assert_true(is_array(json_decode(trim((string)$sfbApi['output']), true)), 'Storefront API: /cs/api/v1/storefront/cart answers the action as JSON');

    // --- 1: default sort adds no sort= to pagination; robots.txt matches the real URLs ---
    $sfbDefault = $sfbPage(['script' => 'index.php', 'script_name' => '/index.php', 'uri' => '/?page=category&slug=sfb-cat', 'get' => ['page' => 'category', 'slug' => 'sfb-cat']]);
    $sfbPaging = $sfbAttr((string)(preg_match('#<nav[^>]*class="[^"]*pagination[^"]*"[^>]*>(.*?)</nav>#s', (string)$sfbDefault['output'], $m) ? $m[1] : (string)$sfbDefault['output']), '#href="([^"]*pg=\d+[^"]*)"#');
    assert_true($sfbPaging !== [] && array_filter($sfbPaging, static fn(string $href): bool => str_contains($href, 'sort=')) === [],
        'Storefront SEO: pagination of the default order carries no sort= (' . implode(' ', $sfbPaging) . ')');
    $sfbRobots = Robots::txt('https://shop.example');
    $sfbRules = [];
    foreach (explode("\n", $sfbRobots) as $line) if (str_starts_with($line, 'Disallow: ')) $sfbRules[] = trim(substr($line, 10));
    $sfbBlocked = static function(string $url) use ($sfbRules): bool {
        foreach ($sfbRules as $rule) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote(rtrim($rule, '$'), '#')) . (str_ends_with($rule, '$') ? '$' : '') . '#';
            if (preg_match($regex, $url)) return true;
        }
        return false;
    };
    foreach (['/?page=cart', '/cs/?page=cart', '/?page=checkout', '/pl/?page=order-done&order=X', '/account.php', '/cs/account.php?p=login', '/wishlist', '/cs/wishlist', '/cs/api/v1/storefront/cart', '/api/v1/storefront/cart', '/?page=category&slug=x&sort=name_desc'] as $sfbUrl) {
        assert_true($sfbBlocked($sfbUrl), "robots.txt: {$sfbUrl} is disallowed");
    }
    foreach (['/', '/?page=category&slug=x&pg=2', '/category/x?pg=3', '/cs/?page=category&slug=x&pg=2', '/product/x', '/cs/product/x', '/page/about'] as $sfbUrl) {
        assert_true(!$sfbBlocked($sfbUrl), "robots.txt: {$sfbUrl} stays crawlable");
    }

    // --- 1/2: search is noindex,follow; transactional and error pages are noindex without hreflang ---
    $sfbSearch = $sfbPage(['script' => 'index.php', 'script_name' => '/index.php', 'uri' => '/?page=search&q=sfb', 'get' => ['page' => 'search', 'q' => 'sfb']]);
    $sfbSearchHead = (string)strstr((string)$sfbSearch['output'], '</head>', true);
    assert_equals(['noindex,follow'], $sfbAttr($sfbSearchHead, '#<meta name="robots" content="([^"]*)"#'), 'Storefront SEO: search results are noindex,follow');
    foreach ([
        'cart' => ['script' => 'index.php', 'uri' => '/cs/?page=cart', 'get' => ['page' => 'cart'], 'cart' => true],
        'checkout' => ['script' => 'index.php', 'uri' => '/?page=checkout', 'get' => ['page' => 'checkout'], 'cart' => true],
        'order-done' => ['script' => 'index.php', 'uri' => '/?page=order-done&order=SFB-1', 'get' => ['page' => 'order-done', 'order' => 'SFB-1']],
        'account' => ['script' => 'account.php', 'uri' => '/account.php?p=login', 'get' => ['p' => 'login']],
        'wishlist' => ['script' => 'wishlist.php', 'uri' => '/wishlist', 'get' => []],
        'missing category' => ['script' => 'index.php', 'uri' => '/?page=category&slug=sfb-nope', 'get' => ['page' => 'category', 'slug' => 'sfb-nope']],
        'missing product' => ['script' => 'index.php', 'uri' => '/product/sfb-nope', 'get' => ['page' => 'product', 'slug' => 'sfb-nope']],
    ] as $sfbLabel => $sfbOptions) {
        $sfbPrivate = $sfbPage($sfbOptions + ['script_name' => '/' . $sfbOptions['script']]);
        $sfbPrivateHead = (string)strstr((string)$sfbPrivate['output'], '</head>', true);
        assert_equals(['noindex'], $sfbAttr($sfbPrivateHead, '#<meta name="robots" content="([^"]*)"#'), "Storefront SEO: {$sfbLabel} page is noindex (" . ($sfbPrivate['noise'] ?? '') . ')');
        assert_true($sfbPrivateHead !== '' && !str_contains($sfbPrivateHead, 'hreflang=') && !str_contains($sfbPrivateHead, 'rel="canonical"'), "Storefront SEO: {$sfbLabel} page has no hreflang alternates or canonical");
    }

    // --- 10: the cart estimate uses the store's country, not a hardcoded SK ---
    [, $sfbCountry] = $sfbRun('run', 'cart_country', ['store' => 'cz', 'locale' => 'cs']);
    assert_equals('CZ', $sfbCountry['country'] ?? null, 'Cart: the CZ store estimates shipping to CZ');
    $sfbCartSource = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront/CheckoutPages.php');
    assert_true(preg_match("#cartQuote\([^\n]*'SK'\)#", $sfbCartSource) === 0, 'Cart: the shipping estimate has no hardcoded SK destination');

    // --- 3: home page JSON-LD describes the current store ---
    $sfbHome = $sfbPage(['script' => 'index.php', 'script_name' => '/index.php', 'uri' => '/cs/', 'get' => []]);
    $sfbLd = [];
    foreach ($sfbAttr((string)strstr((string)$sfbHome['output'], '</head>', true), '#<script type="application/ld\+json"[^>]*>(.*?)</script>#s') as $json) {
        $decoded = json_decode(html_entity_decode($json), true);
        if (is_array($decoded)) $sfbLd[(string)($decoded['@type'] ?? '')] = $decoded;
    }
    $sfbCzLegal = (string)(Bootstrap::stores()['stores']['cz']['legal']['name'] ?? 'cz');
    assert_equals([$sfbCzLegal, '/cs/', '/cs/'], [$sfbLd['Organization']['name'] ?? null, $sfbLocal((string)($sfbLd['Organization']['url'] ?? ''), $sfbHome), $sfbLocal((string)($sfbLd['WebSite']['url'] ?? ''), $sfbHome)],
        'Storefront SEO: Organization/WebSite JSON-LD on /cs/ describe the CZ store and its home');

    // --- 3: og:image and Product JSON-LD url/image are absolute, from app_url ---
    copy(dirname(TEST_CLI_DIR) . '/src/StorefrontViewModel.php', $sfbRoot . '/src/StorefrontViewModel.php');
    copy(dirname(TEST_CLI_DIR) . '/src/Seo.php', $sfbRoot . '/src/Seo.php');
    file_put_contents($sfbRoot . '/config.php', '<?php return ["app_url" => "https://shop.example/shop"];');
    [, $sfbModel, $sfbNoise] = $sfbRun('run', 'product_model', ['script_name' => '/shop/index.php']);
    $sfbProductLd = $sfbModel['seo']['json_ld'][0] ?? [];
    assert_equals(['https://shop.example/shop/product/sfb-main', 'https://shop.example/shop/product/sfb-main', ['https://shop.example/shop/uploads/full/sfb-main.jpg']],
        [$sfbProductLd['url'] ?? null, $sfbProductLd['offers']['url'] ?? null, $sfbProductLd['image'] ?? null], "Storefront SEO: Product JSON-LD url, offer url and image are absolute ({$sfbNoise})");
    assert_true(str_contains((string)($sfbModel['head'] ?? ''), '<meta property="og:image" content="https://shop.example/shop/uploads/full/sfb-main.jpg">'), 'Storefront SEO: og:image is absolute');
    // R7: each hreflang alternate names the product's URL in that language (Czech slug sfb-main-cs), from either store.
    [, $sfbCzModel, $sfbNoise] = $sfbRun('run', 'product_model', ['script_name' => '/shop/index.php', 'store' => 'cz', 'locale' => 'cs']);
    $sfbHreflang = static function(array $model): array {
        preg_match_all('#<link rel="alternate" hreflang="([^"]+)" href="([^"]*)"#', (string)($model['head'] ?? ''), $m);
        return array_combine($m[1], array_map(static fn(string $href): string => html_entity_decode($href, ENT_QUOTES | ENT_HTML5), $m[2]));
    };
    $sfbCzLocale = (string)(Bootstrap::stores()['stores']['cz']['default_locale'] ?? 'cs');
    $sfbDefaultLocale = (string)(Bootstrap::stores()['stores'][(string)(Bootstrap::stores()['default_store'] ?? 'default')]['default_locale'] ?? 'sk');
    foreach (['default store' => $sfbModel, 'CZ store' => $sfbCzModel] as $sfbLabel => $sfbHeadModel) {
        $sfbLinks = $sfbHreflang($sfbHeadModel);
        assert_equals(['https://shop.example/shop/cs/product/sfb-main-cs', 'https://shop.example/shop/product/sfb-main', 'https://shop.example/shop/product/sfb-main'],
            [$sfbLinks[$sfbCzLocale] ?? null, $sfbLinks[$sfbDefaultLocale] ?? null, $sfbLinks['x-default'] ?? null],
            "R7 hreflang: on the {$sfbLabel} product page the Czech alternate uses the Czech slug, the others the base slug ({$sfbNoise})");
    }
    assert_true(str_contains((string)($sfbCzModel['head'] ?? ''), '<link rel="canonical" href="https://shop.example/shop/cs/product/sfb-main-cs">'),
        'R7 hreflang: the CZ product canonical is its own Czech alternate');

    // --- 6: listing cards and the product page do not issue queries per product ---
    [, $sfbQueries, $sfbNoise] = $sfbRun('run', 'queries', ['store' => 'default', 'locale' => 'en']);
    assert_equals(30, $sfbQueries['card_count_30'] ?? null, "Storefront N+1: 30 listing cards are built ({$sfbNoise})");
    assert_true(isset($sfbQueries['cards_5'], $sfbQueries['cards_30']) && $sfbQueries['cards_30'] === $sfbQueries['cards_5'],
        'Storefront N+1: 30 category cards cost the same queries as 5 (' . ($sfbQueries['cards_5'] ?? '?') . ' vs ' . ($sfbQueries['cards_30'] ?? '?') . ')');
    assert_equals(30, $sfbQueries['relations_30'] ?? null, 'Storefront N+1: the product page shows all 30 related products');
    assert_true(isset($sfbQueries['product_5'], $sfbQueries['product_30']) && $sfbQueries['product_30'] <= $sfbQueries['product_5'] + 1,
        'Storefront N+1: a product page with 30 related products costs no more queries than with 5 (' . ($sfbQueries['product_5'] ?? '?') . ' vs ' . ($sfbQueries['product_30'] ?? '?') . ')');
    assert_equals([true, true], [$sfbQueries['single_card_purchasable'] ?? null, $sfbQueries['batched_card_purchasable'] ?? null], 'Storefront N+1: batched purchasability matches the single-card result');

    // --- 7: sitemap index past the URL limit, per-store cache with invalidation on product changes ---
    [, $sfbSitemap, $sfbNoise] = $sfbRun('run', 'sitemap', ['store' => 'default', 'locale' => 'en']);
    assert_true(($sfbSitemap['full_is_urlset'] ?? false) && ($sfbSitemap['index_is_index'] ?? false), "Sitemap: under the limit /sitemap.xml is a urlset, past it a sitemap index ({$sfbNoise})");
    assert_true(($sfbSitemap['part_sizes'] ?? []) !== [] && max($sfbSitemap['part_sizes']) <= 10 && count($sfbSitemap['part_sizes']) > 1, 'Sitemap: every part holds at most the per-file limit');
    assert_equals($sfbSitemap['full_locs'] ?? null, $sfbSitemap['part_locs'] ?? [], 'Sitemap: the parts together list exactly the URLs of the single sitemap, in order');
    assert_true($sfbSitemap['out_of_range'] ?? false, 'Sitemap: a part past the last one is refused (404)');
    $sfbMainLocs = [];
    foreach ($sfbSitemap['main_entries'] ?? [] as $sfbEntry) {
        preg_match('#<loc>([^<]+)</loc>#', $sfbEntry, $m);
        preg_match('#hreflang="' . preg_quote($sfbCzLocale, '#') . '" href="([^"]+)"#', $sfbEntry, $cz);
        $sfbMainLocs[$m[1] ?? ''] = $cz[1] ?? '';
    }
    assert_true(isset($sfbMainLocs['https://shop.example/shop/cs/product/sfb-main-cs'], $sfbMainLocs['https://shop.example/shop/product/sfb-main'])
        && !isset($sfbMainLocs['https://shop.example/shop/cs/product/sfb-main']),
        'R7 sitemap: the CZ store lists the product under its Czech slug, the default store under the base slug (' . implode(' ', array_keys($sfbMainLocs)) . ')');
    assert_equals(['https://shop.example/shop/cs/product/sfb-main-cs'], array_values(array_unique($sfbMainLocs)),
        'R7 sitemap: every entry of the product names the Czech slug as its Czech alternate');
    assert_equals(['<urlset>1</urlset>', '<urlset>1</urlset>', '<urlset>2</urlset>'], $sfbSitemap['cache'] ?? null, 'Sitemap cache: the second request is served from cache, a product change rebuilds it');
    assert_equals([1, 2, true], [$sfbSitemap['calls_after_two'] ?? null, $sfbSitemap['calls'] ?? null, $sfbSitemap['cache_file'] ?? null], 'Sitemap cache: generated once per change into the store cache namespace');

    // --- 7: merchant feed for the CZ store: prefixed link with the store's slug, shown price, store currency ---
    [, $sfbFeed, $sfbNoise] = $sfbRun('run', 'feed', ['store' => 'cz', 'locale' => 'cs', 'script_name' => '/shop/feed.php']);
    $sfbGoogle = (string)($sfbFeed['google'] ?? '');
    $sfbItem = preg_match('#<item>(?:(?!</item>).)*<g:id>SFB-MAIN</g:id>.*?</item>#s', $sfbGoogle, $m) ? $m[0] : '';
    assert_true(str_contains($sfbItem, '<g:link>https://shop.example/shop/cs/product/sfb-main-cs</g:link>'), "Feed: the CZ link carries the /cs prefix and the Czech slug ({$sfbNoise})");
    assert_true(str_contains($sfbItem, '<g:price>7.00 CZK</g:price>'), 'Feed: the price is the promoted price the CZ storefront shows, in the store currency when the product has none');
    assert_true(str_contains($sfbItem, '<g:image_link>https://shop.example/shop/uploads/full/sfb-main.jpg</g:image_link>'), 'Feed: the image link is the storefront image URL');
    assert_true(str_contains((string)($sfbFeed['meta'] ?? ''), 'https://shop.example/shop/cs/product/sfb-main-cs') && str_contains((string)($sfbFeed['meta'] ?? ''), '7.00 CZK'), 'Feed: the Meta CSV exports the same link and price');
    assert_true($sfbFeed['rows_is_generator'] ?? false, 'Feed: rows are streamed from a generator in chunks, not loaded at once');

    // --- 9: legal pages show the template's date, not today ---
    $sfbLegal = $sfbPage(['script' => 'index.php', 'script_name' => '/index.php', 'uri' => '/?page=privacy', 'get' => ['page' => 'privacy']]);
    assert_true(str_contains((string)$sfbLegal['output'], '2026-08-02') && ($sfbLegal['today'] === '2026-08-02' || !str_contains((string)$sfbLegal['output'], (string)$sfbLegal['today'])),
        'Legal page: "last updated" is the date stored with the template, not the render date');
    foreach (glob(dirname(TEST_CLI_DIR) . '/templates/legal/*/*.html') ?: [] as $sfbTemplate) {
        assert_true(preg_match('/<!--\s*legal-updated:\s*\d{4}-\d{2}-\d{2}\s*-->/', (string)file_get_contents($sfbTemplate)) === 1, 'Legal page: ' . basename(dirname($sfbTemplate)) . '/' . basename($sfbTemplate) . ' declares its legal-updated date');
    }

    // --- R7: cli/fix-slugs.php keeps the replaced slugs, and the storefront 301s them to the new URL (last: renames the fixture) ---
    [, $sfbFix, $sfbNoise] = $sfbRun('run', 'fix_slugs', ['store' => 'default', 'locale' => 'en']);
    assert_true(($sfbFix['main'] ?? 'sfb-main') !== 'sfb-main' && ($sfbFix['cat'] ?? 'sfb-cat') !== 'sfb-cat' && ($sfbFix['repair']['products'] ?? 0) > 0,
        "R7 fix-slugs: product and category slugs are re-slugified ({$sfbNoise})");
    assert_equals(['category' => 'sfb-cat', 'product' => 'sfb-main'], $sfbFix['aliases'] ?? null, 'R7 fix-slugs: each replaced slug stays registered for its product/category');
    $sfbStatus = static fn(string $uri, array $get): int => (int)($sfbPage(['script' => 'index.php', 'script_name' => '/index.php', 'uri' => $uri, 'get' => $get])['status'] ?? 0);
    $sfbNewMain = (string)($sfbFix['main'] ?? '');
    assert_equals([301, 200, 301, 200], [
        $sfbStatus('/product/sfb-main', ['page' => 'product', 'slug' => 'sfb-main']),
        $sfbStatus('/product/' . $sfbNewMain, ['page' => 'product', 'slug' => $sfbNewMain]),
        $sfbStatus('/category/sfb-cat', ['page' => 'category', 'slug' => 'sfb-cat']),
        $sfbStatus('/category/' . ($sfbFix['cat'] ?? ''), ['page' => 'category', 'slug' => (string)($sfbFix['cat'] ?? '')]),
    ], 'R7 fix-slugs: the old product and category URLs answer 301 (were 404), the new ones 200');
    assert_equals([301, 200], [
        $sfbStatus('/cs/product/' . $sfbNewMain, ['page' => 'product', 'slug' => $sfbNewMain]),
        $sfbStatus('/cs/product/sfb-main-cs', ['page' => 'product', 'slug' => 'sfb-main-cs']),
    ], 'R7 slugs: the CZ store redirects the base slug to the Czech slug it uses as canonical');
    assert_equals(['/shop/product/new?lang=de&pg=2', null], [
        SlugRepair::canonicalRedirect('old', 'new', '/shop/product/new', ['page' => 'product', 'slug' => 'old', 'lang' => 'de', 'pg' => '2']),
        SlugRepair::canonicalRedirect('new', 'new', '/shop/product/new', ['slug' => 'new']),
    ], 'R7 slugs: the redirect keeps the request parameters except the route ones, and a canonical slug is not redirected');
} finally {
    $sfbTree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sfbRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($sfbTree as $sfbPath) $sfbPath->isDir() ? @rmdir($sfbPath->getPathname()) : @unlink($sfbPath->getPathname());
    @rmdir($sfbRoot);
}

// --- 8: section *url values are validated like content.cta (local path or HTTPS only) ---
$sfbSections = static fn(array $section, bool $lenient = false): array => json_decode(ContentService::sanitizeSections([['id' => 'hero', 'type' => 'hero'] + $section], $lenient), true)[0];
foreach ([
    'data link' => ['data' => ['link_url' => 'javascript:alert(1)']],
    'data image' => ['data' => ['image_url' => 'data:text/html;base64,PHNjcmlwdD4=']],
    'nested item' => ['data' => ['items' => [['title' => 'x', 'url' => '//evil.example/x']]]],
    'settings' => ['settings' => ['background_url' => 'http://evil.example/x.png']],
    'content' => ['content' => ['heading' => 'x', 'video_url' => "java\tscript:alert(1)"]],
] as $sfbLabel => $sfbSection) {
    $sfbRejected = false;
    try { $sfbSections($sfbSection); } catch (InvalidArgumentException) { $sfbRejected = true; }
    assert_true($sfbRejected, "Sections: an unsafe *url in {$sfbLabel} is rejected on save");
}
$sfbClean = $sfbSections(['data' => ['link_url' => '/category/x', 'image_url' => 'https://cdn.example/a.png', 'items' => [['url' => 'javascript:alert(1)']]]], true);
assert_equals(['/category/x', 'https://cdn.example/a.png', ''], [$sfbClean['data']['link_url'], $sfbClean['data']['image_url'], $sfbClean['data']['items'][0]['url']],
    'Sections: local and HTTPS URLs render; a stored unsafe URL renders blank');

// --- 3: JsonLd builders pick the requested store ---
$sfbStores = ['default_store' => 'a', 'stores' => ['a' => ['name' => 'A', 'url_prefix' => '', 'legal' => ['name' => 'A s.r.o.']], 'b' => ['name' => 'B', 'url_prefix' => '/b', 'legal' => ['name' => 'B GmbH']]]];
assert_equals(['B GmbH', 'https://shop.example/b/', 'https://shop.example/b/?page=search&q={search_term_string}'], [
    JsonLd::organization('https://shop.example', $sfbStores, 'b')['name'], JsonLd::organization('https://shop.example', $sfbStores, 'b')['url'],
    JsonLd::webSite('https://shop.example', $sfbStores, 'b')['potentialAction']['target']['urlTemplate'],
], 'JSON-LD: Organization/WebSite of the current store use its legal name and prefixed home');
assert_equals('A s.r.o.', JsonLd::organization('https://shop.example', $sfbStores)['name'], 'JSON-LD: without a store the default store is described');

// --- 5: checkout JS sequences requests, shows a translated error state and never parses formatted money ---
$sfbJs = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/themes/default/assets/storefront.js');
assert_true(!str_contains($sfbJs, 'parseMoney') && str_contains($sfbJs, 'data-price-cents'), 'Checkout JS: the shipping price comes from integer cents, not the formatted label');
assert_true(substr_count($sfbJs, 'request !== shippingRequest') >= 1 && substr_count($sfbJs, 'request !== paymentRequest') >= 1, 'Checkout JS: stale shipping/payment responses are dropped');
assert_true(str_contains($sfbJs, 'text.shippingError') && str_contains($sfbJs, 'text.paymentError') && str_contains($sfbJs, 'role="alert"'), 'Checkout JS: failed requests show the translated error state');
