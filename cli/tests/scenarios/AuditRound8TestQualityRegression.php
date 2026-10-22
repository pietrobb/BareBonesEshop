<?php

// Review round 8 (test quality): asserts that kill mutants which survived the previous suite.
echo "\n--- Review round 8: test quality ---\n";

// Mutant 1: a domestic B2B buyer with a valid VAT ID was switched to reverse charge (0 % VAT) and no test failed —
// the domestic case was only checked without a VAT ID. A supply inside one country always carries the seller's VAT.
foreach (['SK', 'CZ', 'DE'] as $r8qCountry) {
    $r8qSc = VatService::resolveScenario(['seller_country' => $r8qCountry, 'customer_country' => strtolower($r8qCountry),
        'is_b2b' => true, 'customer_vat_id_valid' => true, 'seller_oss_enabled' => true]);
    assert_equals('domestic', $r8qSc['scenario'], "R8 tests: domestic B2B with a valid VAT ID stays domestic ({$r8qCountry})");
    assert_true($r8qSc['applies_vat'] === true && $r8qSc['vat_rate'] === null && $r8qSc['note_key'] === '',
        "R8 tests: domestic B2B charges the seller's VAT per line, with no exemption note ({$r8qCountry})");
    assert_equals($r8qCountry, $r8qSc['rate_country'], "R8 tests: domestic B2B uses the seller-country rate ({$r8qCountry})");
}
// Reverse charge needs all three conditions; dropping any one of them must leave VAT on.
$r8qSc = VatService::resolveScenario(['seller_country' => 'SK', 'customer_country' => 'DE', 'is_b2b' => true, 'customer_vat_id_valid' => false]);
assert_equals('domestic_subthreshold', $r8qSc['scenario'], 'R8 tests: intra-EU B2B without a valid VAT ID is not reverse charge');
$r8qSc = VatService::resolveScenario(['seller_country' => 'SK', 'customer_country' => 'DE', 'is_b2b' => false, 'customer_vat_id_valid' => true]);
assert_equals('domestic_subthreshold', $r8qSc['scenario'], 'R8 tests: intra-EU B2C with a VAT ID is not reverse charge');
$r8qSc = VatService::resolveScenario(['seller_country' => 'SK', 'customer_country' => 'DE', 'is_b2b' => true, 'customer_vat_id_valid' => true]);
assert_true($r8qSc['scenario'] === 'reverse_charge' && $r8qSc['vat_rate'] === 0.0 && $r8qSc['applies_vat'] === false
    && $r8qSc['rate_country'] === 'DE', 'R8 tests: intra-EU B2B with a valid VAT ID is reverse charge at 0 % in the customer country');

assert_true(!InvoiceService::isReverseCharge(['country' => 'SK'], ['country' => 'sk', 'ic_dph' => 'SK2020123456']),
    'R8 tests: invoice to a domestic company with an IČ DPH is not reverse charge');
assert_true(!InvoiceService::isReverseCharge(['country' => 'SK'], ['country' => 'DE', 'ic_dph' => '  ']),
    'R8 tests: invoice without a VAT ID (blank) is not reverse charge');
assert_true(!InvoiceService::isReverseCharge(['country' => 'SK'], ['country' => 'US', 'ic_dph' => 'US123']),
    'R8 tests: invoice outside the EU is not reverse charge');
assert_true(!InvoiceService::isReverseCharge(['country' => ''], ['country' => 'DE', 'ic_dph' => 'DE123456789']),
    'R8 tests: invoice without a seller country is not reverse charge');
assert_true(InvoiceService::isReverseCharge(['country' => 'SK'], ['country' => 'de', 'ic_dph' => 'DE123456789']),
    'R8 tests: invoice to a German company with a VAT ID is reverse charge');

// Mutant 2: GDPR erase could keep the customer's name, phone and VAT ID — only the e-mail, password and addresses were checked.
$r8qDb = Db::get();
$r8qInsert = $r8qDb->prepare("INSERT INTO customers (store_id, email, password_hash, name, phone, vat_id, vat_country, vat_validated_at, status)
    VALUES ('default', ?, '!test!', ?, ?, ?, 'SK', '2026-10-01 00:00:00', 'active')");
$r8qInsert->execute(['r8q-erase@example.test', 'R8Q Erased Name', '+421 900 111 222', 'SK2020111222']);
$r8qSubject = (int)$r8qDb->lastInsertId();
$r8qInsert->execute(['r8q-keep@example.test', 'R8Q Erased Name', '+421 900 111 222', 'SK2020111222']);
$r8qOther = (int)$r8qDb->lastInsertId();
GdprService::anonymizeCustomer($r8qSubject, 'r8q-test');
$r8qRow = static fn(int $id): array => $r8qDb->query('SELECT email, name, phone, vat_id, vat_country, vat_validated_at, status FROM customers WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
$r8qErased = $r8qRow($r8qSubject);
assert_equals(['deleted-' . $r8qSubject . '@anonymized.local', '', '', null, null, null, 'anonymized'], array_values($r8qErased),
    'R8 tests: GDPR erase clears the account name, phone and VAT ID (' . json_encode($r8qErased) . ')');
assert_equals(['r8q-keep@example.test', 'R8Q Erased Name', '+421 900 111 222', 'SK2020111222', 'SK', '2026-10-01 00:00:00', 'active'], array_values($r8qRow($r8qOther)),
    'R8 tests: GDPR erase leaves another customer with the same name and phone untouched');

// Mutant 3: StripePayment::verifySignature had no direct test — any check in it could be removed.
$r8qSecret = 'whsec_r8q_0123456789abcdef';
$r8qStripe = new StripePayment(['stripe_webhook_secret' => $r8qSecret]);
$r8qBody = '{"id":"evt_r8q","type":"checkout.session.completed"}';
$r8qNow = (string)time();
$r8qSig = static fn(string $t, string $body = '', string $secret = ''): string => hash_hmac('sha256', $t . '.' . ($body !== '' ? $body : $r8qBody), $secret !== '' ? $secret : $r8qSecret);
$r8qGood = $r8qSig($r8qNow);
assert_true($r8qStripe->verifySignature($r8qBody, 't=' . $r8qNow . ',v1=' . $r8qGood), 'R8 tests: Stripe accepts a valid current signature');
assert_true($r8qStripe->verifySignature($r8qBody, 't=' . $r8qNow . ', v0=abc, v1=' . str_repeat('0', 64) . ', v1=' . strtoupper($r8qGood)),
    'R8 tests: Stripe accepts any one matching v1 among several (rolled secrets), case-insensitively');
$r8qRejected = [
    'tampered body' => [$r8qBody . ' ', 't=' . $r8qNow . ',v1=' . $r8qGood],
    'wrong secret' => [$r8qBody, 't=' . $r8qNow . ',v1=' . $r8qSig($r8qNow, '', 'whsec_r8q_other_secret_xx')],
    'signature over another timestamp' => [$r8qBody, 't=' . $r8qNow . ',v1=' . $r8qSig((string)((int)$r8qNow - 1))],
    'old timestamp' => [$r8qBody, 't=' . ((int)$r8qNow - 301) . ',v1=' . $r8qSig((string)((int)$r8qNow - 301))],
    'future timestamp' => [$r8qBody, 't=' . ((int)$r8qNow + 301) . ',v1=' . $r8qSig((string)((int)$r8qNow + 301))],
    'no timestamp' => [$r8qBody, 'v1=' . $r8qGood],
    'non-numeric timestamp' => [$r8qBody, 't=' . $r8qNow . 'x,v1=' . $r8qSig($r8qNow . 'x')],
    'two different timestamps' => [$r8qBody, 't=' . ((int)$r8qNow - 1000) . ',t=' . $r8qNow . ',v1=' . $r8qGood],
    'no v1' => [$r8qBody, 't=' . $r8qNow . ',v0=' . $r8qGood],
    'empty v1' => [$r8qBody, 't=' . $r8qNow . ',v1='],
    'empty header' => [$r8qBody, ''],
];
foreach ($r8qRejected as $r8qCase => [$r8qPayload, $r8qHeader]) {
    assert_true(!$r8qStripe->verifySignature($r8qPayload, $r8qHeader), "R8 tests: Stripe rejects a webhook with {$r8qCase}");
}
assert_true($r8qStripe->verifySignature($r8qBody, 't=' . ((int)$r8qNow - 290) . ',v1=' . $r8qSig((string)((int)$r8qNow - 290))),
    'R8 tests: Stripe accepts a signature inside the 5-minute tolerance');
foreach (['' => 'no secret', 'whsec_short' => 'a secret shorter than 16 characters', ' ' . $r8qSecret => 'a secret with surrounding whitespace'] as $r8qBad => $r8qCase) {
    $r8qBadSig = hash_hmac('sha256', $r8qNow . '.' . $r8qBody, (string)$r8qBad);
    assert_true(!(new StripePayment(['stripe_webhook_secret' => (string)$r8qBad]))->verifySignature($r8qBody, 't=' . $r8qNow . ',v1=' . $r8qBadSig),
        "R8 tests: Stripe rejects every webhook when configured with {$r8qCase}");
}
