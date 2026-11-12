<?php

// Review round 33 (high): since R31 the invoice's customer_snapshot names the billing party, and the OSS report took
// its consumption country from there. Goods delivered to Germany and billed to a Slovak address charged German VAT
// (19 %) but were reported as "SK 19 %" — the seller's own state, which never belongs in an OSS return — and Germany's
// base was understated; credit notes the same. The report now takes the country whose rate the order charged.
echo "\n--- Audit round 33: OSS report by the country of supply, not the billing country ---\n";

Bootstrap::initStoreContext('/', '', 'default');
if (!function_exists('collect')) {
    define('BB_TEST_LOAD_OSS_ONLY', true);
    require_once TEST_CLI_DIR . '/oss-report.php';
}
VatService::reset();
$r33oFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R33 OSS {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r33oReport = static function (): array {
    $rows = [];
    $day = ShopTime::date('Y-m-d');
    foreach (collect($day, $day, null) as $row) $rows[$row['consumption_country'] . '|' . $row['vat_rate']] = [$row['net'], $row['vat']];
    return $rows;
};
$r33oDelta = static function (array $before, array $after): array {
    $delta = [];
    foreach ($after + $before as $key => $_) {
        $change = [($after[$key][0] ?? 0) - ($before[$key][0] ?? 0), ($after[$key][1] ?? 0) - ($before[$key][1] ?? 0)];
        if ($change !== [0, 0]) $delta[$key] = $change;
    }
    ksort($delta);
    return $delta;
};

// A consumer's parcel to Germany under OSS, billed to a Slovak address.
$r33oContext = ['seller_country' => 'SK', 'customer_country' => 'DE', 'seller_oss_enabled' => true, 'tax_provider' => 'vat'];
$r33oSummary = ['items' => [['product_id' => 1, 'variant_id' => null, 'sku' => 'R33O-GOODS', 'price' => 11900, 'qty' => 1, 'total' => 11900, 'available' => true,
    'currency_mismatch' => false, 'product_type' => 'simple', 'vat_class' => 'standard', 'currency' => 'eur', 'price_formatted' => '', 'total_formatted' => '']],
    'subtotal' => 11900, 'currency' => 'eur'];
try {
    $r33oItems = Cart::applyVat($r33oSummary, $r33oContext)['items'];
    $r33oSnapshot = VatService::buildSnapshot($r33oItems, $r33oContext, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $r33oGoods = array_values($r33oSnapshot['breakdown'])[0] ?? ['net' => 0, 'vat' => 0];
    assert_equals(['oss', 'DE', 1900], [$r33oSnapshot['scenario'], $r33oSnapshot['rate_country'], (int)$r33oSnapshot['components'][0]['rate_basis_points']],
        'R33 OSS: the order delivered to DE charges the German 19 % under OSS');

    $r33oInsert = static function (string $number, ?string $snapshot) use ($pdo, $r33oSnapshot): int {
        $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, billing_addr, subtotal, shipping_cost, tax_amount,
                discount_amount, payment_fee, total, currency, status, payment_status, payment_method, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
            VALUES (?, 'R33O Empfänger', 'r33o@example.test', '', ?, ?, 11900, 0, 0, 0, 0, ?, 'eur', 'delivered', 'paid', 'cod', 'dpd', 'oss', ?, 'default', " . Db::now() . ")")
            ->execute([$number, json_encode(['street' => 'Lieferstraße 1', 'city' => 'Berlin', 'zip' => '10115', 'country' => 'DE']),
                json_encode(['name' => 'R33O Plátca', 'street' => 'Fakturačná 9', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK']),
                (int)$r33oSnapshot['totals']['gross'], $snapshot]);
        return (int)$pdo->lastInsertId();
    };

    $r33oBefore = $r33oReport();
    $r33oOrder = $r33oInsert('R33O-1', json_encode($r33oSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $r33oInvoice = InvoiceService::issue($r33oOrder);
    $r33oCustomer = json_decode((string)$r33oInvoice['customer_snapshot'], true) ?: [];
    assert_equals(['SK', 'DE'], [$r33oCustomer['country'] ?? null, $r33oCustomer['delivery']['country'] ?? null],
        'R33 OSS: the invoice is issued to the Slovak billing party and names the German delivery address (R31)');
    assert_equals(['DE|19' => [(int)$r33oGoods['net'], (int)$r33oGoods['vat']]], $r33oDelta($r33oBefore, $r33oReport()),
        'R33 OSS: the report counts the sale under DE 19 % where its VAT is owed (was "SK 19 %", the seller\'s own state)');

    $r33oCredit = InvoiceService::creditRefund($r33oOrder, 5950, 'r33o-refund', 'R33 OSS partial refund');
    $r33oCreditRates = array_values(json_decode((string)($r33oCredit['vat_breakdown'] ?? '{}'), true) ?: [])[0] ?? ['net' => 0, 'vat' => 0];
    assert_equals(['DE|19' => [(int)$r33oGoods['net'] - abs((int)$r33oCreditRates['net']), (int)$r33oGoods['vat'] - abs((int)$r33oCreditRates['vat'])]],
        $r33oDelta($r33oBefore, $r33oReport()), 'R33 OSS: the credit note reduces Germany\'s base and VAT, not a Slovak row');
    assert_true(!array_key_exists('SK|19', $r33oReport()) && !array_key_exists('SK|23', $r33oReport()), 'R33 OSS: the seller\'s own state never appears in the report');

    // An invoice without a financial snapshot (historical) and a billing address: its delivery country decides.
    $r33oLegacyBefore = $r33oReport();
    $r33oLegacy = InvoiceService::issue($r33oInsert('R33O-2', null));
    $r33oLegacyRates = json_decode((string)$r33oLegacy['vat_breakdown'], true) ?: [];
    $r33oExpected = [];
    foreach ($r33oLegacyRates as $r33oKey => $r33oGroup) $r33oExpected['DE|' . (float)$r33oKey] = [(int)$r33oGroup['net'], (int)$r33oGroup['vat']];
    assert_equals($r33oExpected, $r33oDelta($r33oLegacyBefore, $r33oReport()),
        'R33 OSS: without a financial snapshot the delivery country of the invoice is the consumption country (' . json_encode($r33oLegacyRates) . ')');
} catch (Throwable $e) { $r33oFail('invoice, credit note and report', $e); }

// The country picked for a document, alone: rate country, else the delivery address, else the customer's.
assert_equals(['AT', 'DE', 'CZ', ''], [
    ossCountry(['financial_snapshot' => json_encode(['rate_country' => 'at']), 'customer_snapshot' => json_encode(['country' => 'SK', 'delivery' => ['country' => 'DE']])]),
    ossCountry(['financial_snapshot' => '', 'customer_snapshot' => json_encode(['country' => 'SK', 'delivery' => ['country' => 'de']])]),
    ossCountry(['financial_snapshot' => null, 'customer_snapshot' => json_encode(['country' => 'cz'])]),
    ossCountry(['financial_snapshot' => '{bad', 'customer_snapshot' => 'null']),
], 'R33 OSS: ossCountry() prefers the charged rate country, then the delivery address, then the customer country');
