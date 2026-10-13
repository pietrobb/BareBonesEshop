<?php

// P0 2026-10-13 layer 4: card checkout was dead for five days while every server
// signal stayed green. The payment funnel alarm, CSP violation metrics and the
// synthetic smoke token make that failure visible without a customer complaint.
echo "\n--- P0 layer 4: payment funnel alarm, CSP metrics, synthetic orders ---\n";

$pmNow = strtotime('2031-03-01T12:00:00+00:00');
$pmConfig = require TEST_CLI_DIR . '/../config.php';
$pmConfig['jobs_dir'] = sys_get_temp_dir();
$pmConfig['private_cache_dir'] = sys_get_temp_dir();
$pmConfig['monitoring'] = [
    'backup_rpo_seconds' => 86400, 'queue_pending_warning' => 999999, 'failed_jobs_warning' => 999999,
    'failed_mail_warning' => 999999, 'payment_incidents_critical' => 999999, 'webhook_incidents_warning' => 999999,
];
$pmSnapshot = static fn(): array => OperationalMetrics::snapshot($pmConfig, dirname(TEST_CLI_DIR), $pdo, 'default', [['meta' => ['timestamp' => gmdate('c', $pmNow - 60)]]], $pmNow);
$pmCodes = static fn(array $snapshot): array => array_column($snapshot['alerts'], 'code');
$pmSeq = 0;
$pmOrder = static function (bool $online, string $paymentId, string $paymentStatus, int $age, int $synthetic = 0) use ($pdo, $pmNow, &$pmSeq): void {
    $pdo->prepare("INSERT INTO orders (order_number, store_id, status, payment_status, customer_name, customer_email, payment_method,
            subtotal, total, payment_id, payment_method_snapshot, is_synthetic, created_at)
        VALUES (?, 'default', 'pending', ?, 'Funnel Probe', 'funnel-probe@example.test', ?, 1000, 1000, ?, ?, ?, ?)")
        ->execute(['PM-FUNNEL-' . (++$pmSeq), $paymentStatus, $online ? 'card' : 'bank_transfer', $paymentId,
            json_encode(['code' => $online ? 'card' : 'bank_transfer', 'requires_online' => $online]), $synthetic, gmdate('Y-m-d H:i:s', $pmNow - $age)]);
};
$pmFunnelAlerts = ['payment_funnel_stalled', 'payment_session_failing'];

$pdo->exec("DELETE FROM orders WHERE customer_email = 'funnel-probe@example.test'");
$pmBase = $pmSnapshot();
assert_equals(0, $pmBase['metrics']['payment_online_started'], 'P0 funnel: no online checkout in the window');
assert_true(!array_intersect($pmFunnelAlerts, $pmCodes($pmBase)), 'P0 funnel: quiet shop raises no funnel alarm');

$pmOrder(true, 'cs_probe_1', 'pending', 7200);
$pmOrder(true, 'cs_probe_2', 'pending', 7300);
for ($pmI = 0; $pmI < 5; $pmI++) $pmOrder(true, 'cs_probe_syn_' . $pmI, 'pending', 7400, 1);
$pmSmall = $pmSnapshot();
assert_equals(2, $pmSmall['metrics']['payment_online_started'], 'P0 funnel: synthetic smoke orders are not counted');
assert_true(!array_intersect($pmFunnelAlerts, $pmCodes($pmSmall)), 'P0 funnel: below min_started there is no alarm');

// The 1.9.0 pattern: card orders reach a gateway session, nobody ever pays.
$pmOrder(true, 'cs_probe_3', 'pending', 10800);
$pmOrder(true, 'cs_probe_fresh', 'pending', 600);          // inside the grace period
$pmOrder(false, '', 'pending', 9000);                       // bank transfer is not a gateway checkout
$pmOrder(true, 'cs_probe_old', 'pending', 90000);           // outside the 24 h window
$pmStalled = $pmSnapshot();
assert_equals(3, $pmStalled['metrics']['payment_online_started'], 'P0 funnel: counts online checkouts between grace and window only');
assert_equals(3, $pmStalled['metrics']['payment_online_redirected'], 'P0 funnel: counts gateway sessions');
assert_equals(0, $pmStalled['metrics']['payment_online_paid'], 'P0 funnel: counts paid orders');
assert_true(in_array('payment_funnel_stalled', $pmCodes($pmStalled), true), 'P0 funnel: online orders with sessions but no payment raise payment_funnel_stalled');
assert_equals('critical', $pmStalled['status'], 'P0 funnel: a stalled payment funnel is critical');

$pmOrder(true, 'pi_probe_paid', 'paid', 14400);
$pmPaid = $pmSnapshot();
assert_true(!array_intersect($pmFunnelAlerts, $pmCodes($pmPaid)), 'P0 funnel: one paid online order clears the alarm');

$pdo->exec("DELETE FROM orders WHERE customer_email = 'funnel-probe@example.test'");
for ($pmI = 0; $pmI < 3; $pmI++) $pmOrder(true, '', 'pending', 5000 + $pmI);
$pmNoSession = $pmSnapshot();
assert_true(in_array('payment_session_failing', $pmCodes($pmNoSession), true), 'P0 funnel: online orders without any gateway session raise payment_session_failing');
$pdo->exec("DELETE FROM orders WHERE customer_email = 'funnel-probe@example.test'");

// CSP reports feed the same snapshot.
$pdo->exec('DELETE FROM csp_violations');
$pmFormAction = json_encode(['csp-report' => ['document-uri' => 'https://shop.example/pokladna', 'effective-directive' => 'form-action', 'blocked-uri' => 'https://checkout.stripe.com/c/pay/cs_1']]);
assert_equals(1, CspReports::ingest((string)json_encode(['csp-report' => ['document-uri' => 'https://shop.example/', 'effective-directive' => 'img-src', 'blocked-uri' => 'https://tracker.example/p.gif']]), $pdo, $pmNow - 16 * 86400), 'P0 CSP: report is stored');
assert_equals(1, CspReports::ingest((string)$pmFormAction, $pdo, $pmNow - 90000), 'P0 CSP: report outside the alarm window is stored');
assert_equals(0, (int)$pdo->query("SELECT COUNT(*) FROM csp_violations WHERE directive = 'img-src'")->fetchColumn(), 'P0 CSP: reports older than the retention are pruned');
$pmQuiet = $pmSnapshot();
assert_true(!in_array('csp_form_action_violation', $pmCodes($pmQuiet), true), 'P0 CSP: violations outside the window do not alarm');
CspReports::ingest((string)json_encode(['csp-report' => ['document-uri' => 'https://shop.example/', 'effective-directive' => 'form-action', 'blocked-uri' => 'https://x.example/', 'disposition' => 'report']]), $pdo, $pmNow);
assert_equals(0, $pmSnapshot()['metrics']['csp_form_action_violations'], 'P0 CSP: report-only violations are not counted as blocked');
assert_equals(0, CspReports::ingest('not json', $pdo, $pmNow) + CspReports::ingest('{"csp-report":{"effective-directive":"x;drop","blocked-uri":"https://a.example"}}', $pdo, $pmNow), 'P0 CSP: malformed reports are ignored');
CspReports::ingest((string)$pmFormAction, $pdo, $pmNow - 60);
$pmBlocked = $pmSnapshot();
assert_equals(1, $pmBlocked['metrics']['csp_form_action_violations'], 'P0 CSP: blocked form-action is exported');
assert_true(in_array('csp_form_action_violation', $pmCodes($pmBlocked), true) && $pmBlocked['status'] === 'critical', 'P0 CSP: a blocked form-action (gateway redirect) is critical');
$pdo->exec('DELETE FROM csp_violations');

// Only the configured token (>= 32 chars) makes a checkout synthetic.
$pmPrevHeader = $_SERVER['HTTP_X_BAREBONES_SYNTHETIC'] ?? null;
$pmToken = str_repeat('t', 40);
$_SERVER['HTTP_X_BAREBONES_SYNTHETIC'] = 'short';
assert_true(!SyntheticCheckout::isRequest(['monitoring' => ['synthetic_token' => 'short']]), 'P0 synthetic: a short configured token never enables synthetic orders');
$_SERVER['HTTP_X_BAREBONES_SYNTHETIC'] = $pmToken . 'x';
assert_true(!SyntheticCheckout::isRequest(['monitoring' => ['synthetic_token' => $pmToken]]), 'P0 synthetic: a wrong token is a normal checkout');
assert_true(!SyntheticCheckout::isRequest([]), 'P0 synthetic: disabled without a configured token');
$_SERVER['HTTP_X_BAREBONES_SYNTHETIC'] = $pmToken;
assert_true(SyntheticCheckout::isRequest(['monitoring' => ['synthetic_token' => $pmToken]]), 'P0 synthetic: the configured token marks the smoke checkout');

// A synthetic checkout must prove a gateway redirect; an offline method would be a real order.
$_SESSION = ['cart' => [], 'csrf_token' => 'pm-synthetic'];
Cart::add((int)$payProduct['id'], null, 1);
$pmOrdersBefore = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$pmOffline = checkoutExecute(['name' => 'Smoke', 'email' => 'pm-synthetic-cod@example.test', 'street' => 'Smoke 1', 'city' => 'Bratislava',
    'zip' => '81101', 'country' => 'SK', 'shipping_method' => 'packeta', 'payment_method' => 'cod'],
    array_replace($pmConfig, ['app_url' => 'https://shop.example', 'monitoring' => ['synthetic_token' => $pmToken]]));
assert_equals('Synthetic checkout requires an online payment method', (string)($pmOffline['error'] ?? ''), 'P0 synthetic: offline payment is refused');
assert_equals($pmOrdersBefore, (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(), 'P0 synthetic: refused smoke creates no order');
if ($pmPrevHeader === null) unset($_SERVER['HTTP_X_BAREBONES_SYNTHETIC']); else $_SERVER['HTTP_X_BAREBONES_SYNTHETIC'] = $pmPrevHeader;
http_response_code(200);
$_SESSION = [];

// close() only ever touches synthetic orders.
$pmOrder(true, 'cs_probe_real', 'pending', 100);
$pmRealId = (int)$pdo->query("SELECT id FROM orders WHERE customer_email = 'funnel-probe@example.test'")->fetchColumn();
assert_true(!SyntheticCheckout::close($pmRealId), 'P0 synthetic: close() refuses a real customer order');
assert_equals('pending', (string)$pdo->query("SELECT status FROM orders WHERE id = {$pmRealId}")->fetchColumn(), 'P0 synthetic: the real order stays pending');
$pdo->exec("DELETE FROM orders WHERE customer_email = 'funnel-probe@example.test'");

// The release gate is written down where operators and CI look.
$pmQualification = (string)file_get_contents(TEST_CLI_DIR . '/../docs/PRODUCTION_QUALIFICATION.md');
$pmRunbook = (string)file_get_contents(TEST_CLI_DIR . '/../docs/OPERATIONS_RUNBOOK.md');
$pmWorkflow = (string)file_get_contents(TEST_CLI_DIR . '/../.github/workflows/payment-gateway.yml');
assert_true(str_contains($pmQualification, 'Checkout critical-path release gate') && str_contains($pmQualification, 'stripe-test.spec.js') && str_contains($pmQualification, 'smoke-checkout.php'), 'P0 gate: production qualification requires all four checkout layers');
assert_true(str_contains($pmRunbook, '## Post-deploy checkout smoke') && str_contains($pmRunbook, 'payment_funnel_stalled') && str_contains($pmRunbook, 'csp_form_action_violation'), 'P0 gate: runbook documents the smoke and both alarms');
foreach (['src/Security.php', 'src/UrlAliases.php', 'src/Updater.php', 'adapters/contrib/*Payment.php', 'src/Entrypoints/Storefront/CheckoutPages.php'] as $pmCritical) {
    assert_equals(2, substr_count($pmWorkflow, "'{$pmCritical}'"), "P0 gate: Stripe test workflow runs on push and PR changes to {$pmCritical}");
}
