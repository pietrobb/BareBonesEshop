<?php

// Review round 36 (medium 2 + lows, goal #454): the OSS threshold counted only invoices taxed at the seller's VAT
// (scenario domestic_subthreshold). Sales invoiced under OSS (last year past the threshold, or this year after crossing
// it) counted nothing, so the checkout charged Austrian consumers Slovak VAT again; one credit note brought a shop back
// under it. E-books were counted by the invoice's scenario, not by where the consumer lives; unpaid orders, the
// supply that crosses the threshold and concurrent orders at it were not handled; the doctor named the store by ID.
echo "\n--- Audit round 36: OSS threshold counts every distance sale (OSS invoices, credit notes, e-books, open orders) ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$r36oStores = Bootstrap::stores();
$r36oStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r36oOff = $r36oStores;
$r36oOff['stores']['default']['oss_enabled'] = false;
$r36oOff['stores']['default']['legal']['country'] = 'SK';
$r36oOff['stores']['default']['name'] = 'R36O Knihy';
$r36oStoresProp->setValue(null, $r36oOff);
Bootstrap::initStoreContext('/', '', 'default');
$r36oCart = $_SESSION['cart'] ?? null;
try {
    $year = (int)ShopTime::date('Y');
    $since = sprintf('%04d-01-01', $year - 1);
    // Nothing of other tests counts this year or the last.
    $r36oStashedInvoices = $pdo->query("SELECT id, issued_at FROM invoices WHERE issued_at >= '{$since}'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $pdo->exec("UPDATE invoices SET issued_at = '2001-01-01 00:00:00' WHERE issued_at >= '{$since}'");
    $r36oStashedOrders = $pdo->query("SELECT id, created_at FROM orders WHERE created_at >= '{$since}'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $pdo->exec("UPDATE orders SET created_at = '2001-01-01 00:00:00' WHERE created_at >= '{$since}'");
    (require TEST_CLI_DIR . '/../migrations/20261113180000_invoice_oss_net.php')->up($pdo, Db::isSqlite());
    Db::forgetSchema($pdo);
    assert_true(Db::hasColumn('invoices', 'oss_net', $pdo), 'R36 OSS: the migration adds invoices.oss_net');
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99391, 'default', 1, 'R36O lamp', 'r36o-lamp', 'R36O-LAMP', 'simple', 12300, 'eur', 'active', 10, 'continue')");

    $r36oOrder = static function (string $number, string $scenario, array $shipping, ?array $billing, int $net, string $status = 'delivered', ?int $digitalNet = null) use ($pdo, $year): array {
        $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, billing_addr, subtotal, shipping_cost, tax_amount, discount_amount,
                total, net_total, currency, status, payment_status, payment_method, shipping_method, store_id, vat_scenario, created_at)
            VALUES (?, 'R36O', 'r36o@example.test', '', ?, ?, ?, 0, 0, 0, ?, ?, 'eur', ?, ?, 'bank_transfer', 'gls', 'default', ?, ?)")
            ->execute([$number, json_encode($shipping), $billing === null ? null : json_encode($billing), $net, $net, $net, $status, $status === 'pending' ? 'pending' : 'paid', $scenario,
                sprintf('%04d-03-10 10:00:00', $year)]);
        $orderId = (int)$pdo->lastInsertId();
        $items = [];
        $physical = $net - (int)$digitalNet;
        if ($physical > 0) {
            $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, net_amount, digital) VALUES (?, 99391, 'R36O-LAMP', 'R36O lamp', 1, ?, ?, ?, 0)")
                ->execute([$orderId, $physical, $physical, $physical]);
            $items['goods'] = (int)$pdo->lastInsertId();
        }
        if ($digitalNet !== null) {
            $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, net_amount, digital) VALUES (?, 99391, 'R36O-EBOOK', 'R36O e-book', 1, ?, ?, ?, 1)")
                ->execute([$orderId, $digitalNet, $digitalNet, $digitalNet]);
            $items['ebook'] = (int)$pdo->lastInsertId();
        }
        return ['id' => $orderId, 'items' => $items];
    };
    $r36oDoc = static function (array $order, string $number, int $net, string $scenario, int $docYear, string $type = 'invoice', ?int $refersTo = null, ?array $credit = null) use ($pdo): int {
        $pdo->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type, refers_to_invoice_id, credit_snapshot)
            VALUES (?, 'default', ?, ?, '', ?, 0, ?, 'eur', 'issued', ?, ?, ?, ?, ?)")
            ->execute([$order['id'], $number, sprintf('%04d-03-15 10:00:00', $docYear), $net, $net, 'r36o-' . $number, $scenario, $type, $refersTo, $credit === null ? null : json_encode($credit)]);
        return (int)$pdo->lastInsertId();
    };
    $r36oClean = static function () use ($pdo): void {
        $pdo->exec("DELETE FROM invoices WHERE number LIKE 'R36O-%'");
        $pdo->exec("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'R36O-%')");
        $pdo->exec("DELETE FROM orders WHERE order_number LIKE 'R36O-%'");
    };
    $r36oStore = $r36oOff['stores']['default'];
    $r36oSum = static fn(): array => OssThreshold::worstYear($pdo, 'default', $r36oStore, ['default' => $r36oStore]);
    $r36oCheck = static fn(): array => Setup::ossThresholdCheck($pdo, 'default', $r36oStore, ['default' => $r36oStore]);
    $r36oAt = ['country' => 'AT'];
    $_SESSION['cart'] = ['p_99391' => ['product_id' => 99391, 'variant_id' => null, 'qty' => 1]];
    $r36oAustria = static function (): array {
        VatService::reset();
        $vat = CheckoutService::vatContext('AT', '', 'gls', false);
        return [(string)$vat['scenario']['scenario'], (string)$vat['scenario']['rate_country']];
    };

    // 1. Last year 12 000 EUR to Austrian consumers under OSS, OSS switched off since: this year Austrian VAT still applies.
    $r36oLast = $r36oOrder('R36O-L1', 'oss', $r36oAt, null, 1200000);
    $r36oDoc($r36oLast, 'R36O-L1', 1200000, 'oss', $year - 1);
    assert_equals(['sum' => 1200000, 'year' => $year - 1], array_intersect_key($r36oSum(), ['sum' => 1, 'year' => 1]),
        'R36 OSS: invoices charged the consumer\'s VAT under OSS count toward the threshold (was 0)');
    assert_equals([VatService::SCENARIO_OSS, 'AT'], $r36oAustria(), 'R36 OSS: past the threshold last year, an Austrian consumer pays Austrian VAT the whole year (art. 59c; was Slovak VAT)');
    $r36oWarn = $r36oCheck();
    assert_true($r36oWarn['status'] === 'warn' && str_contains($r36oWarn['message'], '"R36O Knihy"') && !str_contains($r36oWarn['message'], 'Store default'),
        'R36 OSS: the doctor warns past the threshold and names the store by its name, not its ID');
    $r36oClean();

    // 2. This year: 9 000 EUR at home VAT, then 2 000 EUR under OSS once past it, then a 500 EUR refund credited by amount.
    $r36oA = $r36oOrder('R36O-A', 'domestic_subthreshold', $r36oAt, null, 900000);
    $r36oAInvoice = $r36oDoc($r36oA, 'R36O-A', 900000, 'domestic_subthreshold', $year);
    $r36oB = $r36oOrder('R36O-B', 'oss', $r36oAt, null, 200000);
    $r36oDoc($r36oB, 'R36O-B', 200000, 'oss', $year);
    $r36oDoc($r36oA, 'R36O-A-CN', -50000, 'domestic_subthreshold', $year, 'credit_note', $r36oAInvoice, ['version' => 1, 'kind' => 'refund', 'totals' => ['net' => 50000]]);
    assert_equals(1050000, $r36oSum()['sum'], 'R36 OSS: OSS invoices count after crossing, and a credit note does not bring the shop back under the threshold (was 8 500 EUR)');
    assert_equals([VatService::SCENARIO_OSS, 'AT'], $r36oAustria(), 'R36 OSS: after one credit note during the year the consumer still pays Austrian VAT');
    $r36oClean();

    // 3. E-books count where the consumer lives, not by the invoice's scenario.
    $r36oC = $r36oOrder('R36O-C', 'domestic_subthreshold', $r36oAt, ['name' => 'R36O', 'country' => 'SK'], 500000, 'delivered', 100000); // goods to AT, e-book billed in SK
    $r36oDoc($r36oC, 'R36O-C', 500000, 'domestic_subthreshold', $year);
    $r36oD = $r36oOrder('R36O-D', 'export', ['country' => 'US'], ['name' => 'R36O', 'country' => 'AT'], 300000, 'delivered', 70000); // goods to the USA, e-book to an Austrian consumer
    $r36oDInvoice = $r36oDoc($r36oD, 'R36O-D', 300000, 'export', $year);
    assert_equals(400000 + 70000, $r36oSum()['sum'], 'R36 OSS: an e-book of a consumer at home is not counted with goods sent abroad, one billed to Austria on an export invoice is');
    // ... and an item credit note of that e-book takes it out again.
    $r36oDoc($r36oD, 'R36O-D-CN', -70000, 'export', $year, 'credit_note', $r36oDInvoice,
        ['version' => 1, 'lines' => [['order_item_id' => $r36oD['items']['ebook'], 'net' => 70000, 'gross' => 70000]], 'totals' => ['net' => 70000]]);
    assert_equals(400000, $r36oSum()['sum'], 'R36 OSS: an item credit note of the counted e-book takes it out');
    // The stored net (written when InvoiceService issues a document) equals the count from the lines.
    foreach ($pdo->query("SELECT id FROM invoices WHERE number LIKE 'R36O-%'")->fetchAll(PDO::FETCH_COLUMN) as $id) OssThreshold::record($pdo, (int)$id);
    assert_equals([0, 400000], [(int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE number LIKE 'R36O-%' AND oss_net IS NULL")->fetchColumn(), $r36oSum()['sum']],
        'R36 OSS: the stored oss_net of every document sums to the same threshold count');
    $r36oClean();

    // 4. An order placed and not paid yet counts; a cancelled one does not.
    $r36oDoc($r36oOrder('R36O-E', 'domestic_subthreshold', $r36oAt, null, 950000), 'R36O-E', 950000, 'domestic_subthreshold', $year);
    $r36oOrder('R36O-F', 'domestic_subthreshold', $r36oAt, null, 30000, 'pending');
    $r36oOrder('R36O-G', 'domestic_subthreshold', $r36oAt, null, 400000, 'cancelled');
    assert_equals(980000, $r36oSum()['sum'], 'R36 OSS: an unpaid order not invoiced yet counts, a cancelled one does not');

    // 5. The sale that passes the threshold is the first one charged the consumer's VAT (art. 59c): 9 800 + 100 net
    // stays at home VAT, the lamp of 123 EUR (100 EUR net at 23 %) on top of 9 950 passes it.
    VatService::reset();
    assert_equals([VatService::SCENARIO_DOMESTIC_SUBTHRESH, 'SK'], $r36oAustria(), 'R36 OSS: a sale that keeps the year under 10 000 EUR is charged the seller\'s VAT');
    $r36oOrder('R36O-H', 'domestic_subthreshold', $r36oAt, null, 15000, 'pending');
    assert_equals([VatService::SCENARIO_OSS, 'AT'], $r36oAustria(), 'R36 OSS: the sale that passes 10 000 EUR is already charged Austrian VAT (was Slovak VAT)');
    assert_equals([VatService::SCENARIO_DOMESTIC, 'SK'], array_values(array_intersect_key(CheckoutService::vatContext('SK', '', 'gls', false)['scenario'], ['scenario' => 1, 'rate_country' => 1])),
        'R36 OSS: a Slovak consumer still pays Slovak VAT');

    // 6. Placing the order counts again under the seller's lock: an order priced at home VAT while another order passed
    // the threshold meanwhile is refused (the buyer confirms the new total), one priced at the consumer's VAT goes through.
    $r36oSnapshot = static fn(string $scenario): array => ['scenario' => $scenario, 'currency' => 'eur',
        'components' => [['type' => 'product', 'sku' => 'R36O-LAMP', 'vat_class' => 'standard', 'gross_before_discount' => 12300, 'rate_basis_points' => 2300]]];
    $r36oCustomer = ['name' => 'R36O', 'address' => $r36oAt];
    $r36oRefused = false;
    try {
        OssThreshold::guardOrder($pdo, $r36oSnapshot(VatService::SCENARIO_DOMESTIC_SUBTHRESH), $r36oCustomer, [['product_type' => 'simple']]);
    } catch (VatChangedException $e) {
        $r36oRefused = true;
    }
    assert_true($r36oRefused, 'R36 OSS: an order priced at home VAT past the threshold is refused when it is placed (concurrent orders cannot all pass at home VAT)');
    $r36oLock = OssThreshold::guardOrder($pdo, $r36oSnapshot(VatService::SCENARIO_OSS), $r36oCustomer, [['product_type' => 'simple']]);
    OssThreshold::releaseOrderLock($pdo, $r36oLock);
    assert_true(true, 'R36 OSS: an order priced at the consumer\'s VAT past the threshold is placed');
    assert_equals(null, OssThreshold::guardOrder($pdo, $r36oSnapshot(VatService::SCENARIO_DOMESTIC), ['name' => 'R36O', 'address' => ['country' => 'SK']], [['product_type' => 'simple']]),
        'R36 OSS: a domestic order takes no threshold lock');
    $r36oSource = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php');
    assert_true(str_contains($r36oSource, "} catch (VatChangedException \$e) {") && str_contains($r36oSource, "checkoutRefusal('checkout.error.vat_changed')"),
        'R36 OSS: the checkout answers a refused order with the "VAT changed" message (not "you changed shipping")');
    foreach (glob(dirname(TEST_CLI_DIR) . '/lang/*.php') ?: [] as $r36oLang) {
        assert_true(isset((require $r36oLang)['checkout.error.vat_changed']), 'R36 OSS: ' . basename($r36oLang) . ' explains a VAT changed by the threshold');
    }
} catch (Throwable $e) {
    assert_true(false, 'R36 OSS (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
$pdo->exec("DELETE FROM invoices WHERE number LIKE 'R36O-%'");
$pdo->exec("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'R36O-%')");
$pdo->exec("DELETE FROM orders WHERE order_number LIKE 'R36O-%'");
$pdo->exec('DELETE FROM products WHERE id = 99391');
$restore = $pdo->prepare('UPDATE invoices SET issued_at = ? WHERE id = ?');
foreach ($r36oStashedInvoices ?? [] as $id => $issuedAt) $restore->execute([$issuedAt, $id]);
$restore = $pdo->prepare('UPDATE orders SET created_at = ? WHERE id = ?');
foreach ($r36oStashedOrders ?? [] as $id => $createdAt) $restore->execute([$createdAt, $id]);
$r36oStoresProp->setValue(null, $r36oStores);
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
if ($r36oCart === null) unset($_SESSION['cart']); else $_SESSION['cart'] = $r36oCart;
