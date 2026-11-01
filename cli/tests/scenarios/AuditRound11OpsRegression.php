<?php

// Round 11 review (2026-11-01) "operations and data": legal texts are overridden per store, not per language; the
// price-list repair completes a CZ/HU/PL scope a failed old run left without COD and most countries; setAvailable on
// MySQL counts a change committed meanwhile; doctor ignores payment methods of removed stores; a cached API listing
// is not served past the end of a sale; GDPR retention covers shipped orders, complaints, withdrawals, RMA texts and
// pricing runs. (Redis jobs queued before the upgrade: AuditRedisQueueRegression.)
echo "\n--- Audit round 11: operations and data ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r11oFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R11 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r11oAgo = static fn(int $seconds): string => gmdate('Y-m-d H:i:s', time() - $seconds);
$r11oRmTree = static function (string $dir): void {
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($dir);
};

// Legal texts per store: the US store's English terms are not the terms of an EU store that also speaks English.
$r11oTmp = sys_get_temp_dir() . '/bbe-r11o-' . getmypid() . '-' . bin2hex(random_bytes(3));
try {
    mkdir($r11oTmp . '/data/legal/en', 0700, true);
    $r11oStoresFile = $r11oTmp . '/stores.json';
    file_put_contents($r11oStoresFile, json_encode(['default_store' => 'default', 'stores' => [
        'default' => ['default_locale' => 'sk', 'languages' => ['sk', 'en']],
        'us' => ['default_locale' => 'en', 'languages' => ['en']],
        'pl' => ['default_locale' => 'pl', 'languages' => ['pl']],
    ]]));
    StoreSettings::setPathForTesting($r11oStoresFile);
    DataOverrides::setDirForTesting($r11oTmp . '/data');
    $r11oSeller = ['name' => 'R11O Trading', 'email' => 'legal@r11o.test'];
    file_put_contents($r11oTmp . '/data/legal/en/terms.html', "<h1>R11O shared English terms</h1>\n");
    try {
        $r11oSplit = DataOverrides::splitLegacyLegal();
        assert_true(is_array($r11oSplit) && count($r11oSplit) === 2 && !is_file($r11oTmp . '/data/legal/en/terms.html'),
            'R11 legal: a text saved per language before 2.1 is copied to each store of that language and the shared file removed');
    } catch (Throwable $e) { $r11oFail('legal split', $e); }
    assert_true(str_contains(ConsumerNotice::renderLegal('terms', 'en', $r11oSeller, 'default'), 'R11O shared English terms')
        && str_contains(ConsumerNotice::renderLegal('terms', 'en', $r11oSeller, 'us'), 'R11O shared English terms'),
        'R11 legal: after the split both English-speaking stores still show the text they showed before');
    assert_true(DataOverrides::saveLegal('terms', 'en', "<h1>R11O US terms of {{name}}</h1>\n", 'us'), 'R11 legal: the owner saves the US store\'s English terms');
    assert_true(str_contains(ConsumerNotice::renderLegal('terms', 'en', $r11oSeller, 'us'), 'R11O US terms of R11O Trading'),
        'R11 legal: the US store shows its own terms');
    assert_true(!str_contains(ConsumerNotice::renderLegal('terms', 'en', $r11oSeller, 'default'), 'R11O US terms'),
        'R11 legal: the EU store speaking English keeps its terms when the US terms change');
    assert_true(!DataOverrides::saveLegal('terms', 'en', 'x', '../default'), 'R11 legal: only valid store ids are saved');
    assert_true(DataOverrides::saveLegal('terms', 'en', '', 'us') && str_contains(ConsumerNotice::renderLegal('terms', 'en', $r11oSeller, 'default'), 'R11O shared English terms'),
        'R11 legal: resetting the US text leaves the other store\'s text alone');
} catch (Throwable $e) { $r11oFail('legal per store', $e); } finally {
    StoreSettings::setPathForTesting(null);
    DataOverrides::setDirForTesting(null);
    $r11oRmTree($r11oTmp);
}

// A cached API listing or product is not served past the end (or before the start) of a sale.
$r11oCacheProp = new ReflectionProperty(Cache::class, 'cacheDir');
$r11oCacheProp->setAccessible(true);
$r11oCacheBefore = $r11oCacheProp->getValue();
$r11oCacheDir = sys_get_temp_dir() . '/bbe-r11o-cache-' . getmypid() . '-' . bin2hex(random_bytes(3));
$r11oPromo = 'r11o-ended-' . bin2hex(random_bytes(3));
try {
    mkdir($r11oCacheDir, 0700, true);
    Cache::setDir($r11oCacheDir);
    $r11oPath = 'sk/product/r11o-cached.json';
    $r11oFile = (new ReflectionMethod(Cache::class, 'path'))->invoke(null, $r11oPath);
    $pdo->prepare("INSERT INTO promotions (store_id, code, name, scope, priority, stacking, conditions, actions, valid_from, valid_to, active)
        VALUES (?, ?, 'R11O sale', 'catalog', 100, 'exclusive', '{}', '{}', ?, ?, 1)")
        ->execute([StoreContext::current()->id(), $r11oPromo, $r11oAgo(2 * 86400), $r11oAgo(60)]);
    Cache::readCatalogJson($r11oPath, 3600); // flushes pending catalog changes first
    // Catalog markers written by that flush predate the entry: only the sale's end may make it a miss.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r11oCacheDir, FilesystemIterator::SKIP_DOTS)) as $r11oEntry) touch($r11oEntry->getPathname(), time() - 3600);
    Cache::writeJson($r11oPath, ['price' => 799]);
    touch($r11oFile, time() - 600);
    clearstatcache(true, $r11oFile);
    assert_equals(null, Cache::readCatalogJson($r11oPath, 3600), 'R11 API cache: an entry written during a sale that has ended since is a miss');
    touch($r11oFile, time() + 5);
    clearstatcache(true, $r11oFile);
    assert_equals(['price' => 799], Cache::readCatalogJson($r11oPath, 3600), 'R11 API cache: an entry written after the sale ended is served');
} catch (Throwable $e) { $r11oFail('API cache', $e); } finally {
    $pdo->prepare('DELETE FROM promotions WHERE code = ?')->execute([$r11oPromo]);
    $r11oCacheProp->setValue(null, $r11oCacheBefore);
    $r11oRmTree($r11oCacheDir);
}

// A fresh SQLite schema: price-list repair, doctor and GDPR retention.
[$r11oSavedDb, $r11oSavedDriver] = [Db::get(), Db::driver()];
$r11oLock = sys_get_temp_dir() . '/bbe-r11o-' . getmypid() . '.lock';
$r11oDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
try {
    (new Migrator($r11oDb, TEST_CLI_DIR . '/../migrations', $r11oLock))->apply(static fn($m) => null);
    Db::init($r11oDb, 'sqlite');
    $r11oCount = static fn(string $sql, array $params = []): int => (int)(($s = $r11oDb->prepare($sql)) && $s->execute($params) ? $s->fetchColumn() : 0);

    // Price-list repair: a CZ scope a failed old run left without COD, most countries, some rates and the links.
    try {
        $r11oRepairFile = TEST_CLI_DIR . '/../migrations/20261101150000_price_list_repair.php';
        assert_true(is_file($r11oRepairFile), 'R11 price lists: a migration repairs a partially seeded scope by itself');
        $r11oFull = [
            'rates' => $r11oCount("SELECT COUNT(*) FROM shipping_rates WHERE store_id = 'cz'"),
            'countries' => $r11oCount("SELECT COUNT(*) FROM payment_method_countries WHERE store_id = 'cz'"),
        ];
        $r11oDb->exec("DELETE FROM payment_methods WHERE store_id = 'cz' AND code = 'cod'");
        $r11oDb->exec("DELETE FROM payment_method_countries WHERE store_id = 'cz' AND country NOT IN ('CZ', 'SK')");
        $r11oDb->exec("DELETE FROM shipping_rates WHERE store_id = 'cz' AND method_code = 'gls'");
        $r11oDb->exec("DELETE FROM payment_method_shipping WHERE store_id = 'cz'");
        // HU: the merchant runs it with an own courier and no COD — not a seed remainder.
        $r11oDb->exec("DELETE FROM payment_methods WHERE store_id = 'hu' AND code = 'cod'");
        $r11oDb->exec("DELETE FROM payment_method_shipping WHERE store_id = 'hu'");
        $r11oDb->exec("INSERT INTO shipping_methods (store_id, code, carrier, name, adapter_class, service_type, supports_cod, supports_pickup, sort_order)
            VALUES ('hu', 'own_courier', 'own', 'Saját futár', 'PersonalPickup', 'parcel', 0, 0, 90)");
        if (is_file($r11oRepairFile)) {
            (require $r11oRepairFile)->up($r11oDb, true);
            (require $r11oRepairFile)->up($r11oDb, true);
        }
        assert_equals(1, $r11oCount("SELECT COUNT(*) FROM payment_methods WHERE store_id = 'cz' AND code = 'cod' AND active = 1"), 'R11 price lists: the half-seeded CZ scope gets cash on delivery');
        assert_equals([$r11oFull['rates'], $r11oFull['countries'], 2], [
            $r11oCount("SELECT COUNT(*) FROM shipping_rates WHERE store_id = 'cz'"),
            $r11oCount("SELECT COUNT(*) FROM payment_method_countries WHERE store_id = 'cz'"),
            $r11oCount("SELECT COUNT(*) FROM payment_method_shipping WHERE store_id = 'cz'"),
        ], 'R11 price lists: its rates, payment countries and COD carriers are complete, also after a second run (no duplicates)');
        assert_equals([0, 0], [
            $r11oCount("SELECT COUNT(*) FROM payment_methods WHERE store_id = 'hu' AND code = 'cod'"),
            $r11oCount("SELECT COUNT(*) FROM payment_method_shipping WHERE store_id = 'hu'"),
        ], 'R11 price lists: a scope the merchant configured is not touched');
    } catch (Throwable $e) { $r11oFail('price-list repair', $e); }

    // Doctor: the payment methods of a removed store stay in the database but no buyer reaches them.
    try {
        $r11oDb->exec("INSERT INTO payment_methods (store_id, code, provider, name, requires_online, active) VALUES ('r11gone', 'card', 'r11-unknown', 'Card', 1, 1)");
        $r11oNames = static fn(?array $stores): array => array_column(PaymentReadiness::checks($r11oDb, [], $stores), 1);
        assert_true(in_array('payment.provider.r11-unknown', $r11oNames(null), true), 'R11 doctor: without the store list every active method is checked (control)');
        assert_true(!in_array('payment.provider.r11-unknown', $r11oNames(['default' => ['provider_scope' => 'default'], 'cz' => []]), true),
            'R11 doctor: a removed store\'s payment method reports no error');
    } catch (Throwable $e) { $r11oFail('doctor', $e); }

    // GDPR retention: shipped orders, complaints, withdrawals, RMA texts and pricing runs.
    try {
        $r11oOrder = $r11oDb->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, store_id, ip_address, notes, created_at)
            VALUES (?, 'Jana R11O', 'jana@r11o.test', '+421900111222', '{}', 1000, 0, 0, 0, 1000, 'eur', ?, 'paid', 'card', 'default', '203.0.113.11', '', ?)");
        $r11oOrder->execute(['R11O-SHIPPED', 'shipped', $r11oAgo(200 * 86400)]);
        $r11oShipped = (int)$r11oDb->lastInsertId();
        $r11oOrder->execute(['R11O-OPEN', 'processing', $r11oAgo(200 * 86400)]);
        $r11oOpen = (int)$r11oDb->lastInsertId();
        $r11oComplaint = $r11oDb->prepare("INSERT INTO complaints (store_id, complaint_number, order_id, order_item_id, order_number, customer_name, customer_email, item_name, defect, remedy, status, received_at, decision_note)
            VALUES ('default', ?, ?, 1, 'R11O-SHIPPED', 'Jana R11O', 'jana@r11o.test', 'Item', 'Broken by Jana', 'repair', ?, ?, 'Decided for Jana')");
        foreach ([['R11O-C-OLD', 'resolved', 6 * 365], ['R11O-C-OPEN', 'received', 6 * 365], ['R11O-C-NEW', 'resolved', 365]] as [$number, $status, $days]) {
            $r11oComplaint->execute([$number, $r11oShipped, $status, $r11oAgo($days * 86400)]);
        }
        $r11oWithdrawal = $r11oDb->prepare("INSERT INTO withdrawal_requests (store_id, order_id, order_number, customer_name, customer_email, items, reason, received_at)
            VALUES ('default', ?, ?, 'Jana R11O', 'jana@r11o.test', 'Item', 'Changed my mind', ?)");
        $r11oWithdrawal->execute([$r11oShipped, 'R11O-W-OLD', $r11oAgo(6 * 365 * 86400)]);
        $r11oWithdrawal->execute([$r11oShipped, 'R11O-W-NEW', $r11oAgo(365 * 86400)]);
        $r11oRma = $r11oDb->prepare("INSERT INTO rma_requests (rma_number, order_id, status, reason, admin_note, store_id, created_at) VALUES (?, ?, ?, 'Jana says', 'Called Jana', 'default', ?)");
        $r11oRma->execute(['R11O-R-OLD', $r11oShipped, 'refunded', $r11oAgo(6 * 365 * 86400)]);
        $r11oRmaOld = (int)$r11oDb->lastInsertId();
        $r11oRma->execute(['R11O-R-OPEN', $r11oShipped, 'requested', $r11oAgo(6 * 365 * 86400)]);
        $r11oDb->prepare("INSERT INTO return_receipts (store_id, rma_id, rma_item_id, quantity, disposition, note, idempotency_key, created_at) VALUES ('default', ?, 1, 1, 'restock', 'Jana packed it', 'r11o', ?)")
            ->execute([$r11oRmaOld, $r11oAgo(6 * 365 * 86400)]);
        $r11oRun = $r11oDb->prepare("INSERT INTO promotion_runs (store_id, promotion_id, code, scope, priority, stacking, conditions, actions, valid_from, valid_to)
            VALUES ('default', 1, ?, 'catalog', 100, 'exclusive', '{}', '{}', NULL, ?)");
        $r11oRun->execute(['R11O-P-OLD', $r11oAgo(2 * 365 * 86400)]);
        $r11oRun->execute(['R11O-P-NEW', $r11oAgo(30 * 86400)]);
        $r11oList = $r11oDb->prepare("INSERT INTO price_list_runs (store_id, price_list_id, currency, customer_group_id, product_id, price, valid_from, valid_to) VALUES ('default', 1, 'eur', NULL, ?, 500, NULL, ?)");
        $r11oList->execute([1, $r11oAgo(2 * 365 * 86400)]);
        $r11oList->execute([2, $r11oAgo(30 * 86400)]);

        $r11oPolicies = json_decode((string)file_get_contents(TEST_CLI_DIR . '/../config/data-retention.json'), true)['policies'];
        foreach ($r11oPolicies as $r11oKey => $r11oPolicy) GdprService::applyRetention((string)$r11oKey, (array)$r11oPolicy, 'apply');

        $r11oErased = static fn(?string $value): bool => str_starts_with((string)$value, '[erased');
        $r11oRow = static fn(string $sql, array $params): array => (($s = $r11oDb->prepare($sql)) && $s->execute($params) ? $s->fetch() : null) ?: [];
        $r11oShippedRow = $r11oRow('SELECT customer_phone, ip_address FROM orders WHERE id = ?', [$r11oShipped]);
        assert_true($r11oErased($r11oShippedRow['customer_phone'] ?? null) && $r11oErased($r11oShippedRow['ip_address'] ?? null),
            'R11 GDPR: a shipped order past 180 days loses phone and IP address like a delivered one');
        assert_equals('+421900111222', $r11oRow('SELECT customer_phone FROM orders WHERE id = ?', [$r11oOpen])['customer_phone'] ?? null, 'R11 GDPR: an unshipped order keeps the phone');
        $r11oC = static fn(string $number): array => $r11oRow('SELECT customer_name, customer_email, defect, decision_note, remedy FROM complaints WHERE complaint_number = ?', [$number]);
        $r11oCOld = $r11oC('R11O-C-OLD');
        assert_true($r11oErased($r11oCOld['customer_name'] ?? null) && $r11oErased($r11oCOld['customer_email'] ?? null) && $r11oErased($r11oCOld['defect'] ?? null)
            && $r11oErased($r11oCOld['decision_note'] ?? null) && ($r11oCOld['remedy'] ?? '') === 'repair', 'R11 GDPR: a decided complaint older than its retention loses the personal data, keeps the record');
        assert_equals(['jana@r11o.test', 'jana@r11o.test'], [$r11oC('R11O-C-OPEN')['customer_email'] ?? null, $r11oC('R11O-C-NEW')['customer_email'] ?? null],
            'R11 GDPR: an undecided or recent complaint is untouched');
        $r11oW = static fn(string $number): array => $r11oRow('SELECT customer_name, customer_email, reason, items FROM withdrawal_requests WHERE order_number = ?', [$number]);
        $r11oWOld = $r11oW('R11O-W-OLD');
        assert_true($r11oErased($r11oWOld['customer_name'] ?? null) && $r11oErased($r11oWOld['customer_email'] ?? null) && $r11oErased($r11oWOld['reason'] ?? null)
            && ($r11oWOld['items'] ?? '') === 'Item', 'R11 GDPR: an old withdrawal statement loses name, e-mail and reason, keeps what was withdrawn');
        assert_equals('jana@r11o.test', $r11oW('R11O-W-NEW')['customer_email'] ?? null, 'R11 GDPR: a recent withdrawal is untouched');
        $r11oR = static fn(string $number): array => $r11oRow('SELECT reason, admin_note FROM rma_requests WHERE rma_number = ?', [$number]);
        assert_true($r11oErased($r11oR('R11O-R-OLD')['reason'] ?? null) && $r11oErased($r11oR('R11O-R-OLD')['admin_note'] ?? null)
            && $r11oErased($r11oRow('SELECT note FROM return_receipts WHERE rma_id = ?', [$r11oRmaOld])['note'] ?? null),
            'R11 GDPR: an old closed RMA loses its free texts and receipt note');
        assert_equals('Jana says', $r11oR('R11O-R-OPEN')['reason'] ?? null, 'R11 GDPR: an open RMA is untouched');
        assert_equals([['R11O-P-NEW'], [2]], [
            array_column($r11oDb->query("SELECT code FROM promotion_runs WHERE code LIKE 'R11O-%'")->fetchAll(), 'code'),
            array_map('intval', array_column($r11oDb->query('SELECT product_id FROM price_list_runs')->fetchAll(), 'product_id')),
        ], 'R11 GDPR: promotion and price-list runs no prior price reads any more are deleted, recent ones stay');
    } catch (Throwable $e) { $r11oFail('GDPR retention', $e); }
} catch (Throwable $e) { $r11oFail('fresh schema', $e); } finally {
    Db::init($r11oSavedDb, $r11oSavedDriver);
    @unlink($r11oLock);
}

// setAvailable on MySQL: a stock change another connection committed while the transaction ran is not overwritten.
if (!getenv('TEST_MYSQL_DSN')) {
    print "  [SKIP] R11 stock: setAvailable with a concurrent change (set TEST_MYSQL_DSN)\n";
} else {
    $r11oTarget = 'bbe_r11o_' . bin2hex(random_bytes(3)) . '_test';
    $r11oConnect = static function (): PDO {
        $db = new PDO((string)getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $db;
    };
    [$r11oSavedDb, $r11oSavedDriver] = [Db::get(), Db::driver()];
    $r11oA = $r11oConnect();
    try {
        $r11oA->exec("CREATE DATABASE `{$r11oTarget}`");
        $r11oA->exec("USE `{$r11oTarget}`");
        Db::init($r11oA, 'mysql');
        (new Migrator($r11oA, TEST_CLI_DIR . '/../migrations', $r11oLock))->apply(static fn($m) => null);
        $r11oB = $r11oConnect();
        $r11oB->exec("USE `{$r11oTarget}`");
        $r11oStore = StoreContext::current()->catalogId();
        $r11oA->prepare("INSERT INTO categories (store_id, name, slug) VALUES (?, 'R11O', 'r11o')")->execute([$r11oStore]);
        $r11oA->prepare("INSERT INTO products (store_id, category_id, name, slug, sku, product_type, price, currency, status, stock) VALUES (?, ?, 'R11O', 'r11o', 'R11O-SKU', 'simple', 1000, 'eur', 'active', 10)")
            ->execute([$r11oStore, (int)$r11oA->lastInsertId()]);
        $r11oWh = (int)(Warehouse::defaultWarehouse()['id'] ?? 0);
        if ($r11oWh === 0) {
            $r11oA->prepare("INSERT INTO warehouses (store_id, code, name, priority, is_default) VALUES (?, 'r11o-wh', 'R11O', 1, 1)")->execute([$r11oStore]);
            $r11oWh = (int)$r11oA->lastInsertId();
        }
        $r11oA->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES (?, ?, 'R11O-SKU', 10, 0)")->execute([$r11oStore, $r11oWh]);

        // The admin's save runs in a transaction that has already read (snapshot: 10); an import adds 2 and commits.
        $r11oA->beginTransaction();
        $r11oA->query("SELECT SUM(qty) FROM warehouse_stock WHERE sku = 'R11O-SKU'")->fetchColumn();
        $r11oB->exec("UPDATE warehouse_stock SET qty = qty + 2 WHERE sku = 'R11O-SKU'");
        Warehouse::setAvailable('R11O-SKU', 15);
        $r11oA->commit();
        assert_equals(15, (int)$r11oB->query("SELECT SUM(qty - reserved) FROM warehouse_stock WHERE sku = 'R11O-SKU'")->fetchColumn(),
            'R11 stock: setting 15 while an import committed +2 meanwhile ends at 15, not 17');
    } catch (Throwable $e) {
        if ($r11oA->inTransaction()) $r11oA->rollBack();
        $r11oFail('stock setAvailable', $e);
    } finally {
        Db::init($r11oSavedDb, $r11oSavedDriver);
        $r11oA->exec("DROP DATABASE IF EXISTS `{$r11oTarget}`");
        @unlink($r11oLock);
    }
}
