<?php

// Review round 15 (2026-11-04), low findings. (1) Money::parseInput("1,299") gave 1,30 €: a decimal part finer than a
// cent was rounded instead of refused. (2) The contact and address typed at checkout were lost on a reload or a trip
// back to the cart. (3) Withdrawal and complaint confirmations showed the receipt time in UTC and the complaint decision
// day on the UTC calendar instead of the shop's.
echo "\n--- Audit round 15: low findings (amount input, checkout draft, confirmation times) ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r15lFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R15 low {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};

// (1) Amount input.
try {
    $r15lParse = static fn(string $raw, string $currency = 'eur'): int => Money::parseInput($raw, $currency);
    assert_equals([-1, -1, -1], [$r15lParse('1,299'), $r15lParse('1.299'), $r15lParse('12,5001')],
        'R15 low: an amount with a decimal part finer than a cent is refused, not rounded ("1,299" is never 1,30 €)');
    assert_equals([129, 1250, 129950, 129950, 1234567800, 129900, 1000],
        [$r15lParse('1,29'), $r15lParse('12.5'), $r15lParse('1.299,50'), $r15lParse('1,299.50'), $r15lParse('12,345,678'), $r15lParse('1 299'), $r15lParse('10,-')],
        'R15 low: cents, either decimal separator, grouping with both kinds or a repeated one, spaces and "10,-" still parse');
    assert_equals([129900, -1], [$r15lParse('1 299', 'huf'), $r15lParse('1,5', 'huf')], 'R15 low: a whole-unit currency still refuses decimals');
} catch (Throwable $e) {
    $r15lFail('amount input', $e);
}

// (3) Confirmation times on the shop's clock (the test store runs on Europe/Bratislava).
try {
    // Received 23:30 UTC on 4 Nov is already 5 Nov in Bratislava: the 30 days run from the local day.
    assert_equals('2026-12-05', Complaints::decideBy(['received_at' => '2026-11-04 23:30:00', 'store_id' => 'default']),
        'R15 low: the complaint decision day counts from the receipt day on the shop\'s calendar');
    $r15lStamp = ShopTime::stamp('2026-11-04 23:30:00', 'sk');
    assert_equals(['5. 11. 2026 00:30 CET', false], [$r15lStamp, str_contains($r15lStamp, 'UTC')],
        'R15 low: a receipt time is the shop\'s wall clock in the locale\'s format with its zone, not UTC');
    assert_equals('14 Jul 2026, 10:05 CEST', ShopTime::stamp('2026-07-14 08:05:00', 'en'), 'R15 low: summer time and the English format');
    assert_equals(['', 'not a time'], [ShopTime::stamp(''), ShopTime::stamp('not a time')], 'R15 low: an unparsable stamp comes back unchanged');
    $r15lWithdrawal = (string)file_get_contents(dirname(__DIR__, 3) . '/src/Withdrawal.php');
    $r15lComplaints = (string)file_get_contents(dirname(__DIR__, 3) . '/src/Complaints.php');
    $r15lPages = (string)file_get_contents(dirname(__DIR__, 3) . '/src/Entrypoints/Storefront/WithdrawalPages.php')
        . (string)file_get_contents(dirname(__DIR__, 3) . '/src/Entrypoints/Storefront/ComplaintPages.php');
    $r15lShown = static fn(string $src): bool => (bool)preg_match("/\\.\\s*' UTC'\\s*,/", $src);
    assert_equals([false, false, false], [$r15lShown($r15lWithdrawal), $r15lShown($r15lComplaints), $r15lShown($r15lPages)],
        'R15 low: no confirmation (e-mail or page) appends " UTC" to a stored time any more');
} catch (Throwable $e) {
    $r15lFail('confirmation times', $e);
}

// (2) The checkout draft survives a reload and is dropped on logout.
try {
    $_SESSION = [];
    $r15lTyped = ['name' => 'R15L Typed', 'email' => 'r15l@example.test', 'phone' => '+421900000151', 'street' => 'R15L Street 1',
        'city' => 'Zilina', 'zip' => '01001', 'country' => 'SK', 'notes' => 'R15L note'];
    CheckoutService::formState(['name' => ''] + $r15lTyped, true);
    $r15lAfterRefusal = CheckoutService::formState([], false);
    CheckoutService::formState($r15lTyped, true);
    $r15lReload = CheckoutService::formState([], false);
    assert_equals(['', 'r15l@example.test'], [$r15lAfterRefusal['values']['name'], $r15lAfterRefusal['values']['email']],
        'R15 low: a reload after a refused submit shows what was typed (the empty name stays empty)');
    assert_equals(['R15L Typed', 'r15l@example.test', '+421900000151', 'R15L Street 1', 'Zilina', '01001', 'R15L note', 'SK'],
        [$r15lReload['values']['name'], $r15lReload['values']['email'], $r15lReload['values']['phone'], $r15lReload['values']['street'],
            $r15lReload['values']['city'], $r15lReload['values']['zip'], $r15lReload['values']['notes'], $r15lReload['country']],
        'R15 low: a fresh checkout form (reload, back from the cart) keeps the contact and address last posted in the session');
    Customers::logout();
    $r15lLoggedOut = CheckoutService::formState([], false);
    assert_equals([false, '', ''], [isset($_SESSION[CheckoutService::DRAFT_KEY]), $r15lLoggedOut['values']['email'], $r15lLoggedOut['values']['street']],
        'R15 low: logging out drops the checkout draft (the next person at a shared computer does not see it)');
    $r15lExecute = (string)file_get_contents(dirname(__DIR__, 3) . '/src/Http/ApiHandlers.php');
    assert_true((bool)preg_match('/Cart::clear\(\);\s*unset\(\$_SESSION\[CheckoutService::DRAFT_KEY\]\);/', $r15lExecute),
        'R15 low: placing the order drops the checkout draft together with the cart');
} catch (Throwable $e) {
    $r15lFail('checkout draft', $e);
} finally {
    $_SESSION = [];
}
