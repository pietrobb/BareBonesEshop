<?php

// R52 (idea 61): a 365-day sales report summed every order and order line of the year (3.8 s at 500 000 orders on
// MySQL). Days that have ended are read from the daily totals (SalesDaily); they must read the same as the orders do,
// also after a cancellation, a payment, a refund and a credit note of an old order, an imported old order, and a change
// of the shop's timezone.
echo "\n--- Audit round 52: sales reports read closed days from daily totals that match the orders ---\n";

$r52sDb = Db::get();
$r52sZone = date_default_timezone_get();
$r52sOrders = [];
$r52sRefunds = [];
$r52sStore = StoreContext::current()->id();
// The full suite's database has no migrations applied; the tables of this one are created here and dropped after.
$r52sCreated = !SalesDaily::available($r52sDb);
try {
    if ($r52sCreated) (require TEST_CLI_DIR . '/../migrations/20261121120000_sales_daily.php')->up($r52sDb, Db::isSqlite());
    $r52sCategory = (int)$r52sDb->query('SELECT MIN(id) FROM categories')->fetchColumn();
    $r52sProduct = $r52sDb->prepare("INSERT INTO products (id, category_id, name, slug, sku, price, store_id) VALUES (?, ?, ?, ?, ?, 1000, ?)");
    foreach ([952101 => 'R52S-A', 952102 => 'R52S-B', 952103 => 'R52S-C'] as $r52sId => $r52sSku) {
        $r52sProduct->execute([$r52sId, $r52sCategory, $r52sSku, strtolower($r52sSku), $r52sSku, $r52sStore]);
    }
    $r52sOrder = $r52sDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method,
        subtotal, total, currency, store_id, locale, is_synthetic, created_at) VALUES (?, ?, ?, 'R52S', 'r52s@example.test', '{}', 'card', ?, ?, 'XTS', ?, 'sk', ?, ?)");
    $r52sLine = $r52sDb->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?, ?, ?)');
    // Local wall-clock time $days ago at $minute past midnight, as UTC.
    $r52sAt = static fn(int $days, int $minute): string => (new DateTimeImmutable('today'))->modify("-{$days} days")->modify("+{$minute} minutes")
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $r52sAdd = static function (string $status, string $payment, int $synthetic, string $created, array $lines) use ($r52sDb, $r52sOrder, $r52sLine, $r52sStore, &$r52sOrders): int {
        $total = array_sum(array_column($lines, 2));
        $r52sOrder->execute(['R52S-' . bin2hex(random_bytes(5)), $status, $payment, $total, $total, $r52sStore, $synthetic, $created]);
        $id = (int)$r52sDb->lastInsertId();
        $r52sOrders[] = $id;
        foreach ($lines as [$product, $qty, $amount]) $r52sLine->execute([$id, $product, 'R52S-' . $product, 'R52S ' . $product, $qty, intdiv($amount, $qty), $amount]);
        return $id;
    };
    $r52sRefund = static function (int $orderId, int $amount) use ($r52sDb, $r52sStore): void {
        $r52sDb->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id) VALUES (?, 'stripe', 'refund_succeeded', ?, 'XTS', ?, ?)")
            ->execute([$orderId, $amount, 'r52s-' . bin2hex(random_bytes(5)), $r52sStore]);
    };
    // A year and a bit of orders: both ends of local days, every third day, paid and not, cancelled, smoke orders.
    $r52sByDay = [];
    for ($r52sDay = 0; $r52sDay <= 380; $r52sDay += 3) {
        $r52sProductId = 952101 + $r52sDay % 3;
        $r52sByDay[$r52sDay] = $r52sAdd('delivered', 'paid', 0, $r52sAt($r52sDay, 5), [[$r52sProductId, 2, 1000 + $r52sDay], [952103, 1, 300]]);
        $r52sAdd('paid', 'paid', 0, $r52sAt($r52sDay, 1435), [[952102, 1, 700]]);
        if ($r52sDay % 9 === 0) $r52sAdd('cancelled', 'paid', 0, $r52sAt($r52sDay, 600), [[952101, 1, 5000]]);
        if ($r52sDay % 12 === 0) $r52sAdd('pending', 'pending', 0, $r52sAt($r52sDay, 700), [[952102, 1, 4000]]);
        if ($r52sDay % 15 === 0) $r52sAdd('delivered', 'paid', 1, $r52sAt($r52sDay, 800), [[952103, 1, 9000]]);
    }
    $r52sRefund($r52sByDay[30], 600);
    $r52sAdd('paid', 'paid', 0, gmdate('Y-m-d H:i:s'), [[952101, 1, 111]]); // placed now: an open day

    $r52sCheck = static function (string $label) use ($r52sStore): void {
        $r52sFar = '9999-12-31 00:00:00';
        $r52sOk = true;
        $r52sDiff = [];
        foreach (SalesReport::PERIODS as $r52sPeriod) {
            $r52sSince = SalesReport::sinceUtc($r52sPeriod);
            $r52sRaw = SalesReport::revenueRows($r52sStore, $r52sSince, $r52sFar)['xts'] ?? ['orders' => 0, 'revenue' => 0];
            $r52sTotals = SalesReport::totals($r52sStore, $r52sSince)['xts'] ?? ['orders' => 0, 'revenue' => 0];
            if ([$r52sRaw['orders'], $r52sRaw['revenue']] !== [$r52sTotals['orders'], $r52sTotals['revenue']]) {
                $r52sOk = false;
                $r52sDiff[] = "totals {$r52sPeriod}: " . json_encode([$r52sRaw, $r52sTotals]);
            }
            $r52sTop = [];
            foreach (SalesReport::topProducts($r52sStore, $r52sSince, 50)['xts'] ?? [] as $r52sRow) $r52sTop[$r52sRow['product_id']] = [$r52sRow['quantity'], $r52sRow['revenue']];
            $r52sRawTop = [];
            foreach (SalesReport::productGroups($r52sStore, $r52sSince) as $r52sGroup) {
                if ($r52sGroup['currency'] === 'xts') $r52sRawTop[$r52sGroup['product_id']] = [$r52sGroup['quantity'], (int)round($r52sGroup['revenue'])];
            }
            ksort($r52sTop);
            ksort($r52sRawTop);
            if ($r52sTop !== $r52sRawTop) {
                $r52sOk = false;
                $r52sDiff[] = "top {$r52sPeriod}: " . json_encode([$r52sRawTop, $r52sTop]);
            }
        }
        $r52sTrend = SalesReport::trend($r52sStore, 365)['xts'] ?? [];
        $r52sDays = array_keys($r52sTrend);
        foreach ($r52sDays as $r52sI => $r52sDay) {
            $r52sUntil = $r52sI === count($r52sDays) - 1 ? $r52sFar : SalesDaily::dayStartUtc((string)$r52sDays[$r52sI + 1]);
            $r52sRaw = SalesReport::revenueRows($r52sStore, SalesDaily::dayStartUtc((string)$r52sDay), $r52sUntil)['xts'] ?? ['orders' => 0, 'revenue' => 0];
            if ([$r52sRaw['orders'], $r52sRaw['revenue']] !== [$r52sTrend[$r52sDay]['orders'], $r52sTrend[$r52sDay]['revenue']]) {
                $r52sOk = false;
                $r52sDiff[] = "trend {$r52sDay}: " . json_encode([$r52sRaw, $r52sTrend[$r52sDay]]);
            }
        }
        assert_true($r52sOk && count($r52sTrend) === 365, "R52 daily sales: {$label} - totals, best sellers and each day of the trend of 7/30/90/365 days read as the orders sum them"
            . ($r52sDiff === [] ? '' : ' (' . implode('; ', array_slice($r52sDiff, 0, 3)) . ')'));
    };

    $r52sCheck('built from the existing orders');
    assert_equals(SalesDaily::openDay(), SalesDaily::builtUntil($r52sStore), 'R52 daily sales: every day closed is built');
    $r52sDayBuilt = $r52sDb->prepare("SELECT COUNT(*) FROM sales_daily WHERE store_id = ? AND currency = 'xts'");
    $r52sDayBuilt->execute([$r52sStore]);
    assert_true((int)$r52sDayBuilt->fetchColumn() >= 120, 'R52 daily sales: the closed days of the year have their rows');
    // The report really reads the rows: one cent more in a built day shows in the 365-day total.
    $r52sBefore = SalesReport::totals($r52sStore, SalesReport::sinceUtc(365))['xts']['revenue'] ?? 0;
    $r52sDb->prepare("UPDATE sales_daily SET revenue = revenue + 1 WHERE store_id = ? AND currency = 'xts' AND day = ?")->execute([$r52sStore, SalesDaily::dayOf($r52sAt(60, 5))]);
    assert_equals($r52sBefore + 1, SalesReport::totals($r52sStore, SalesReport::sinceUtc(365))['xts']['revenue'] ?? 0, 'R52 daily sales: a closed day is read from its row, not summed from the orders');
    SalesDaily::refreshAll(true);
    $r52sCheck('rebuilt by cli/sales-daily.php --rebuild');

    // Changes of old orders by the shop's own code.
    assert_true(Orders::updateStatus($r52sByDay[90], 'cancelled'), 'R52 daily sales: an order of 90 days ago is cancelled');
    $r52sPending = $r52sAdd('pending', 'pending', 0, $r52sAt(45, 100), [[952101, 1, 2500]]);
    SalesReport::totals($r52sStore, SalesReport::sinceUtc(7)); // its day is built while it is unpaid
    PaymentService::updatePaymentStatus($r52sPending, 'paid');
    $r52sCheck('after an old order is cancelled and another one paid (Orders::updateStatus(), PaymentService::updatePaymentStatus())');

    // Rows written without the shop's code: a refund, a credit note of an old order, an imported order of last year.
    $r52sRefund($r52sByDay[150], 900);
    $r52sItem = (int)$r52sDb->query('SELECT MIN(id) FROM order_items WHERE order_id = ' . (int)$r52sByDay[210])->fetchColumn();
    $r52sDb->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key) VALUES (?, ?, 0, 0, 500, 'XTS', 'succeeded', ?)")
        ->execute([$r52sStore, $r52sByDay[210], 'r52s-cn-' . bin2hex(random_bytes(4))]);
    $r52sRefunds[] = (int)$r52sDb->lastInsertId();
    $r52sDb->prepare("INSERT INTO credit_note_items (credit_note_id, refund_id, order_item_id, rma_item_id, sku, name, quantity, unit_gross, gross, net, vat, rate_basis_points)
        VALUES (0, ?, ?, 0, 'R52S', 'R52S', 1, 500, 500, 500, 0, 0)")->execute([end($r52sRefunds), $r52sItem]);
    $r52sRefund($r52sByDay[210], 500);
    $r52sAdd('delivered', 'paid', 0, $r52sAt(300, 720), [[952103, 3, 3300]]);
    $r52sCheck('after a refund, a credit note and an imported order of old days written by SQL');

    // Another wall clock: the days are those of the new timezone.
    date_default_timezone_set($r52sZone === 'Pacific/Kiritimati' ? 'America/Adak' : 'Pacific/Kiritimati');
    $r52sCheck('after the shop timezone changes by 24 hours');
} catch (Throwable $e) {
    assert_true(false, 'R52 daily sales (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    date_default_timezone_set($r52sZone);
    if ($r52sRefunds !== []) {
        $r52sDb->exec('DELETE FROM credit_note_items WHERE refund_id IN (' . implode(',', $r52sRefunds) . ')');
        $r52sDb->exec('DELETE FROM order_refunds WHERE id IN (' . implode(',', $r52sRefunds) . ')');
    }
    if ($r52sOrders !== []) {
        $r52sIds = implode(',', $r52sOrders);
        foreach (['payment_transactions', 'order_items'] as $r52sTable) $r52sDb->exec("DELETE FROM {$r52sTable} WHERE order_id IN ({$r52sIds})");
        $r52sDb->exec("DELETE FROM orders WHERE id IN ({$r52sIds})");
    }
    $r52sDb->exec('DELETE FROM products WHERE id IN (952101, 952102, 952103)');
    if ($r52sCreated) {
        foreach (['sales_daily', 'sales_daily_products', 'sales_daily_changes', 'sales_daily_state'] as $r52sTable) $r52sDb->exec("DROP TABLE IF EXISTS {$r52sTable}");
        Db::forgetSchema($r52sDb);
    } else {
        SalesDaily::refreshAll(true);
    }
}
