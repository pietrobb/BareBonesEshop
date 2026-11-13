<?php

// Review round 35 (low, account addresses): a company, name, street or VAT ID longer than 191 characters failed the
// VARCHAR(191) columns with a 500 on MySQL (the R34 IČO/DIČ class of bug in the other fields); the VAT ID was neither
// checked nor normalised on save, while the checkout does both.
echo "\n--- Audit round 35: account address field lengths and VAT ID ---\n";

try {
    $pdo->prepare("INSERT INTO customers (store_id, email, password_hash, name, status) VALUES ('default', ?, ?, ?, 'active')")
        ->execute(['r35a@example.test', 'r35a-hash', 'R35A Buyer']);
    $r35aCustomer = (int)$pdo->query("SELECT id FROM customers WHERE email = 'r35a@example.test'")->fetchColumn();
    $r35aBase = ['type' => 'billing', 'name' => 'R35A Buyer', 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'];
    $r35aRow = static function (int $id) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT name, company, vat_id, street FROM customer_addresses WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $r35aLong = str_repeat('ž', 192);

    // 1. Too long for the column: a field error per field, not a database error.
    assert_equals(['name' => 'account.err_address_too_long', 'company' => 'account.err_address_too_long', 'street' => 'account.err_address_too_long', 'city' => 'account.err_address_too_long'],
        Customers::addressErrors(['name' => $r35aLong, 'company' => $r35aLong, 'street' => $r35aLong, 'city' => $r35aLong] + $r35aBase),
        'R35 account: a 192-character name, company, street and city are refused per field (was a MySQL 500)');
    $r35aFits = str_repeat('ž', 191);
    assert_equals([], Customers::addressErrors(['company' => $r35aFits] + $r35aBase), 'R35 account: 191 characters (multibyte) still fit');
    $r35aId = Customers::addAddress($r35aCustomer, ['company' => $r35aFits] + $r35aBase);
    assert_equals($r35aFits, $r35aRow($r35aId)['company'] ?? null, 'R35 account: a 191-character company is saved whole');

    // 2. The VAT ID is checked like the checkout checks it, and stored normalised.
    assert_equals(['vat_id' => 'account.err_address_vat_id'], Customers::addressErrors(['vat_id' => '2020123456 SK'] + $r35aBase),
        'R35 account: a malformed VAT ID is refused');
    assert_equals(['vat_id' => 'account.err_address_vat_id'], Customers::addressErrors(['vat_id' => 'SK' . str_repeat('1', 200)] + $r35aBase),
        'R35 account: a 202-character VAT ID is refused as a VAT ID (was a MySQL 500)');
    $r35aVat = Customers::addAddress($r35aCustomer, ['vat_id' => ' sk 2020.123-456 '] + $r35aBase);
    assert_equals('SK2020123456', $r35aRow($r35aVat)['vat_id'] ?? null, 'R35 account: a new address stores the VAT ID without spaces, dots, dashes, upper case');

    // 3. An edit is checked and normalised the same; a refused edit keeps what was stored.
    assert_true(Customers::updateCheckedAddress($r35aCustomer, $r35aVat, ['street' => $r35aLong]) === ['street' => 'account.err_address_too_long']
        && ($r35aRow($r35aVat)['street'] ?? null) === 'Hlavná 1', 'R35 account: an edit with a 192-character street is refused and the street kept');
    assert_true(Customers::updateCheckedAddress($r35aCustomer, $r35aVat, ['vat_id' => 'XX']) === ['vat_id' => 'account.err_address_vat_id']
        && ($r35aRow($r35aVat)['vat_id'] ?? null) === 'SK2020123456', 'R35 account: an edit with a malformed VAT ID is refused and the VAT ID kept');
    assert_equals([], Customers::updateCheckedAddress($r35aCustomer, $r35aVat, ['vat_id' => 'cz 699 001 234']), 'R35 account: a spaced lower-case VAT ID edit is accepted');
    assert_equals('CZ699001234', $r35aRow($r35aVat)['vat_id'] ?? null, 'R35 account: the edited VAT ID is stored normalised');

    // 4. The messages exist in every storefront language.
    foreach (['account.err_address_too_long', 'account.err_address_vat_id'] as $r35aKey) {
        assert_true(I18n::t($r35aKey) !== $r35aKey, "R35 account: {$r35aKey} is translated");
    }
    $pdo->prepare('DELETE FROM customer_addresses WHERE customer_id = ?')->execute([$r35aCustomer]);
    $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$r35aCustomer]);
} catch (Throwable $e) {
    assert_true(false, 'R35 account (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
