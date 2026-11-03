<?php

// Review round 13 (N3 + low): an e-book of a consumer from another member state next to goods collected in the
// seller's store. Its foreign rate was summed into the invoice's rate groups ("10 %" on a Slovak invoice), left out
// of the OSS report (the invoice is domestic) and, as the highest rate, taxed the cash-on-delivery fee. A business
// buyer with a valid VAT ID of another member state paid Slovak VAT on the e-book instead of reverse charge.
echo "\n--- Audit round 13: e-book VAT next to collected goods (own group, invoice, OSS report, COD fee, reverse charge) ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r13vFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R13 VAT {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
if (!function_exists('collect')) {
    define('BB_TEST_LOAD_OSS_ONLY', true);
    require_once TEST_CLI_DIR . '/oss-report.php';
}
VatService::reset();
$r13vPickup = ['seller_country' => 'SK', 'customer_country' => 'SK', 'seller_oss_enabled' => true, 'digital_customer_country' => 'AT', 'tax_provider' => 'vat'];
$r13vLine = static fn(string $sku, string $type, int $price): array => ['product_id' => 1, 'variant_id' => null, 'sku' => $sku, 'price' => $price, 'qty' => 1,
    'total' => $price, 'available' => true, 'currency_mismatch' => false, 'product_type' => $type, 'vat_class' => 'books', 'currency' => 'eur',
    'price_formatted' => '', 'total_formatted' => ''];
$r13vSummary = ['items' => [$r13vLine('R13V-EBOOK', 'digital', 1100), $r13vLine('R13V-PB', 'simple', 2100)], 'subtotal' => 3200, 'currency' => 'eur'];
$r13vReport = static function (): array {
    $rows = [];
    $day = ShopTime::date('Y-m-d');
    foreach (collect($day, $day, null) as $row) $rows[$row['consumption_country'] . '|' . $row['vat_rate']] = [$row['net'], $row['vat']];
    return $rows;
};
$r13vDelta = static function (array $before, array $after): array {
    $delta = [];
    foreach ($after + $before as $key => $_) {
        $change = [($after[$key][0] ?? 0) - ($before[$key][0] ?? 0), ($after[$key][1] ?? 0) - ($before[$key][1] ?? 0)];
        if ($change !== [0, 0]) $delta[$key] = $change;
    }
    ksort($delta);
    return $delta;
};

$r13vSnapshot = null;
try {
    $r13vItems = Cart::applyVat($r13vSummary, $r13vPickup)['items'];
    $r13vSnapshot = VatService::buildSnapshot($r13vItems, $r13vPickup, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d'), 'payment_fee' => 210]);
    $r13vGroups = array_map(static fn(array $g): array => [(int)$g['rate_basis_points'], $g['country'] ?? null, $g['supply'] ?? null], $r13vSnapshot['breakdown']);
    assert_equals(['5' => [500, null, null], '10 AT' => [1000, 'AT', 'oss']], $r13vGroups,
        'R13 VAT: the Austrian e-book is its own breakdown group naming its country (was summed into a bare "10" group)');
    $r13vFee = array_values(array_filter($r13vSnapshot['components'], static fn(array $c): bool => $c['type'] === 'payment_fee'))[0] ?? [];
    assert_equals(500, (int)($r13vFee['rate_basis_points'] ?? -1), 'R13 VAT: the COD fee follows the collected goods\' rate (5 %), not the e-book\'s Austrian 10 %');
    VatService::validateSnapshot($r13vSnapshot);
    assert_true(true, 'R13 VAT: the snapshot with a foreign-supply group passes its invariants');

    // A snapshot taken before (one group per rate) still validates; a tampered breakdown does not.
    $r13vBreakdownOf = new ReflectionMethod(VatService::class, 'breakdownOf');
    $r13vFingerprint = new ReflectionMethod(VatService::class, 'snapshotFingerprint');
    $r13vLegacy = $r13vSnapshot;
    unset($r13vLegacy['fingerprint']);
    $r13vLegacy['breakdown'] = $r13vBreakdownOf->invoke(null, $r13vLegacy['components'], false);
    $r13vLegacy['fingerprint'] = $r13vFingerprint->invoke(null, $r13vLegacy);
    VatService::validateSnapshot($r13vLegacy);
    assert_equals(['5', '10'], array_map('strval', array_keys($r13vLegacy['breakdown'])), 'R13 VAT: a snapshot summed by rate alone (round 12) still validates');
    $r13vBad = $r13vSnapshot;
    unset($r13vBad['fingerprint']);
    $r13vBad['breakdown']['10 AT']['country'] = 'DE';
    $r13vBad['fingerprint'] = $r13vFingerprint->invoke(null, $r13vBad);
    $r13vRefused = false;
    try { VatService::validateSnapshot($r13vBad); } catch (InvalidArgumentException) { $r13vRefused = true; }
    assert_true($r13vRefused, 'R13 VAT: a breakdown naming another country than its lines is refused');
} catch (Throwable $e) { $r13vFail('snapshot', $e); }

// The invoice of that order, the OSS report, and a credit note of the whole amount.
if ($r13vSnapshot !== null) {
    try {
        $r13vTotal = (int)$r13vSnapshot['totals']['gross'];
        $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
                payment_fee, total, currency, status, payment_status, payment_method, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
            VALUES ('R13V-1', 'R13V', 'r13v@example.test', '', '{\"country\":\"SK\"}', 3200, 0, 0, 0, 210, ?, 'eur', 'delivered', 'paid', 'cod', 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
            ->execute([$r13vTotal, json_encode($r13vSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $r13vOrder = (int)$pdo->lastInsertId();
        $r13vBefore = $r13vReport();
        $r13vInvoice = InvoiceService::issue($r13vOrder);
        $r13vRates = json_decode((string)$r13vInvoice['vat_breakdown'], true);
        assert_equals(['5', '10 AT'], array_map('strval', array_keys($r13vRates)), 'R13 VAT: the invoice recap keeps the e-book\'s rate apart, by country');
        assert_equals(['AT', 'oss'], [$r13vRates['10 AT']['country'] ?? null, $r13vRates['10 AT']['supply'] ?? null], 'R13 VAT: the invoice group names Austria and OSS');
        $r13vEbook = $r13vSnapshot['breakdown']['10 AT'] ?? ['net' => 1000, 'vat' => 100];
        assert_equals(['AT|10' => [(int)$r13vEbook['net'], (int)$r13vEbook['vat']]], $r13vDelta($r13vBefore, $r13vReport()),
            'R13 VAT: the OSS report counts the e-book of the domestic invoice under Austria, and nothing of its Slovak lines (was left out)');
        $r13vLabel = new ReflectionMethod(InvoiceService::class, 'rateLabel');
        assert_equals(['10% AT', '5%'], [$r13vLabel->invoke(null, 10.0, $r13vRates['10 AT']), $r13vLabel->invoke(null, 5.0, $r13vRates['5'])],
            'R13 VAT: the document prints "10% AT" for the e-book, "5%" for the Slovak lines');

        $r13vCredit = InvoiceService::creditRefund($r13vOrder, $r13vTotal, 'r13v-refund', 'R13 VAT');
        $r13vCreditRates = json_decode((string)($r13vCredit['vat_breakdown'] ?? '{}'), true) ?: [];
        assert_equals('AT', $r13vCreditRates['10 AT']['country'] ?? null, 'R13 VAT: the credit note keeps the e-book\'s country');
        assert_equals([], $r13vDelta($r13vBefore, $r13vReport()), 'R13 VAT: the credit note of the whole amount nets the e-book out of the OSS report again');
    } catch (Throwable $e) { $r13vFail('invoice and OSS report', $e); }
}

// A business buyer with a valid VAT ID of another member state: the e-book is reverse charged.
try {
    $r13vB2b = ['digital_customer_country' => 'DE', 'is_b2b' => true, 'customer_vat_id_valid' => true] + $r13vPickup;
    $r13vCart = Cart::applyVat($r13vSummary, $r13vB2b);
    $r13vNetEbook = (int)round(1100 / 1.05);
    assert_equals([[0.0, $r13vNetEbook], [5.0, 2100]], array_map(static fn(array $i): array => [(float)$i['vat_rate'], (int)$i['price']], $r13vCart['items']),
        'R13 VAT: a business\'s e-book is charged without the Slovak VAT at 0 %; the collected paperback keeps the Slovak 5 % (was SK VAT on both)');
    assert_equals(2100 + $r13vNetEbook, (int)$r13vCart['subtotal'], 'R13 VAT: the cart subtotal drops the Slovak VAT of the reverse-charged e-book');
    $r13vRc = VatService::buildSnapshot($r13vCart['items'], $r13vB2b, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d'), 'payment_fee' => 210]);
    assert_equals([500, ['0 DE RC', '5'], 'DE', 'reverse_charge'], [
        (int)(array_values(array_filter($r13vRc['components'], static fn(array $c): bool => $c['type'] === 'payment_fee'))[0]['rate_basis_points'] ?? -1),
        array_map('strval', array_keys($r13vRc['breakdown'])),
        $r13vRc['breakdown']['0 DE RC']['country'] ?? null,
        $r13vRc['breakdown']['0 DE RC']['supply'] ?? null,
    ], 'R13 VAT: the snapshot holds the e-book as a reverse-charged German group and the COD fee at the goods\' rate');
    assert_equals('vat.reverse_charge_services_note', $r13vRc['note_key'], 'R13 VAT: the invoice states the business accounts for the e-book\'s VAT (Art. 196)');
    assert_true(InvoiceService::vatNotice(['reverse_charge_note_key' => $r13vRc['note_key']], 'sk', false, 'SK') !== null,
        'R13 VAT: the reverse-charge note of the e-book has its wording on a Slovak invoice');
} catch (Throwable $e) { $r13vFail('reverse charge', $e); }
finally { VatService::reset(); }
