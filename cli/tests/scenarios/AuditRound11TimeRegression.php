<?php

// Round 11 review (2026-11-01) H5 "time": the shop's calendar is its store's time zone, not php.ini or the container.
// Invoice/credit dates and number years, the VAT rate in force, consumer deadlines (withdrawal, returns), the bank
// transfer due date with its reminder and grace days, and fixed-time feed schedules across the DST changes.
echo "\n--- Audit round 11 (time): store time zone, invoice date/year, VAT midnight, deadlines, transfer due date, DST feed sync ---\n";
$r11tFail = static fn(string $what, Throwable $e) => assert_true(false, "R11 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
$r11tStore = StoreContext::current()->id();
$r11tAt = static fn(string $local): int => (new DateTimeImmutable($local, new DateTimeZone('Europe/Bratislava')))->getTimestamp();
$r11tLocal = static fn(int $ts): string => (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Bratislava'))->format('Y-m-d H:i:s');

// The store's zone: configured, else the seller country's, else Europe/Bratislava; doctor names each case.
try {
    assert_equals(['America/New_York', 'Europe/Prague', 'Europe/Bratislava', 'Europe/Bratislava'], [
        StoreContext::timezoneFor(['timezone' => 'America/New_York', 'legal' => ['country' => 'SK']]),
        StoreContext::timezoneFor(['legal' => ['country' => 'CZ']]),
        StoreContext::timezoneFor([]),
        StoreContext::timezoneFor(['timezone' => 'Mars/Olympus', 'legal' => ['country' => 'SK']]),
    ], 'R11 H5: a store runs on its timezone, else its seller country\'s, else Europe/Bratislava');
    assert_equals(['fail', 'warn', 'pass'], [
        Setup::storeTimezoneCheck('x', ['timezone' => 'Mars/Olympus'])['status'],
        Setup::storeTimezoneCheck('x', ['legal' => ['country' => 'CZ']])['status'],
        Setup::storeTimezoneCheck('x', ['timezone' => 'Europe/Prague'])['status'],
    ], 'R11 H5: doctor fails an unknown zone, warns about a missing one and passes a valid one');
    assert_true(str_contains(Setup::storeTimezoneCheck('x', ['legal' => ['country' => 'CZ']])['message'], 'Europe/Prague'), 'R11 H5: doctor says which zone a store without one runs on');
    $r11tShipped = json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/stores.example.json'), true);
    $r11tUnset = [];
    foreach (($r11tShipped['stores'] ?? []) as $r11tId => $r11tCfg) if (StoreContext::configuredTimezone($r11tCfg) === null) $r11tUnset[] = $r11tId;
    assert_equals([], $r11tUnset, 'R11 H5: every shipped store sets a valid timezone');
    StoreContext::activate(StoreContext::current());
    assert_equals(StoreContext::current()->timezone(), date_default_timezone_get(), 'R11 H5: activating a store makes its zone PHP\'s default');
} catch (Throwable $e) { $r11tFail('H5 zone', $e); }
assert_equals('Europe/Bratislava', StoreContext::current()->timezone(), 'R11 H5: the test store runs on Europe/Bratislava (precondition)');

// VAT: a rate change takes effect at local midnight (SK 20 % → 23 % on 1 January 2025), not at 01:00 local on a UTC server.
try {
    ShopTime::setNowForTesting(gmmktime(23, 30, 0, 12, 31, 2024)); // 00:30 on 1 January in Bratislava
    $r11tAfter = VatService::rate('SK');
    ShopTime::setNowForTesting(gmmktime(22, 30, 0, 12, 31, 2024)); // 23:30 on 31 December
    $r11tBefore = VatService::rate('SK');
    assert_equals([20.0, 23.0], [$r11tBefore, $r11tAfter], 'R11 H5: the new VAT rate applies from local midnight');
    ShopTime::setNowForTesting(gmmktime(23, 30, 0, 12, 31, 2024));
    assert_equals('2025-01-01', (string)(VatService::buildSnapshot([['qty' => 1, 'price' => 1230, 'sku' => 'r11t']], ['seller_country' => 'SK', 'buyer_country' => 'SK'], ['currency' => 'eur'])['effective_date'] ?? ''),
        'R11 H5: an order placed at 00:30 local is priced with the new year\'s rates');
} catch (Throwable $e) { $r11tFail('H5 VAT', $e); } finally { ShopTime::setNowForTesting(null); }

// Invoice: paid at 00:20 local on 1 January (23:20 UTC on 31 December) — issued, supplied and numbered in the new year.
try {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, store_id, created_at)
        VALUES (?, 'R11 Time', 'r11t@example.test', '', '{}', 1230, 0, 0, 0, 1230, 'eur', 'paid', 'paid', 'bank', ?, '2026-12-31 23:10:00')")
        ->execute(['R11T-' . bin2hex(random_bytes(4)), $r11tStore]);
    $r11tOrderId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, status, amount, currency, idempotency_key, created_at) VALUES (?, ?, 'manual', 'paid', 1230, 'eur', ?, '2026-12-31 23:20:00')")
        ->execute([$r11tOrderId, $r11tStore, 'r11t-paid-' . $r11tOrderId]);
    ShopTime::setNowForTesting(gmmktime(23, 30, 0, 12, 31, 2026));
    $r11tInvoice = InvoiceService::issue($r11tOrderId);
    assert_equals(['2027-01-01', '2027-01-01'], [substr((string)$r11tInvoice['issued_at'], 0, 10), (string)$r11tInvoice['delivery_date']],
        'R11 H5: an invoice for a payment at 00:20 local on 1 January is issued and supplied on 1 January');
    assert_true(str_contains((string)$r11tInvoice['number'], '2027'), 'R11 H5: and is numbered in the new year\'s series (' . $r11tInvoice['number'] . ')');
    assert_true(str_contains((string)$r11tInvoice['pdf_path'], '2027/01/'), 'R11 H5: its PDF is filed under 2027/01');
} catch (Throwable $e) { $r11tFail('H5 invoice', $e); } finally { ShopTime::setNowForTesting(null); }

// Consumer deadlines: 14 days after delivery end at 23:59:59 of the 14th day on the shop's calendar (Reg. 1182/71 art. 3),
// also across the change to summer time — not 14 × 24 h after the hour of delivery.
try {
    assert_equals('2026-03-15 23:59:59', $r11tLocal(Withdrawal::periodEnd($r11tAt('2026-03-01 15:00:00'), 14)), 'R11 H5: delivered 1 March 15:00, withdrawable until 15 March 23:59:59');
    assert_equals('2026-04-03 23:59:59', $r11tLocal(Withdrawal::periodEnd($r11tAt('2026-03-20 10:00:00'), 14)), 'R11 H5: a period across the change to summer time ends at local midnight');
    $r11tDelivered = ['id' => 0, 'store_id' => $r11tStore, 'status' => 'delivered', 'delivered_at' => gmdate('Y-m-d H:i:s', $r11tAt('2026-03-01 15:00:00'))];
    assert_equals([true, false], [Withdrawal::withinPeriod($r11tDelivered, $r11tAt('2026-03-15 23:30:00')), Withdrawal::withinPeriod($r11tDelivered, $r11tAt('2026-03-16 00:00:30'))],
        'R11 H5: a withdrawal at 23:30 on the last day is in time, one after midnight is not');
    // Digital content without the consumer's waiver is held while the period runs: released at local midnight after its
    // last day, not 14 × 24 h after the hour of the order.
    $r11tDigital = ['id' => 0, 'store_id' => $r11tStore, 'created_at' => gmdate('Y-m-d H:i:s', $r11tAt('2026-03-01 15:00:00'))];
    $r11tRelease = DigitalDelivery::holdUntil($r11tDigital, $r11tAt('2026-03-02 09:00:00'));
    assert_true($r11tRelease !== null && substr($r11tLocal($r11tRelease), 11) === '00:00:00'
        && DigitalDelivery::holdUntil($r11tDigital, $r11tRelease - 1800) !== null && DigitalDelivery::holdUntil($r11tDigital, $r11tRelease + 30) === null,
        'R11 H5: a digital hold lasts until local midnight after the last day of the withdrawal period');
} catch (Throwable $e) { $r11tFail('H5 deadlines', $e); }

// Bank transfer: due at the end of its due day, reminded once before it, cancelled only after the grace days.
try {
    $r11tTransfer = static function (int $daysAgo) use ($pdo, $r11tStore): array {
        $snapshot = ['provider' => 'bank_transfer', 'service_type' => 'bank_transfer', 'requires_online' => false, 'label' => 'Transfer'];
        $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
            VALUES (?, 'R11 Transfer', 'r11t-bank@example.test', '', '{}', 2000, 0, 0, 0, 2000, 'eur', 'pending', 'pending', 'bank_transfer', '', ?, ?, ?)")
            ->execute(['R11TB-' . bin2hex(random_bytes(4)), json_encode($snapshot), $r11tStore, gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400)]);
        return Orders::byId((int)$pdo->lastInsertId());
    };
    $r11tTerm = PendingOrderExpiry::DEFAULT_OFFLINE_SECONDS['bank_transfer'];
    $r11tProbe = $r11tTransfer(14);
    $r11tDue = BankTransferInstructions::dueAt($r11tProbe, $r11tTerm);
    assert_equals('23:59:59', (new DateTimeImmutable('@' . $r11tDue))->setTimezone(new DateTimeZone('Europe/Bratislava'))->format('H:i:s'), 'R11 H5: a transfer is due at the end of its due day on the shop\'s calendar');
    assert_equals(ShopTime::date('Y-m-d', $r11tDue), (string)(BankTransferInstructions::forApi($r11tProbe)['due_date'] ?? ShopTime::date('Y-m-d', $r11tDue)), 'R11 H5: the instructions print that local due date');
    $r11tGrace = $r11tTransfer(15);    // one day past due: the money may still be on its way
    $r11tLapsed = $r11tTransfer(19);   // past due and the 3 grace days
    Orders::expirePending(172800, 100, null, ['bank_transfer' => $r11tTerm]);
    assert_equals(['pending', 'cancelled'], [Orders::byId((int)$r11tGrace['id'])['status'] ?? '', Orders::byId((int)$r11tLapsed['id'])['status'] ?? ''],
        'R11 H5: an unpaid transfer is cancelled only after its due day and the grace days');
    $r11tSoon = $r11tTransfer(13);     // due tomorrow: reminder day
    $r11tEarly = $r11tTransfer(5);     // due in 9 days: not yet
    $r11tMigration = require TEST_CLI_DIR . '/../migrations/20261101120000_order_payment_reminder.php';
    if (!Db::hasColumn('orders', 'payment_reminder_sent_at')) {
        assert_equals(0, BankTransferInstructions::remindDue($r11tTerm), 'R11 H5: before its migration has run the reminder sweep does nothing (no error)');
    }
    $r11tMigration->up($pdo, Db::isSqlite());
    $r11tMigration->up($pdo, Db::isSqlite());
    Db::forgetSchema();
    $r11tFirst = BankTransferInstructions::remindDue($r11tTerm);
    $r11tSecond = BankTransferInstructions::remindDue($r11tTerm);
    $r11tMarked = static fn(array $o): bool => (string)(Orders::byId((int)$o['id'])['payment_reminder_sent_at'] ?? '') !== '';
    assert_true($r11tFirst >= 1 && $r11tSecond === 0 && $r11tMarked($r11tSoon) && !$r11tMarked($r11tEarly) && !$r11tMarked($r11tGrace),
        'R11 H5: the reminder goes once, on the days before the due date, not early and not after it');
    $r11tKeys = array_merge(array_keys(BankTransferInstructions::mailVars($r11tSoon)), ['customer_name', 'order_number', 'order_total']);
    foreach (['sk', 'cs', 'pl', 'hu', 'de', 'es', 'en'] as $r11tLocale) {
        $r11tTpl = (string)file_get_contents(dirname(TEST_CLI_DIR) . "/templates/emails/{$r11tLocale}/bank_transfer_reminder.html")
            . (string)file_get_contents(dirname(TEST_CLI_DIR) . "/templates/emails/{$r11tLocale}/bank_transfer_reminder.subject.txt");
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $r11tTpl, $r11tPh);
        assert_equals([], array_values(array_diff(array_unique($r11tPh[1]), $r11tKeys)), "R11 H5: the transfer reminder ({$r11tLocale}) gets every variable it prints");
    }
    foreach ([$r11tProbe, $r11tGrace, $r11tSoon, $r11tEarly] as $r11tOpen) {
        if ((Orders::byId((int)$r11tOpen['id'])['status'] ?? '') === 'pending') OrderWorkflow::transition((int)$r11tOpen['id'], 'cancelled', 'R11 H5 test cleanup');
    }
} catch (Throwable $e) { $r11tFail('H5 transfer', $e); }

// Feed sync "30 2 * * *" on the shop's clock: the autumn change repeats 02:30 (runs once), the spring change skips it
// (runs right after the change, once).
try {
    $r11tIsDue = new ReflectionMethod(FeedImport::class, 'isDue');
    $r11tDueAt = static function (int $now, string $lastSync) use ($r11tIsDue): bool {
        ShopTime::setNowForTesting($now);
        try { return (bool)$r11tIsDue->invoke(null, '30 2 * * *', $lastSync); } finally { ShopTime::setNowForTesting(null); }
    };
    assert_equals([true, false], [
        $r11tDueAt(gmmktime(0, 45, 0, 10, 25, 2026), '2026-10-24 23:00:00'),   // 02:45 CEST, first 02:30 passed
        $r11tDueAt(gmmktime(1, 45, 0, 10, 25, 2026), '2026-10-25 00:31:00'),   // 02:45 CET, after the repeated 02:30
    ], 'R11 H5: on the autumn change the 02:30 feed runs once, not again in the repeated hour');
    assert_equals([true, false], [
        $r11tDueAt(gmmktime(1, 5, 0, 3, 29, 2026), '2026-03-28 01:31:00'),     // 03:05 CEST: 02:30 never happened
        $r11tDueAt(gmmktime(5, 0, 0, 3, 29, 2026), '2026-03-29 01:02:00'),     // later that morning: already ran
    ], 'R11 H5: on the spring change the skipped 02:30 feed runs right after the change, once');
    assert_equals(false, $r11tDueAt(gmmktime(2, 45, 0, 6, 1, 2026), '2026-06-01 00:45:00'), 'R11 H5: in summer 02:30 is 02:30 local, not 02:30 UTC (synced 02:45 local, now 04:45 local)');
} catch (Throwable $e) { $r11tFail('H5 feed DST', $e); }
