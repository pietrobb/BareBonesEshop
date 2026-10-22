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
