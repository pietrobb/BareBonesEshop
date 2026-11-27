<?php
// Local Stripe mock: state in STATE file. Records every call.
$state = getenv('STRIPE_MOCK_STATE') ?: __DIR__ . '/../stripe-state.json';
$fp = fopen($state . '.lock', 'c'); flock($fp, LOCK_EX);
$st = is_file($state) ? json_decode(file_get_contents($state), true) : ['sessions' => [], 'refunds' => [], 'calls' => [], 'refund_mode' => 'succeeded'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); $m = $_SERVER['REQUEST_METHOD'];
parse_str(file_get_contents('php://input'), $post);
$st['calls'][] = [$m, $_SERVER['REQUEST_URI'], $post];
header('Content-Type: application/json');
$out = ['error' => ['message' => 'unknown ' . $path]]; $code = 404;
if ($m === 'POST' && $path === '/v1/checkout/sessions') {
    $id = 'cs_test_' . bin2hex(random_bytes(8)); $pi = 'pi_test_' . bin2hex(random_bytes(8));
    $st['sessions'][$id] = ['id' => $id, 'payment_intent' => $pi, 'post' => $post, 'status' => 'open'];
    $out = ['id' => $id, 'url' => 'http://127.0.0.1:18752/pay/' . $id]; $code = 200;
} elseif (preg_match('#^/v1/checkout/sessions/([^/]+)(/expire)?$#', $path, $mm)) {
    $s = $st['sessions'][$mm[1]] ?? null; $code = $s ? 200 : 404;
    $out = $s ? ['id' => $s['id'], 'status' => $s['status'], 'payment_status' => $s['status'] === 'complete' ? 'paid' : 'unpaid', 'payment_intent' => $s['payment_intent']] : $out;
} elseif ($path === '/v1/refunds' && $m === 'POST') {
    $id = 're_test_' . bin2hex(random_bytes(6));
    $r = ['id' => $id, 'amount' => (int)($post['amount'] ?? 0), 'payment_intent' => $post['payment_intent'] ?? '', 'metadata' => $post['metadata'] ?? [], 'status' => $st['refund_mode'] === 'fail_request' ? (str_starts_with((string)($post['metadata']['bbe_refund_key'] ?? ''), 'admin_refund_request') ? 'failed' : 'succeeded') : $st['refund_mode']];
    if ($st['refund_mode'] === 'error500') { $code = 500; $out = ['error' => ['message' => 'mock outage']]; }
    else { $st['refunds'][$id] = $r; $out = $r; $code = 200; }
} elseif ($path === '/v1/refunds' && $m === 'GET') {
    $data = array_values(array_filter($st['refunds'], fn($r) => ($_GET['payment_intent'] ?? null) === null || $r['payment_intent'] === $_GET['payment_intent']));
    $out = ['data' => $data, 'has_more' => false]; $code = 200;
} elseif (preg_match('#^/v1/payment_intents/([^/]+)#', $path, $mm)) {
    $out = ['id' => $mm[1], 'status' => 'succeeded']; $code = 200;
} elseif (preg_match('#^/pay/(cs_test_\w+)$#', $path, $mm)) {
    header('Content-Type: text/html');
    $s = $st['sessions'][$mm[1]] ?? [];
    echo '<html><body><h1>Mock Stripe</h1><p id="amt">' . htmlspecialchars(json_encode($s['post'] ?? [])) . '</p><a id="success" href="' . htmlspecialchars($s['post']['success_url'] ?? '') . '">Pay</a></body></html>';
    file_put_contents($state, json_encode($st)); exit;
}
file_put_contents($state, json_encode($st));
http_response_code($code); echo json_encode($out);
