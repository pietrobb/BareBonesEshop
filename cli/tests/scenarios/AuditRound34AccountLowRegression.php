<?php

// Review round 34 (low, account addresses): a long IČO or DIČ in an account address failed the VARCHAR(40) columns
// with a 500 on MySQL; an address saved before the IČO/DIČ columns existed never got them; an edited address stored
// the IČO as typed (spaces, lower case) while a new one and the checkout normalise it.
echo "\n--- Audit round 34: account address IČO/DIČ ---\n";

try {
    $pdo->prepare("INSERT INTO customers (store_id, email, password_hash, name, status) VALUES ('default', ?, ?, ?, 'active')")
        ->execute(['r34a@example.test', 'r34a-hash', 'R34A Buyer']);
    $r34aCustomer = (int)$pdo->query("SELECT id FROM customers WHERE email = 'r34a@example.test'")->fetchColumn();
    $r34aBase = ['type' => 'billing', 'name' => 'R34A Buyer', 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'];
    $r34aRow = static function (int $id) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT company, vat_id, ico, dic FROM customer_addresses WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    };

    // 1. A long or malformed ID is a field error, not a database error; a spaced one is accepted.
    assert_equals(['ico' => 'account.err_address_company_id', 'dic' => 'account.err_address_company_id'],
        Customers::addressErrors($r34aBase + ['company' => 'R34A s.r.o.', 'ico' => str_repeat('1', 41), 'dic' => 'SK 20<20>']),
        'R34 account: a 41-character IČO and a DIČ with other characters are refused per field');
    assert_equals([], Customers::addressErrors($r34aBase + ['company' => 'R34A s.r.o.', 'ico' => ' 27 082 440 ', 'dic' => 'sk2020202020']),
        'R34 account: an IČO with spaces and a lower-case DIČ are accepted (stored normalised)');

    // 2. Editing an address checks and normalises the IDs like a new one.
    $r34aId = Customers::addAddress($r34aCustomer, $r34aBase + ['company' => 'R34A s.r.o.', 'ico' => '27082440', 'dic' => 'SK2020202020']);
    $r34aRefused = Customers::updateCheckedAddress($r34aCustomer, $r34aId, ['dic' => str_repeat('D', 45)]);
    assert_true($r34aRefused === ['dic' => 'account.err_address_company_id'] && $r34aRow($r34aId)['dic'] === 'SK2020202020',
        'R34 account: an edit with a 45-character DIČ is refused and the stored DIČ is kept');
    assert_equals([], Customers::updateCheckedAddress($r34aCustomer, $r34aId, ['ico' => '36 421 928', 'dic' => 'sk 2021 234567']), 'R34 account: a spaced edit is accepted');
    assert_equals(['36421928', 'SK2021234567'], array_values(array_intersect_key($r34aRow($r34aId), ['ico' => 1, 'dic' => 1])),
        'R34 account: the edited IČO and DIČ are stored without spaces, upper case, as a new address and the checkout store them');

    // 3. The same address saved before the IČO/DIČ columns (NULL) gets them from the next order that saves it.
    $pdo->prepare("INSERT INTO customer_addresses (customer_id, type, is_default, name, company, vat_id, street, city, zip, country, phone, ico, dic)
        VALUES (?, 'shipping', 1, 'R34A Buyer', 'R34A s.r.o.', '', 'Staromestská 5', 'Košice', '04001', 'SK', '', NULL, NULL)")->execute([$r34aCustomer]);
    $r34aOld = (int)$pdo->lastInsertId();
    $r34aCheckout = ['name' => 'R34A Buyer', 'street' => 'Staromestská 5', 'city' => 'Košice', 'zip' => '040 01', 'country' => 'SK', 'phone' => '',
        'company' => 'R34A s.r.o.', 'ico' => '27082440', 'dic' => 'SK2020202020', 'vat_id' => 'SK2020202020'];
    $r34aCount = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM customer_addresses WHERE customer_id = {$r34aCustomer}")->fetchColumn();
    $r34aBefore = $r34aCount();
    assert_true(GuestCheckoutAccount::rememberAddress($r34aCustomer, $r34aCheckout) && $r34aCount() === $r34aBefore
        && $r34aRow($r34aOld) === ['company' => 'R34A s.r.o.', 'vat_id' => 'SK2020202020', 'ico' => '27082440', 'dic' => 'SK2020202020'],
        'R34 account: an address saved before IČO/DIČ were kept is completed from the order, not saved twice (' . json_encode($r34aRow($r34aOld)) . ')');

    // The same street saved for another company keeps its own (empty) IDs.
    $pdo->prepare("UPDATE customer_addresses SET company = 'Other a.s.', vat_id = '', ico = NULL, dic = NULL WHERE id = ?")->execute([$r34aOld]);
    assert_true(!GuestCheckoutAccount::rememberAddress($r34aCustomer, $r34aCheckout)
        && $r34aRow($r34aOld) === ['company' => 'Other a.s.', 'vat_id' => '', 'ico' => null, 'dic' => null],
        'R34 account: a saved address of another company is not given this company\'s IDs');
    $pdo->prepare('DELETE FROM customer_addresses WHERE customer_id = ?')->execute([$r34aCustomer]);
    $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$r34aCustomer]);
} catch (Throwable $e) {
    assert_true(false, 'R34 account (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
