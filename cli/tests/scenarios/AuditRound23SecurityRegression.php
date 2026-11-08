<?php
/**
 * Review round 23, low security findings: the CSP reporter counts an IPv6 client per /64, the logger masks pwd=,
 * private_key and an Authorization header without a scheme (T15), PayU can refuse MD5-signed notifications (T20).
 */
echo "\n--- R23 security (low) ---\n";

// 1. One IPv6 client picks a fresh address of its /64 for every forged form-action report: that is one reporter.
$r23sDb = Db::get();
$r23sDb->exec('DELETE FROM csp_violations');
$r23sNow = time();
$r23sForged = (string)json_encode(['csp-report' => ['document-uri' => 'https://shop.example/pokladna', 'effective-directive' => 'form-action', 'blocked-uri' => 'https://evil.example/x']]);
$r23sStore = StoreContext::current()->id();
CspReports::ingest($r23sForged, $r23sDb, $r23sNow, '2001:db8:1:2::1');
CspReports::ingest($r23sForged, $r23sDb, $r23sNow, '2001:db8:1:2:ffff:abcd:1234:5678');
CspReports::ingest($r23sForged, $r23sDb, $r23sNow, '2001:0db8:0001:0002:0000:0000:0000:00ff');
$r23sOne = CspReports::counts($r23sDb, $r23sStore, $r23sNow - 60);
assert_true($r23sOne['form_action'] === 1 && $r23sOne['form_action_reports'] === 3,
    'R23 CSP: form-action reports from addresses of one IPv6 /64 count as one reporter (were three: a critical alarm from one client)');
CspReports::ingest($r23sForged, $r23sDb, $r23sNow, '2001:db8:1:3::1');
assert_true(CspReports::counts($r23sDb, $r23sStore, $r23sNow - 60)['form_action'] === 2, 'R23 CSP: another /64 is another reporter');
$r23sDb->exec('DELETE FROM csp_violations');
CspReports::ingest($r23sForged, $r23sDb, $r23sNow, '198.51.100.4');
CspReports::ingest($r23sForged, $r23sDb, $r23sNow, '::ffff:198.51.100.4');
CspReports::ingest($r23sForged, $r23sDb, $r23sNow, '198.51.100.5');
assert_true(CspReports::counts($r23sDb, $r23sStore, $r23sNow - 60)['form_action'] === 2,
    'R23 CSP: IPv4 reporters still count per address, and an IPv4-mapped IPv6 address is that IPv4 address');
$r23sDb->exec('DELETE FROM csp_violations');

// 2. T15: secrets under pwd=, private_key and an Authorization header without a scheme reached the log.
$r23sMasked = Logger::redact(['detail' => 'connect pwd=Hunter2x; private_key=MIIEvQIBADANBgkqhk "private-key": "abc def" Authorization: 0123abcdTOKEN',
    'auth' => 'Authorization: Bearer [REDACTED]', 'pwd' => 'plain', 'private_key' => 'k']);
$r23sText = (string)json_encode($r23sMasked);
foreach (['Hunter2x', 'MIIEvQIBADANBgkqhk', 'abc def', '0123abcdTOKEN', 'plain', '"k"'] as $r23sSecret) {
    assert_true(!str_contains($r23sText, $r23sSecret), "R23 T15: the logger masks {$r23sSecret} (" . $r23sText . ')');
}
assert_equals('Authorization: Bearer [REDACTED]', $r23sMasked['auth'], 'R23 T15: an already masked Authorization header stays as it is');
assert_equals('Authorization: Basic [REDACTED]', Logger::redact(['detail' => 'Authorization: Basic dXNlcjpwYXNzd29yZA=='])['detail'], 'R23 T15: Basic credentials are masked once');

// 3. T20: a POS that signs with SHA-256 can refuse MD5-signed notifications.
require_once dirname(TEST_CLI_DIR) . '/adapters/contrib/PayUPayment.php';
$r23sKey = 'r23s-second-key';
$r23sBody = '{"order":{"orderId":"R23S","status":"COMPLETED"}}';
$r23sSign = static fn(PayUPayment $payu, string $algo, string $hash): bool
    => $payu->verifySignature($r23sBody, 'sender=checkout;signature=' . hash($hash, $r23sBody . $r23sKey) . ";algorithm={$algo};content=DOCUMENT");
$r23sDefault = new PayUPayment(['payu_second_key' => $r23sKey]);
$r23sStrict = new PayUPayment(['payu_second_key' => $r23sKey, 'payu_signature_algorithm' => 'sha256']);
assert_equals([true, true, false, true], [$r23sSign($r23sDefault, 'MD5', 'md5'), $r23sSign($r23sDefault, 'SHA-256', 'sha256'), $r23sSign($r23sStrict, 'MD5', 'md5'), $r23sSign($r23sStrict, 'SHA-256', 'sha256')],
    'R23 T20: payu_signature_algorithm = sha256 refuses MD5-signed notifications; by default both are accepted');
putenv('PAY_PAYU_SIGNATURE_ALGORITHM=SHA-256');
assert_true(!$r23sSign(new PayUPayment(['payu_second_key' => $r23sKey]), 'MD5', 'md5'), 'R23 T20: PAY_PAYU_SIGNATURE_ALGORITHM overrides the config');
putenv('PAY_PAYU_SIGNATURE_ALGORITHM');

$r23sReady = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r23sReady->exec('CREATE TABLE payment_methods (store_id TEXT, code TEXT, provider TEXT, active INTEGER, requires_online INTEGER)');
$r23sReady->exec("INSERT INTO payment_methods VALUES ('cz', 'payu', 'payu', 1, 1)");
$r23sPayuCfg = ['payu_pos_id' => '300746', 'payu_client_id' => '300746', 'payu_client_secret' => str_repeat('c', 32), 'payu_second_key' => str_repeat('d', 32), 'payu_env' => 'production'];
$r23sWarn = PaymentReadiness::checks($r23sReady, $r23sPayuCfg)[0];
$r23sPass = PaymentReadiness::checks($r23sReady, $r23sPayuCfg + ['payu_signature_algorithm' => 'sha256'])[0];
assert_true($r23sWarn[0] === 'warn' && str_contains($r23sWarn[2], 'MD5') && str_contains($r23sWarn[3], "payu_signature_algorithm = 'sha256'") && $r23sPass[0] === 'pass',
    'R23 T20: doctor warns while PayU still accepts MD5 signatures and passes once payu_signature_algorithm = sha256');
$r23sDocs = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/README.md') . (string)file_get_contents(dirname(TEST_CLI_DIR) . '/config.example.php');
assert_true(substr_count($r23sDocs, 'payu_signature_algorithm') >= 2 && str_contains($r23sDocs, 'PAY_PAYU_SIGNATURE_ALGORITHM'), 'R23 T20: README and config.example.php document the setting');
