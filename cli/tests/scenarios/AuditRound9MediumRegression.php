<?php
// Round 9 medium findings.
//
// Low stock: the dashboard card (StockAlerts::summary) joined every SKU's summed stock to products and variants, twice
// (count + list), and on MySQL drove the variant branch from all active products; 100k products took 3.6 s per
// dashboard load. Now only SKUs at or below the highest effective `low` are joined, the count comes with the list in
// one query and MySQL joins in stock → variant → product order. The result must stay the same, including a product
// whose own `low` override is above the configured one. SQLite always, MySQL 8 / MariaDB 11.4 via TEST_MYSQL_DSN.

$r9lsDrivers = ['sqlite' => test_fixture_path('r9-low-stock.sqlite')];
if (getenv('TEST_MYSQL_DSN')) $r9lsDrivers['mysql'] = 'bbe_r9ls_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R9 low-stock summary on native MySQL/MariaDB (set TEST_MYSQL_DSN)\n";
$r9lsPrev = [Db::get(), Db::driver()];
$r9lsN = 4000;
foreach ($r9lsDrivers as $r9lsDriver => $r9lsTarget) {
    $r9lsSqlite = $r9lsDriver === 'sqlite';
    if ($r9lsSqlite) {
        @unlink($r9lsTarget);
        $r9lsDb = new PDO('sqlite:' . $r9lsTarget);
    } else {
        $r9lsDb = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]);
        $r9lsDb->exec("CREATE DATABASE `{$r9lsTarget}`");
        $r9lsDb->exec("USE `{$r9lsTarget}`");
    }
    $r9lsDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $r9lsDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    try {
        (new Migrator($r9lsDb, dirname(TEST_CLI_DIR) . '/migrations', sys_get_temp_dir() . '/bbe-r9ls-' . getmypid() . '.lock'))->apply();
        $r9lsDb->exec("INSERT INTO categories (id, store_id, name, slug) VALUES (1, 'default', 'C', 'c')");
        $r9lsDb->exec("INSERT INTO warehouses (id, store_id, code, name) VALUES (9001, 'default', 'W1', 'W1'), (9002, 'default', 'W2', 'W2')");
        // Expected levels computed here, independently of StockAlerts: config low 5 / critical 2.
        $r9lsExpected = [];
        $r9lsLevel = static fn(int $available, int $low, int $critical): ?string
            => $available <= 0 ? 'out' : ($available <= $critical ? 'critical' : ($available <= $low ? 'low' : null));
        $r9lsDb->beginTransaction();
        for ($r9lsB = 0; $r9lsB < $r9lsN; $r9lsB += 500) {
            [$r9lsP, $r9lsV, $r9lsW] = [[], [], []];
            for ($r9lsI = $r9lsB + 1; $r9lsI <= $r9lsB + 500; $r9lsI++) {
                // Product 7 has its own `low` of 50 and 30 available: low only because of the override.
                $r9lsOwnLow = $r9lsI === 7 ? 50 : null;
                $r9lsP[] = "({$r9lsI}, 'default', 1, 'P{$r9lsI}', 'Product {$r9lsI}', 'p-{$r9lsI}', 1000, 'active', 'physical', " . ($r9lsOwnLow ?? 'NULL') . ')';
                $r9lsV[] = "({$r9lsI}, {$r9lsI}, 'V{$r9lsI}', 'Var {$r9lsI}')";
                $r9lsQa = $r9lsI === 7 ? 28 : ($r9lsI % 40 === 0 ? 1 : 40 + $r9lsI % 7);
                $r9lsW[] = "('default', 9001, 'P{$r9lsI}', {$r9lsQa}, 0)";
                $r9lsW[] = "('default', 9002, 'P{$r9lsI}', 3, 1)";
                $r9lsVa = $r9lsI % 97 === 0 ? 0 : ($r9lsI % 61 === 0 ? 4 : 25);
                $r9lsW[] = "('default', 9001, 'V{$r9lsI}', {$r9lsVa}, 0)";
                if ($r9lsL = $r9lsLevel($r9lsQa + 2, $r9lsOwnLow ?? 5, 2)) $r9lsExpected["P{$r9lsI}"] = [$r9lsL, $r9lsQa + 2];
                if ($r9lsL = $r9lsLevel($r9lsVa, $r9lsOwnLow ?? 5, 2)) $r9lsExpected["V{$r9lsI}"] = [$r9lsL, $r9lsVa];
            }
            $r9lsDb->exec('INSERT INTO products (id, store_id, category_id, sku, name, slug, price, status, product_type, stock_low_threshold) VALUES ' . implode(',', $r9lsP));
            $r9lsDb->exec('INSERT INTO variants (id, product_id, sku, name) VALUES ' . implode(',', $r9lsV));
            $r9lsDb->exec('INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ' . implode(',', $r9lsW));
        }
        $r9lsDb->commit();
        if (!$r9lsSqlite) $r9lsDb->query('ANALYZE TABLE products, variants, warehouse_stock')->fetchAll();
        $r9lsRank = ['low' => 1, 'critical' => 2, 'out' => 3];
        uksort($r9lsExpected, static fn(string $a, string $b): int
            => [$r9lsRank[$r9lsExpected[$b][0]], $r9lsExpected[$a][1], $a] <=> [$r9lsRank[$r9lsExpected[$a][0]], $r9lsExpected[$b][1], $b]);

        Db::init($r9lsDb, $r9lsDriver);
        StockAlerts::setConfigForTesting(['stock_alerts' => ['low' => 5, 'critical' => 2]]);
        $r9lsReads = static function () use ($r9lsDb, $r9lsSqlite): int {
            if ($r9lsSqlite) return 0;
            return (int)$r9lsDb->query("SHOW SESSION STATUS LIKE 'Handler_read_key'")->fetch()['Value'];
        };
        $r9lsSummary = StockAlerts::summary(6);
        $r9lsGot = [];
        foreach ($r9lsSummary['products'] as $r9lsRow) $r9lsGot[$r9lsRow['sku']] = [$r9lsRow['level'], $r9lsRow['available']];
        assert_equals([count($r9lsExpected), array_slice($r9lsExpected, 0, 6, true)], [$r9lsSummary['count'], $r9lsGot],
            "R9 {$r9lsDriver} low stock: the dashboard count and its first rows (worst first) are unchanged");
        $r9lsAll = [];
        foreach (StockAlerts::belowLow() as $r9lsRow) $r9lsAll[$r9lsRow['sku']] = [$r9lsRow['level'], $r9lsRow['available']];
        assert_equals([$r9lsExpected, count($r9lsExpected)], [$r9lsAll, StockAlerts::belowLowCount()],
            "R9 {$r9lsDriver} low stock: the full list and count still include a SKU that is low only by its product's own higher threshold (P7)");
        if (!$r9lsSqlite) {
            // Index lookups (Handler_read_key) of one summary without per-product overrides: about two per low SKU,
            // where joining every SKU took four per catalog SKU.
            $r9lsDb->exec('UPDATE products SET stock_low_threshold = NULL WHERE id = 7');
            $r9lsBefore = $r9lsReads();
            StockAlerts::summary(6);
            $r9lsLookups = $r9lsReads() - $r9lsBefore;
            assert_true($r9lsLookups < $r9lsN / 2,
                "R9 {$r9lsDriver} low stock: the dashboard summary looks up the catalog only for low SKUs, not for all " . (2 * $r9lsN) . " (index lookups: {$r9lsLookups})");
        }
    } finally {
        Db::init($r9lsPrev[0], $r9lsPrev[1]);
        StockAlerts::setConfigForTesting(null);
        if ($r9lsSqlite) {
            $r9lsDb = null;
            @unlink($r9lsTarget);
        } else {
            $r9lsDb->exec("DROP DATABASE IF EXISTS `{$r9lsTarget}`");
            $r9lsDb = null;
        }
    }
}

// Sitemap: each <url> repeats every store's hreflang alternate, so with several stores 50,000 URLs made 42-45 MB
// parts, close to the 50 MB limit. Parts are now cut by size as well. A low byte limit on the suite catalog: every
// part stays within it, and the parts together list exactly the URLs of the single sitemap, in order.
$r9smBase = 'https://shop.example';
$r9smUrls = static fn(string $xml): array => preg_match_all('~<url>.*?</url>~s', $xml, $m) ? $m[0] : [];
$r9smFull = $r9smUrls(Sitemap::xml($r9smBase, 0));
$r9smLargest = max(array_map('strlen', $r9smFull));
$r9smLimit = 3 * $r9smLargest + 400;
$r9smParts = [];
try {
    if (!method_exists(Sitemap::class, 'setMaxBytesForTesting')) throw new RuntimeException('no byte limit');
    Sitemap::setMaxBytesForTesting($r9smLimit);
    $r9smIndex = Sitemap::xml($r9smBase, 0);
    $r9smCount = preg_match_all('~<sitemap><loc>~', $r9smIndex);
    for ($r9smI = 1; $r9smI <= $r9smCount; $r9smI++) $r9smParts[$r9smI] = Sitemap::xml($r9smBase, $r9smI);
    try {
        Sitemap::xml($r9smBase, $r9smCount + 1);
        $r9smBeyond = 'rendered';
    } catch (OutOfRangeException) {
        $r9smBeyond = 'out of range';
    }
} catch (RuntimeException $e) {
    [$r9smIndex, $r9smCount, $r9smBeyond] = [$e->getMessage(), 0, ''];
} finally {
    if (method_exists(Sitemap::class, 'setMaxBytesForTesting')) Sitemap::setMaxBytesForTesting(null);
}
$r9smSizes = array_map('strlen', $r9smParts);
assert_true(count($r9smFull) > 9 && str_contains($r9smIndex, '<sitemapindex') && $r9smCount >= 3 && max($r9smSizes ?: [PHP_INT_MAX]) <= $r9smLimit,
    "R9 sitemap: past the byte limit /sitemap.xml is an index and every part stays within the limit ({$r9smLimit} B; parts: " . implode(', ', $r9smSizes) . ')');
assert_equals([$r9smFull, 'out of range'], [array_merge(...array_map($r9smUrls, array_values($r9smParts ?: [''])) ), $r9smBeyond],
    'R9 sitemap: the size-cut parts list exactly the URLs of the single sitemap, in order, and no part beyond the last');

// Delivery note: it travels in the parcel, but was printed in the admin's language. Each note is now in the language
// its order was placed in (an unknown order locale keeps the admin's), and the admin's locale is restored afterwards.
$r9dnLocale = I18n::locale();
$r9dnNote = static fn(string $number, string $locale): array => ['order' => ['order_number' => $number, 'locale' => $locale, 'created_at' => '2026-10-23 10:00:00',
    'customer_name' => 'R9 Recipient', 'customer_phone' => '', 'shipping_method' => '', 'notes' => ''], 'items' => [['sku' => 'R9DN', 'name' => 'R9 Item', 'quantity' => 2]],
    'address' => ['street' => 'Main 1', 'zip' => '81101', 'city' => 'Bratislava', 'country' => 'sk'], 'pickup' => null];
$r9dnNotes = [$r9dnNote('R9DN-SK', 'sk'), $r9dnNote('R9DN-DE', 'de'), $r9dnNote('R9DN-XX', 'xx')];
try {
    I18n::setLocale('en');
    $r9dnHtml = DeliveryNote::html($r9dnNotes);
    $r9dnPdf = DeliveryNote::pdf([$r9dnNotes[1]]);
    $r9dnAfter = I18n::locale();
} finally {
    I18n::setLocale($r9dnLocale);
}
preg_match_all('~<section class="note"[^>]*data-delivery-note="([^"]+)"[^>]*>\s*<h1>([^<]+)</h1>~u', $r9dnHtml, $r9dnTitles, PREG_SET_ORDER);
assert_equals(['R9DN-SK' => 'Dodací list R9DN-SK', 'R9DN-DE' => 'Lieferschein R9DN-DE', 'R9DN-XX' => 'Delivery note R9DN-XX'], array_column($r9dnTitles, 2, 1),
    'R9 delivery note: each note is titled in its order\'s language (unknown locale: the admin\'s)');
assert_true(str_contains($r9dnPdf, 'Lieferschein') && !str_contains($r9dnPdf, 'Delivery note'), 'R9 delivery note: the PDF of a German order is in German');
assert_equals('en', $r9dnAfter, 'R9 delivery note: the admin\'s locale is restored after printing');

// Admin order totals: the order detail listed subtotal, shipping, payment fee and VAT but no discount, and VAT as an
// addend although gross prices already include it, so the lines never added up to the total. Orders::totalLines() gives the
// lines that add up (discount negative) and VAT as an "of which" line when it is included; net prices still add it.
$r9otAdds = static function (array $order): bool {
    $sum = 0;
    foreach (Orders::totalLines($order) as $line) if ($line['key'] !== 'vat_included') $sum += $line['cents'];
    return $sum === (int)$order['total'];
};
$r9otGross = ['subtotal' => 10000, 'discount_amount' => 1000, 'shipping_cost' => 390, 'payment_fee' => 100, 'tax_amount' => 1748, 'total' => 9490];
$r9otNet = ['subtotal' => 10000, 'discount_amount' => 0, 'shipping_cost' => 500, 'payment_fee' => 0, 'tax_amount' => 2100, 'total' => 12600];
assert_equals([['subtotal', 10000], ['discount', -1000], ['shipping', 390], ['payment_fee', 100], ['vat_included', 1748]],
    array_map(static fn(array $l): array => [$l['key'], $l['cents']], Orders::totalLines($r9otGross)), 'R9 order totals: gross prices list the discount and VAT as an included line');
assert_true($r9otAdds($r9otGross) && $r9otAdds($r9otNet) && in_array('vat', array_column(Orders::totalLines($r9otNet), 'key'), true),
    'R9 order totals: the listed lines add up to the order total, with gross and with net prices');
$r9otAdmin = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Orders.php');
$r9otAccount = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/AccountViewModel.php');
assert_true(str_contains($r9otAdmin, 'Orders::totalLines($order)') && str_contains($r9otAccount, 'Orders::totalLines($order)') && I18n::tIn('admin.order.vat_included', 'sk') === 'Z toho DPH',
    'R9 order totals: the admin order detail and the customer\'s order page list these lines');

// Doctor/preflight payments: doctor passed "Payment provider selected: stripe" for any provider_defaults value and the
// preflight checked Stripe keys only when provider_defaults said stripe, although checkout charges through each active
// method's provider. PaymentReadiness reports every active online method's provider and that provider's credentials.
$r9prDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r9prDb->exec('CREATE TABLE payment_methods (store_id TEXT, code TEXT, provider TEXT, active INTEGER, requires_online INTEGER)');
$r9prDb->exec("INSERT INTO payment_methods VALUES ('default', 'card', 'stripe', 1, 1), ('cz', 'payu', 'payu', 1, 1), ('cz', 'bnpl', 'acme', 1, 1),
    ('default', 'gopay', 'gopay', 0, 1), ('default', 'bank_transfer', 'manual', 1, 0)");
$r9prCfg = ['provider_defaults' => ['payment' => 'gopay'], 'stripe_secret_key' => 'sk_test_' . str_repeat('A1', 12), 'stripe_webhook_secret' => 'whsec_' . str_repeat('b2', 10),
    'payu_pos_id' => '300746', 'payu_client_id' => '300746', 'payu_client_secret' => str_repeat('c', 32)];
$r9prChecks = [];
foreach (PaymentReadiness::checks($r9prDb, $r9prCfg) as [$r9prStatus, $r9prName, $r9prMessage]) $r9prChecks[$r9prName] = [$r9prStatus, $r9prMessage];
assert_equals(['payment.provider.acme' => 'fail', 'payment.provider.payu' => 'fail', 'payment.provider.stripe' => 'pass'], array_map(static fn(array $c): string => $c[0], $r9prChecks),
    'R9 payments readiness: one check per provider of the active online methods (inactive GoPay, offline manual and provider_defaults are not what checkout charges)');
assert_true(str_contains($r9prChecks['payment.provider.payu'][1], 'payu_second_key') && !str_contains($r9prChecks['payment.provider.payu'][1], 'payu_pos_id')
    && str_contains($r9prChecks['payment.provider.payu'][1], 'cz:payu') && str_contains($r9prChecks['payment.provider.acme'][1], 'Unknown payment provider acme'),
    'R9 payments readiness: names the missing PayU key and the store method, and an unknown provider');
$r9prKeyless = [];
foreach (PaymentReadiness::checks($r9prDb, ['stripe_secret_key' => 'sk_test_...', 'stripe_webhook_secret' => ''] + $r9prCfg) as [$r9prStatus, $r9prName]) $r9prKeyless[$r9prName] = $r9prStatus;
$r9prDb->exec('UPDATE payment_methods SET active = 0 WHERE requires_online = 1');
assert_true($r9prKeyless['payment.provider.stripe'] === 'fail' && PaymentReadiness::checks($r9prDb, $r9prCfg)[0][0] === 'warn',
    'R9 payments readiness: a placeholder Stripe key fails; with no online method it only warns that checkout is offline-only');
$r9prDb = null;
