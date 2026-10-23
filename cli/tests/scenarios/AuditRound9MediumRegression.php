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
