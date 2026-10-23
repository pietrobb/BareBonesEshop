<?php

// Round 9 review (2026-10-23), law and complaints still open from round 8.
echo "\n--- Audit round 9 (law): no consumer surcharge for card or bank transfer ---\n";

Bootstrap::initStoreContext('/', '', 'default');

// 1. PSD2 art. 62(4) / Directive 2011/83/EU art. 19: a consumer is not charged for paying by card or SEPA transfer.
//    A fee configured on those methods applies to an approved business account only; cash on delivery keeps its fee.
$pdo->exec("INSERT INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order)
    VALUES ('r9lcard', 'stripe', 'R9L card', '', 'online_card', 0, 0, 50, 150, NULL, 1, 1, 1),
           ('r9lbank', 'manual', 'R9L bank', '', 'bank_transfer', 0, 0, 30, 0, NULL, 0, 1, 2),
           ('r9lcod', 'manual', 'R9L COD', '', 'cod', 0, 0, 100, 0, NULL, 0, 1, 3)");
$pdo->exec("INSERT INTO payment_method_countries (method_code, country) VALUES ('r9lcard', 'SK'), ('r9lbank', 'SK'), ('r9lcod', 'SK')");
$pdo->exec("INSERT INTO companies (store_id, code, legal_name, approval_status, active) VALUES ('default', 'r9l-co', 'R9L s.r.o.', 'approved', 1), ('default', 'r9l-pend', 'R9L pending s.r.o.', 'pending', 1)");
$r9lCompany = (int)$pdo->query("SELECT id FROM companies WHERE code = 'r9l-co'")->fetchColumn();
$r9lPending = (int)$pdo->query("SELECT id FROM companies WHERE code = 'r9l-pend'")->fetchColumn();
$pdo->prepare("INSERT INTO customers (store_id, email, password_hash, name, company_id, status) VALUES
    ('default', 'r9l-b2b@example.test', '!test!', 'R9L B2B', ?, 'active'),
    ('default', 'r9l-pend@example.test', '!test!', 'R9L Pending', ?, 'active'),
    ('default', 'r9l-consumer@example.test', '!test!', 'R9L Consumer', NULL, 'active')")->execute([$r9lCompany, $r9lPending]);
$r9lCustomer = static fn(string $email): int => (int)$pdo->query("SELECT id FROM customers WHERE email = " . $pdo->quote($email))->fetchColumn();
$r9lFees = static fn(?int $customerId): array => array_map('intval', array_column(array_filter(
    PaymentService::availableMethods('SK', 'eur', 10000, '', $customerId),
    static fn(array $m): bool => str_starts_with((string)$m['code'], 'r9l')), 'fee', 'code'));

assert_equals(['r9lcard' => 0, 'r9lbank' => 0, 'r9lcod' => 100], $r9lFees(null),
    'R9 PSD2: a guest pays no card or bank-transfer fee; cash on delivery keeps its fee');
assert_equals(['r9lcard' => 0, 'r9lbank' => 0, 'r9lcod' => 100], $r9lFees($r9lCustomer('r9l-consumer@example.test')),
    'R9 PSD2: a signed-in consumer pays no card or bank-transfer fee');
assert_equals(['r9lcard' => 0, 'r9lbank' => 0, 'r9lcod' => 100], $r9lFees($r9lCustomer('r9l-pend@example.test')),
    'R9 PSD2: a company account still awaiting approval is charged as a consumer');
assert_equals(['r9lcard' => 200, 'r9lbank' => 30, 'r9lcod' => 100], $r9lFees($r9lCustomer('r9l-b2b@example.test')),
    'R9 PSD2: an approved business account is charged the configured card (0,50 € + 1,5 %) and transfer fees');

$r9lOps = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin/Operations.php');
assert_true(str_contains($r9lOps, "PaymentService::NO_CONSUMER_SURCHARGE") && str_contains($r9lOps, "'admin.payments.saved_consumer_fee'"),
    'R9 PSD2: saving a fee on a card or transfer method tells the admin consumers are not charged it');
foreach (['sk', 'cs', 'en', 'de', 'pl', 'hu', 'es'] as $r9lLang) {
    $r9lStrings = require TEST_CLI_DIR . '/../lang/' . $r9lLang . '.php';
    assert_true(str_contains((string)($r9lStrings['admin.payments.saved_consumer_fee'] ?? ''), 'PSD2'),
        "R9 PSD2: the consumer-fee notice is translated ({$r9lLang})");
}

$pdo->exec("DELETE FROM payment_method_countries WHERE method_code LIKE 'r9l%'");
$pdo->exec("DELETE FROM payment_methods WHERE code LIKE 'r9l%'");
$pdo->exec("DELETE FROM customers WHERE email LIKE 'r9l-%'");
$pdo->exec("DELETE FROM companies WHERE code LIKE 'r9l-%'");
