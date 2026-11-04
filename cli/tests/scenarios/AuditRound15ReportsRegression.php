<?php

// Review round 15, medium: reports, feed and translations.
echo "\n--- Audit round 15: reports, feed and translations ---\n";

// 1. Top products merged the variants of a product into one row (a red and a blue shirt were one "best seller"), and
//    an item refund was spread over every line of the order, so the refunded mug still earned and the shirts lost.
//    Now one row per variant; a completed credit note line comes off the line it refunded, and only the rest of
//    the order's refund (an amount refund, shipping) is spread over what is left of the lines.
$r15rRoot = test_fixture_path('r15-reports');
mkdir($r15rRoot);
$r15rFixture = $r15rRoot . '/top.php';
file_put_contents($r15rFixture, <<<'PHP'
<?php
[, $driver, $phase, $root, $target] = $argv;
require $root . '/src/autoload.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') {
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    $db->exec("USE `{$target}`");
}
Db::init($db, $driver);
StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), '/', '', 'default'));
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', dirname(__FILE__) . '/migrate-' . $driver . '.lock'))->apply();
    $db->exec("INSERT INTO categories (store_id, name, slug, path) VALUES ('default', 'R15R', 'r15r', 'r15r')");
    $category = (int)$db->lastInsertId();
    foreach ([1 => 'R15R-SHIRT', 2 => 'R15R-MUG', 3 => 'R15R-LAMP', 4 => 'R15R-BULB'] as $id => $sku) {
        $db->prepare("INSERT INTO products (id, store_id, category_id, sku, name, slug, price, stock, status) VALUES (?, 'default', ?, ?, ?, ?, 100, 0, 'active')")
            ->execute([$id, $category, $sku, $sku, strtolower($sku)]);
    }
    $order = $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id, created_at, is_synthetic)
        VALUES (?, 'delivered', 'partially_refunded', 'R15R Buyer', 'r15r@example.test', 'card', ?, ?, ?, 'default', ?, 0)");
    $item = $db->prepare("INSERT INTO order_items (order_id, product_id, variant_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $refund = $db->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, status, amount, currency, idempotency_key, raw_response) VALUES (?, 'default', 'stripe', 'refund_succeeded', ?, ?, ?, '{}')");
    // A completed item refund: its credit note line names the order line it refunded.
    $credit = static function (int $orderId, int $itemId, int $gross, string $currency, string $status = 'succeeded') use ($db): void {
        static $n = 0;
        $n++;
        $db->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key) VALUES ('default', ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$orderId, 900 + $n, 900 + $n, $gross, $currency, $status, 'r15r-refund-' . $n]);
        $db->prepare("INSERT INTO credit_note_items (credit_note_id, refund_id, order_item_id, rma_item_id, sku, name, quantity, unit_gross, gross, net, vat, rate_basis_points)
            VALUES (?, ?, ?, ?, 'x', 'x', 1, ?, ?, ?, 0, 0)")->execute([900 + $n, (int)$db->lastInsertId(), $itemId, 900 + $n, $gross, $gross, $gross]);
    };
    $now = gmdate('Y-m-d H:i:s', time() - 3600);
    // EUR: two variants of the shirt and a mug; the mug was returned and refunded (5 €).
    $order->execute(['R15R-EUR', 4000, 4000, 'eur', $now]);
    $eur = (int)$db->lastInsertId();
    $item->execute([$eur, 1, 11, 'R15R-SHIRT-RED', 'R15R-SHIRT — red', 2, 1000, 2000]);
    $item->execute([$eur, 1, 12, 'R15R-SHIRT-BLUE', 'R15R-SHIRT — blue', 1, 1500, 1500]);
    $item->execute([$eur, 2, null, 'R15R-MUG', 'R15R-MUG', 1, 500, 500]);
    $credit($eur, (int)$db->lastInsertId(), 500, 'eur');
    $refund->execute([$eur, 500, 'eur', 'r15r-eur-refund']);
    // CZK: lamp 30, bulb 10, shipping 10; the bulb was refunded (10) and 12 more went back as a goodwill refund.
    // A credit note still waiting for its money takes nothing off yet.
    $order->execute(['R15R-CZK', 4000, 5000, 'czk', $now]);
    $czk = (int)$db->lastInsertId();
    $item->execute([$czk, 3, null, 'R15R-LAMP', 'R15R-LAMP', 1, 3000, 3000]);
    $lamp = (int)$db->lastInsertId();
    $item->execute([$czk, 4, null, 'R15R-BULB', 'R15R-BULB', 1, 1000, 1000]);
    $credit($czk, (int)$db->lastInsertId(), 1000, 'czk');
    $credit($czk, $lamp, 3000, 'czk', 'pending');
    $refund->execute([$czk, 2200, 'czk', 'r15r-czk-refund']);
    // Feed: a book reduced from 10 € to 8 € (the 10 € proven by the price history), and a T-shirt in three sizes.
    $db->prepare("INSERT INTO products (id, store_id, category_id, sku, name, slug, price, compare_price, currency, stock, status) VALUES
        (5, 'default', ?, 'R15F-BOOK', 'R15F Book', 'r15f-book', 800, 1000, 'eur', 5, 'active'),
        (6, 'default', ?, 'R15F-TEE', 'R15F Tee', 'r15f-tee', 1200, NULL, 'eur', 0, 'active')")->execute([$category, $category]);
    $db->prepare("INSERT INTO product_price_history (product_id, price, lowest_prior, changed_at) VALUES (5, 800, 1000, ?)")->execute([gmdate('Y-m-d H:i:s', time() - 5 * 86400)]);
    $variant = $db->prepare("INSERT INTO variants (id, product_id, sku, name, attrs, price_delta, price_absolute, stock, sort_order) VALUES (?, 6, ?, ?, ?, ?, ?, ?, ?)");
    $variant->execute([61, 'R15F-TEE-S', 'S', '{"Size":"S","Color":"red"}', 0, null, 3, 1]);
    $variant->execute([62, 'R15F-TEE-L', 'L', '{"size":"L"}', 0, 1500, 2, 2]);
    $variant->execute([63, 'R15F-TEE-XL', 'XL', '{}', 100, null, 0, 3]);
    exit(0);
}
if ($phase === 'feed') {
    I18n::setLocale('en');
    $xml = simplexml_load_string(MarketingFeed::google('https://r15f.example.test', 'en'));
    $items = [];
    foreach ($xml->channel->item as $item) {
        $g = $item->children('http://base.google.com/ns/1.0');
        if (!str_starts_with((string)$g->id, 'R15F-')) continue;
        $items[(string)$g->id] = array_filter(['title' => (string)$g->title, 'price' => (string)$g->price, 'sale_price' => (string)$g->sale_price,
            'availability' => (string)$g->availability, 'item_group_id' => (string)$g->item_group_id, 'color' => (string)$g->color, 'size' => (string)$g->size,
            'link' => (string)$g->link], static fn(string $v): bool => $v !== '');
    }
    $meta = array_map(static fn(string $l): array => str_getcsv($l, ',', '"', '\\'), preg_split('/\r?\n/', trim(MarketingFeed::meta('https://r15f.example.test', 'en'))));
    $metaRows = [];
    foreach (array_slice($meta, 1) as $cells) {
        $row = array_combine($meta[0], $cells);
        if (str_starts_with($row['id'], 'R15F-')) $metaRows[$row['id']] = [$row['price'], $row['sale_price'] ?? '', $row['item_group_id'] ?? ''];
    }
    $tee = $db->query('SELECT * FROM products WHERE id = 6')->fetch();
    $page = static function (?string $variant) use ($tee): array {
        $_GET = $variant === null ? [] : ['variant' => $variant];
        $product = StorefrontViewModel::product($tee)['product'];
        return [$product['price']['formatted'], array_values(array_column(array_filter($product['variants'], static fn(array $v): bool => $v['selected']), 'sku'))];
    };
    echo json_encode(['items' => $items, 'meta' => $metaRows, 'page' => [$page(null), $page('62'), $page('63'), $page('x')]], JSON_UNESCAPED_UNICODE);
    exit(0);
}
$top = SalesReport::topProducts('default', gmdate('Y-m-d H:i:s', time() - 86400));
$totals = SalesReport::totals('default', gmdate('Y-m-d H:i:s', time() - 86400));
echo json_encode([
    'top' => array_map(static fn(array $rows): array => array_map(static fn(array $r): array => [$r['sku'], $r['name'], $r['variant_id'] ?? null, $r['quantity'], $r['revenue']], $rows), $top),
    'revenue' => array_map(static fn(array $t): int => $t['revenue'], $totals),
], JSON_UNESCAPED_UNICODE);
PHP);
$r15rRun = static function (array $args) use ($r15rFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r15rFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$r15rDrivers = ['sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r15rDrivers[] = 'mysql';
else print "  [SKIP] R15 reports native top products (set TEST_MYSQL_DSN)\n";
$r15rApp = dirname(TEST_CLI_DIR);
try {
    foreach ($r15rDrivers as $r15rDriver) {
        $r15rTarget = $r15rDriver === 'mysql' ? 'bbe_r15r_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test' : $r15rRoot . '/top.sqlite';
        try {
            [$r15rExit, , $r15rErr] = $r15rRun([$r15rDriver, 'setup', $r15rApp, $r15rTarget]);
            assert_equals([0, ''], [$r15rExit, $r15rErr], "R15 reports {$r15rDriver}: migrated fixture with variant and item-refund orders");
            [$r15rExit, $r15rOut, $r15rErr] = $r15rRun([$r15rDriver, 'run', $r15rApp, $r15rTarget]);
            $r15rData = json_decode((string)$r15rOut, true) ?: [];
            assert_equals([0, ''], [$r15rExit, $r15rErr], "R15 reports {$r15rDriver}: the report runs");
            assert_equals([
                ['R15R-SHIRT-RED', 'R15R-SHIRT — red', 11, 2, 2000],
                ['R15R-SHIRT-BLUE', 'R15R-SHIRT — blue', 12, 1, 1500],
                ['R15R-MUG', 'R15R-MUG', null, 1, 0],
            ], $r15rData['top']['eur'] ?? null, "R15 reports {$r15rDriver}: one row per variant; the refunded mug earned nothing and the shirts lost nothing");
            assert_equals([['R15R-LAMP', 'R15R-LAMP', null, 1, 2100], ['R15R-BULB', 'R15R-BULB', null, 1, 0]], $r15rData['top']['czk'] ?? null,
                "R15 reports {$r15rDriver}: the item refund comes off the bulb; the goodwill refund is spread over what is left (lamp 30 x 28/40), a pending credit note takes nothing");
            assert_equals(['czk' => 2800, 'eur' => 3500], $r15rData['revenue'] ?? null, "R15 reports {$r15rDriver}: the totals are unchanged (money received)");

            // 2. The Google feed had one item per product: no variants (sizes, colours, their own prices and stock) and
            //    no sale_price, so a reduced product was listed at its reduced price as the regular one.
            [$r15rExit, $r15rOut, $r15rErr] = $r15rRun([$r15rDriver, 'feed', $r15rApp, $r15rTarget]);
            $r15rFeed = json_decode((string)$r15rOut, true) ?: [];
            assert_equals([0, ''], [$r15rExit, $r15rErr], "R15 feed {$r15rDriver}: the feeds and the product page render");
            $r15rTeeLink = 'https://r15f.example.test/product/r15f-tee?variant=';
            assert_equals([
                'R15F-BOOK' => ['title' => 'R15F Book', 'price' => '10.00 EUR', 'sale_price' => '8.00 EUR', 'availability' => 'in stock', 'link' => 'https://r15f.example.test/product/r15f-book'],
                'R15F-TEE-S' => ['title' => 'R15F Tee — S', 'price' => '12.00 EUR', 'availability' => 'in stock', 'item_group_id' => 'R15F-TEE', 'color' => 'red', 'size' => 'S', 'link' => $r15rTeeLink . '61'],
                'R15F-TEE-L' => ['title' => 'R15F Tee — L', 'price' => '15.00 EUR', 'availability' => 'in stock', 'item_group_id' => 'R15F-TEE', 'size' => 'L', 'link' => $r15rTeeLink . '62'],
                'R15F-TEE-XL' => ['title' => 'R15F Tee — XL', 'price' => '13.00 EUR', 'availability' => 'out of stock', 'item_group_id' => 'R15F-TEE', 'link' => $r15rTeeLink . '63'],
            ], $r15rFeed['items'] ?? null, "R15 feed {$r15rDriver}: the reduced book has price + sale_price; the T-shirt is one item per variant (own SKU, price, stock, size/colour) grouped by the product SKU");
            assert_equals(['R15F-BOOK' => ['10.00 EUR', '8.00 EUR', ''], 'R15F-TEE-S' => ['12.00 EUR', '', 'R15F-TEE'], 'R15F-TEE-L' => ['15.00 EUR', '', 'R15F-TEE'], 'R15F-TEE-XL' => ['13.00 EUR', '', 'R15F-TEE']],
                $r15rFeed['meta'] ?? null, "R15 feed {$r15rDriver}: the Meta catalog CSV carries the same items with sale_price and item_group_id");
            assert_equals([['12,00 €', ['R15F-TEE-S']], ['15,00 €', ['R15F-TEE-L']], ['12,00 €', ['R15F-TEE-S']], ['12,00 €', ['R15F-TEE-S']]], $r15rFeed['page'] ?? null,
                "R15 feed {$r15rDriver}: the variant link opens the product page on that variant at its price; a sold-out or unknown variant falls back to the default");
        } finally {
            if ($r15rDriver === 'mysql') $r15rRun([$r15rDriver, 'teardown', $r15rApp, $r15rTarget]);
        }
    }
} finally {
    foreach (glob($r15rRoot . '/*') ?: [] as $r15rFile) @unlink($r15rFile);
    @rmdir($r15rRoot);
}
