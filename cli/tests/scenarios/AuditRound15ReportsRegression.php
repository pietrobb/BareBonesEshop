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
    $pages = [$page(null), $page('62'), $page('63'), $page('x')];
    // R16 low: in a store's second language the product link already has ?lang=, so the variant goes after "&".
    StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), '/', '', 'us'));
    I18n::setLocale('es');
    $esLinks = [];
    foreach (simplexml_load_string(MarketingFeed::google('https://r15f.example.test', 'es'))->channel->item as $item) {
        $g = $item->children('http://base.google.com/ns/1.0');
        if (str_starts_with((string)$g->id, 'R15F-TEE-')) $esLinks[(string)$g->id] = (string)$g->link;
    }
    echo json_encode(['items' => $items, 'meta' => $metaRows, 'page' => $pages, 'es_links' => $esLinks], JSON_UNESCAPED_UNICODE);
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
            assert_equals([['12,00 €', ['R15F-TEE-S']], ['15,00 €', ['R15F-TEE-L']], ['13,00 €', ['R15F-TEE-XL']], ['12,00 €', ['R15F-TEE-S']]], $r15rFeed['page'] ?? null,
                "R15 feed {$r15rDriver}: the variant link opens the product page on that variant at its price; (R23) a sold-out one too, at its own price; an unknown variant falls back to the default");
            $r15rEsLinks = $r15rFeed['es_links'] ?? [];
            assert_true(count($r15rEsLinks) === 3 && array_filter($r15rEsLinks, static fn(string $l, string $sku): bool
                    => preg_match('#^https://r15f\.example\.test/[^?]*/product/r15f-tee\?lang=es&variant=' . ['S' => 61, 'L' => 62, 'XL' => 63][substr($sku, 9)] . '$#', $l) !== 1, ARRAY_FILTER_USE_BOTH) === [],
                "R16 low feed {$r15rDriver}: a variant link in a store's second language is ?lang=es&variant=N, not ?lang=es?variant=N (" . json_encode($r15rEsLinks) . ')');
        } finally {
            if ($r15rDriver === 'mysql') $r15rRun([$r15rDriver, 'teardown', $r15rApp, $r15rTarget]);
        }
    }
} finally {
    foreach (glob($r15rRoot . '/*') ?: [] as $r15rFile) @unlink($r15rFile);
    @rmdir($r15rRoot);
}

// 3. Codes and English where the customer or merchant reads names. The B2B method is seeded as "Invoice purchase" and
//    that name was frozen into the order, so a Slovak invoice said "Spôsob úhrady: Invoice purchase"; the mails passed
//    the raw code (bank_transfer); the admin shipment list and label block read "packeta Z123"; and nothing told the
//    merchant that without MAIL_FROM every e-mail leaves from noreply@localhost.invalid.
$r15tOrder = static fn(string $label, string $code = 'invoice'): array => ['locale' => 'sk', 'payment_method' => $code,
    'payment_method_snapshot' => json_encode(['code' => $code, 'label' => $label, 'service_type' => $code], JSON_UNESCAPED_UNICODE)];
assert_equals(['Nákup na faktúru', 'Purchase on invoice', 'Kauf auf Rechnung', 'Nákup na faktúru', 'Firemný účet 30 dní'], [
    PaymentService::orderLabel($r15tOrder('Invoice purchase')),
    PaymentService::orderLabel($r15tOrder('Invoice purchase'), 'en'),
    PaymentService::orderLabel($r15tOrder('Invoice purchase'), 'de'),
    PaymentService::orderLabel(['locale' => 'sk', 'payment_method' => 'invoice', 'payment_method_snapshot' => '']),
    PaymentService::orderLabel($r15tOrder('Firemný účet 30 dní')),
], 'R15 translations: the seeded B2B method reads in the document\'s language, also on orders that froze the English name; a merchant\'s own name stays');
assert_equals(['Bankový prevod', 'Bank transfer'], [PaymentService::orderLabel($r15tOrder('', 'bank_transfer')), PaymentService::orderLabel($r15tOrder('', 'bank_transfer'), 'en')],
    'R15 translations: without a frozen name the method type is translated, not shown as its code');
foreach (['sk', 'cs', 'pl', 'hu', 'de', 'es', 'en'] as $r15tLocale) {
    $r15tText = I18n::tIn('methods.payment.invoice', $r15tLocale);
    assert_true($r15tText !== 'methods.payment.invoice' && $r15tText !== 'Invoice purchase' && I18n::tIn('methods.payment.invoice_desc', $r15tLocale) !== 'methods.payment.invoice_desc',
        "R15 translations ({$r15tLocale}): the invoice method has a translated name and description");
}

$r15tLocaleBefore = I18n::locale();
I18n::setLocale('sk');
try {
    require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Shipments.php';
    ob_start();
    renderShipmentLabelForm(['id' => 0, 'currency' => 'eur'], ['id' => 0], [['carrier' => 'packeta', 'awb' => 'Z123', 'cod_amount' => 0]], ['packeta' => null], '#');
    renderShipmentLabelForm(['id' => 0, 'currency' => 'eur'], ['id' => 0], [], ['packeta' => 'production API is not connected'], '#');
    $r15tAdmin = (string)ob_get_clean();
    assert_true(str_contains($r15tAdmin, 'Packeta (Zásielkovňa) Z123') && str_contains($r15tAdmin, 'Packeta (Zásielkovňa)') && !preg_match('/>[^<]*\bpacketa\b/', $r15tAdmin),
        'R15 translations: the admin label block names the carrier, not its code');
    $r15tShipments = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin/Shipments.php');
    assert_true(str_contains($r15tShipments, "MethodLabels::carrier((string)\$shipment['carrier'])"), 'R15 translations: the admin shipment list names the carrier, not its code');
} finally {
    I18n::setLocale($r15tLocaleBefore);
}

$r15tFromBefore = getenv('MAIL_FROM');
$r15tDir = test_fixture_path('r15-mail-from');
mkdir($r15tDir . '/app', 0777, true);
mkdir($r15tDir . '/state', 0777, true);
try {
    $r15tLevels = [];
    foreach (['', 'not-an-address', 'noreply@yourshop.example.com', 'shop@mail.invalid', 'obchod@mojobchod.sk'] as $r15tFrom) {
        putenv($r15tFrom === '' ? 'MAIL_FROM' : 'MAIL_FROM=' . $r15tFrom);
        $r15tUpdater = array_column((new Updater($r15tDir . '/app', $r15tDir . '/state'))->doctor(null, null, false, [])['checks'], 'level', 'name');
        $r15tSetup = array_column(Setup::doctor(['db_driver' => 'sqlite', 'private_runtime_dir' => $r15tDir . '/state'], $r15tDir . '/app')['checks'] ?? [], 'status', 'name');
        $r15tLevels[] = [UpgradeChecks::mailSender()[0], $r15tUpdater['mail_from'] ?? null, $r15tSetup['provider.mail_from'] ?? null];
    }
    assert_equals([['warn', 'warn', 'warn'], ['fail', 'fail', 'fail'], ['warn', 'warn', 'warn'], ['warn', 'warn', 'warn'], ['ok', 'ok', 'pass']], $r15tLevels,
        'R15 translations: update doctor and setup doctor warn while MAIL_FROM is unset (noreply@localhost.invalid) or an example/reserved domain, and fail on an invalid one');
    putenv('MAIL_FROM');
    assert_true(str_contains(UpgradeChecks::mailSender()[1], 'noreply@localhost.invalid'), 'R15 translations: the warning names the address the mails would really leave from');
} finally {
    putenv($r15tFromBefore === false ? 'MAIL_FROM' : 'MAIL_FROM=' . $r15tFromBefore);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r15tDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $r15tFile) {
        $r15tFile->isDir() ? @rmdir($r15tFile->getPathname()) : @unlink($r15tFile->getPathname());
    }
    @rmdir($r15tDir);
}
