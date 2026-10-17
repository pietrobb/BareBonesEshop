<?php

// Integrations review (2026-10-16): carrier rates, carrier webhooks, outgoing webhooks/outbox,
// extension endpoints and API actions, transactional/marketing mail and the SMTP/SES adapters,
// abandoned-cart consent, the Redis queue retention (AuditRedisQueueRegression) and digital downloads.
echo "\n--- Audit integrations: carriers, webhooks, outbox, extensions, mail, cart reminders, downloads ---\n";

$aigRoot = test_fixture_path('integrations');
mkdir($aigRoot, 0700, true);
JobQueue::setDir($aigRoot . '/jobs');
Bootstrap::initStoreContext('/', '', 'default');
$aigThrows = static function (callable $fn, string $class = 'Throwable'): ?Throwable {
    try { $fn(); } catch (Throwable $e) { return $e instanceof $class ? $e : null; }
    return null;
};

// ── 1/2. One failing carrier never takes rates down; adapters get the store currency ─────────────
$aigHttpCalls = [];
$aigHttpAnswer = ['status' => 503, 'body' => '{"error":{"code":"down"}}'];
JsonHttpClient::setFixtureTransport(static function (string $method, string $url, string $body, array $headers) use (&$aigHttpCalls, &$aigHttpAnswer): array {
    $aigHttpCalls[] = ['method' => $method, 'url' => $url, 'body' => json_decode($body, true)];
    return $aigHttpAnswer;
});
$aigHttp = new HttpShipping(['integrations' => ['http_shipping' => ['base_url' => 'https://carrier.example', 'api_key' => str_repeat('k', 24)]]]);
ShippingService::setAdapters(['http-carrier' => $aigHttp, 'pickup' => new PersonalPickup()]);
$aigRates = null;
$aigError = $aigThrows(static function () use (&$aigRates): void { $aigRates = ShippingService::calculateRates('IS', 1000, 2000); });
assert_true($aigError === null && is_array($aigRates), '1 rates: a carrier answering HTTP 503 does not throw out of calculateRates');
assert_true(in_array('pickup', array_column($aigRates ?? [], 'carrier'), true) && !in_array('http-carrier', array_column($aigRates ?? [], 'carrier'), true),
    '1 rates: the failing carrier is skipped, the other carriers are still offered');
assert_true((int)$pdo->query("SELECT COUNT(*) FROM operational_incidents WHERE code = 'shipping.rates_adapter_failed'")->fetchColumn() >= 1, '1 rates: the failing carrier raises a warning incident');
assert_equals('eur', $aigHttpCalls[0]['body']['currency'] ?? null, '2 currency: the HTTP carrier is asked in the store currency (default store EUR)');
Bootstrap::initStoreContext('/', '', 'cz');
$aigHttpCalls = [];
ShippingService::calculateRates('IS', 1000, 2000);
assert_equals('czk', $aigHttpCalls[0]['body']['currency'] ?? null, '2 currency: a CZK store sends its real currency to the HTTP carrier');
$aigLegacy = array_values(array_filter(ShippingService::calculateRates('IS', 1000, 2000), static fn(array $r): bool => ($r['provider'] ?? '') === 'legacy'));
assert_equals([], $aigLegacy, '2 currency: EUR shipping.json prices are not quoted to a CZK store');
Bootstrap::initStoreContext('/', '', 'default');
assert_true(array_filter(ShippingService::calculateRates('IS', 1000, 2000), static fn(array $r): bool => ($r['provider'] ?? '') === 'legacy') !== [], '2 currency: the EUR store still gets the shipping.json methods');
assert_equals('EUR', Shipping::currency(), '2 currency: shipping.json declares its currency');
ShippingService::setAdapters([]);
JsonHttpClient::setFixtureTransport(null);

$aigPacketa = new PacketaShipping(['private_cache_dir' => $aigRoot]);
$aigGls = new GlsShipping();
assert_equals(799, (int)($aigPacketa->getRates(['country' => 'HU', 'weight_g' => 1000, 'currency' => 'EUR'])[0]['price'] ?? -1), '2 currency: Packeta built-in EUR tier in an EUR store');
assert_equals([], $aigPacketa->getRates(['country' => 'HU', 'weight_g' => 1000, 'currency' => 'CZK']), '2 currency: Packeta has no CZK price without a store rate row');
assert_equals([], $aigPacketa->getRates(['country' => 'HU', 'weight_g' => 1000, 'subtotal' => 900000, 'currency' => 'HUF']), '2 currency: no "free above 50 EUR" in a HUF store either');
$pdo->exec("DELETE FROM shipping_rates WHERE carrier = 'gls' AND country = 'IE'");
assert_equals([], $aigGls->getRates(['country' => 'IE', 'weight_g' => 1000, 'currency' => 'PLN']), '2 currency: GLS has no PLN price without a store rate row');
assert_equals(699, (int)($aigGls->getRates(['country' => 'IE', 'weight_g' => 1000, 'currency' => 'EUR'])[0]['price'] ?? -1), '2 currency: GLS built-in EUR tier in an EUR store');
$pdo->exec("INSERT INTO shipping_rates (store_id, carrier, country, weight_from_g, weight_to_g, price, cod_surcharge) VALUES ('default', 'gls', 'IE', 0, 5000, 4500, 300)");
$aigGlsPln = $aigGls->getRates(['country' => 'IE', 'weight_g' => 1000, 'currency' => 'PLN', 'cod' => true])[0] ?? [];
assert_equals([4500, 300], [(int)($aigGlsPln['price'] ?? -1), (int)($aigGlsPln['cod_surcharge'] ?? -1)], '2 currency: a store rate row (store currency) is quoted with its own COD fee');
$pdo->exec("DELETE FROM shipping_rates WHERE carrier = 'gls' AND country = 'IE'");

// ── 3. Packeta: coverage before the free-shipping threshold ──────────────────────────────────────
assert_equals([], $aigPacketa->getRates(['country' => 'US', 'weight_g' => 500, 'subtotal' => 100000, 'currency' => 'EUR']), '3 Packeta: no free rate to a country Packeta does not serve');
assert_equals(0, (int)($aigPacketa->getRates(['country' => 'SK', 'weight_g' => 500, 'subtotal' => 100000, 'currency' => 'EUR'])[0]['price'] ?? -1), '3 Packeta: free above the threshold where it serves');

// ── 9/12. Carrier webhooks: "-" codes, env names, configuration errors, timestamps, status order ──
$aigApi = (string)file_get_contents(TEST_CLI_DIR . '/../src/Http/ApiHandlers.php');
assert_true(str_contains($aigApi, "preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', \$carrier)"), '9 webhook route: carrier codes may contain "-" (http-carrier)');
assert_true(str_contains($aigApi, 'catch (ShippingWebhookConfigurationError $e)') && str_contains($aigApi, "'shipping.webhook_misconfigured'"), '9 webhook route: misconfiguration is its own error with an incident, not "Invalid signature"');
assert_equals('SHIP_HTTP_CARRIER_WEBHOOK_SECRET', ShippingService::webhookSecretEnvName('http-carrier'), '9 webhook: env name writes "-" as "_"');
$aigSecret = 'aig-carrier-secret-0123456789';
$aigBody = json_encode(['awb' => 'AIG-NOPE', 'status' => 'in_transit']);
putenv('SHIP_HTTP_CARRIER_WEBHOOK_SECRET');
$aigError = $aigThrows(static fn() => ShippingService::handleTrackingWebhook('http-carrier', json_decode($aigBody, true), hash_hmac('sha256', $aigBody, $aigSecret), $aigBody));
assert_true($aigError instanceof ShippingWebhookConfigurationError, '9 webhook: a missing secret is a configuration error');
putenv('SHIP_HTTP_CARRIER_WEBHOOK_SECRET=' . $aigSecret);
assert_equals('unknown_awb', ShippingService::handleTrackingWebhook('http-carrier', json_decode($aigBody, true), hash_hmac('sha256', $aigBody, $aigSecret), $aigBody)['reason'] ?? null,
    '9 webhook: SHIP_HTTP_CARRIER_WEBHOOK_SECRET verifies the http-carrier signature');
$aigError = $aigThrows(static fn() => ShippingService::handleTrackingWebhook('http-carrier', json_decode($aigBody, true), 'bad', $aigBody));
assert_true($aigError !== null && !$aigError instanceof ShippingWebhookConfigurationError && str_contains($aigError->getMessage(), 'signature'), '9 webhook: a wrong signature stays "Invalid signature"');
$aigTs = (string)time();
assert_equals('unknown_awb', ShippingService::handleTrackingWebhook('http-carrier', json_decode($aigBody, true), hash_hmac('sha256', $aigTs . '.' . $aigBody, $aigSecret), $aigBody, $aigTs)['reason'] ?? null,
    '12 webhook: a signed timestamp header is accepted');
$aigOld = (string)(time() - 3600);
$aigError = $aigThrows(static fn() => ShippingService::handleTrackingWebhook('http-carrier', json_decode($aigBody, true), hash_hmac('sha256', $aigOld . '.' . $aigBody, $aigSecret), $aigBody, $aigOld));
assert_true($aigError !== null && str_contains($aigError->getMessage(), 'timestamp'), '12 webhook: a signed timestamp outside the tolerance is rejected (replay)');
$aigWeird = json_encode(['awb' => 'AIG-NOPE', 'status' => 'teleported']);
assert_equals('unknown_status', ShippingService::handleTrackingWebhook('http-carrier', json_decode($aigWeird, true), hash_hmac('sha256', $aigWeird, $aigSecret), $aigWeird)['reason'] ?? null,
    '12 webhook: unknown statuses are not stored');
$aigLabelOrder = (int)$pdo->query("SELECT id FROM orders WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO shipping_labels (order_id, store_id, carrier, awb, status, label_data) VALUES (?, 'default', 'http-carrier', 'AIG-AWB-1', 'created', '')")->execute([$aigLabelOrder]);
$aigHook = static function (string $status, string $nonce) use ($aigSecret): array {
    $raw = json_encode(['awb' => 'AIG-AWB-1', 'status' => $status, 'nonce' => $nonce]);
    return ShippingService::handleTrackingWebhook('http-carrier', json_decode($raw, true), hash_hmac('sha256', $raw, $aigSecret), $raw);
};
$aigLabelStatus = static fn(): string => (string)$pdo->query("SELECT status FROM shipping_labels WHERE awb = 'AIG-AWB-1'")->fetchColumn();
assert_true(($aigHook('out_for_delivery', 'a')['handled'] ?? false) === true && $aigLabelStatus() === 'out_for_delivery', '12 webhook: forward status is applied');
assert_equals('stale_status', $aigHook('in_transit', 'b')['reason'] ?? null, '12 webhook: a late in_transit after out_for_delivery is not applied');
assert_equals('out_for_delivery', $aigLabelStatus(), '12 webhook: the label status never moves backwards');
assert_true(($aigHook('failed', 'c')['handled'] ?? false) === true && ($aigHook('out_for_delivery', 'd')['handled'] ?? false) === true, '12 webhook: equal-rank statuses (failed attempt, new delivery round) alternate');
$pdo->exec("UPDATE shipping_labels SET status = 'cancelled' WHERE awb = 'AIG-AWB-1'");
assert_equals('stale_status', $aigHook('in_transit', 'e')['reason'] ?? null, '12 webhook: a cancelled label is not revived by a carrier event');
$pdo->exec("DELETE FROM shipping_labels WHERE awb = 'AIG-AWB-1'");
putenv('SHIP_HTTP_CARRIER_WEBHOOK_SECRET');

// ── 10. Legacy shipping: unknown method/country is unavailable; checkout only to offered countries ─
Shipping::setConfig(['methods' => ['standard' => ['rates' => ['eu' => ['base' => 500, 'per_kg' => 0]]]], 'zones' => ['eu' => ['countries' => ['SK']]]]);
assert_equals(-1, Shipping::calculate('nope', 'SK'), '10 legacy: unknown method is unavailable (-1), not free');
assert_equals(-1, Shipping::calculate('standard', 'US'), '10 legacy: a country outside every zone is unavailable (-1), not free');
assert_equals(500, Shipping::calculate('standard', 'SK'), '10 legacy: a served country keeps its price');
Shipping::setConfig([]);
assert_true(str_contains($aigApi, "!array_key_exists(\$country, ShippingService::destinationCountries())"), '10 checkout: physical orders are refused for countries the checkout does not offer');

// ── 11. In-store pickup code is random ───────────────────────────────────────────────────────────
$aigCodes = [(new PersonalPickup())->generateLabel(['id' => 42])['awb'], (new PersonalPickup())->generateLabel(['id' => 42])['awb']];
assert_true(preg_match('/^PU-[0-9A-F]{10}$/', $aigCodes[0]) === 1 && $aigCodes[0] !== $aigCodes[1] && $aigCodes[0] !== 'PU-' . strtoupper(substr(md5('42pickup'), 0, 8)),
    '11 pickup: the collection code is random (not md5 of the order ID)');

// ── 4/14. Outgoing webhooks: https only, per-endpoint failures, time-boxed sweep ─────────────────
$aigWhSecret = str_repeat('s', 40);
assert_equals(null, Webhook::endpointProblem('erp', ['url' => 'https://erp.example/hook', 'secret' => $aigWhSecret]), '4 webhook: https endpoint is valid');
assert_true(str_contains((string)Webhook::endpointProblem('erp', ['url' => 'http://erp.example/hook', 'secret' => $aigWhSecret]), 'https'), '4 webhook: plain http is refused');
assert_equals(null, Webhook::endpointProblem('dev', ['url' => 'http://localhost:9000/hook', 'secret' => $aigWhSecret, 'allow_insecure_http' => true]), '4 webhook: http only with the explicit allow_insecure_http opt-out');
assert_true(Webhook::endpointProblem('erp', ['url' => 'https://erp.example/hook', 'secret' => 'short']) !== null && Webhook::endpointProblem('ext:x', ['url' => 'https://erp.example/hook', 'secret' => $aigWhSecret]) !== null,
    '4 webhook: short secrets and codes outside [a-z0-9_-] are reported');
assert_true($aigThrows(static fn() => Webhook::deliver(['url' => 'http://erp.example/hook', 'secret' => $aigWhSecret], ['id' => str_repeat('a', 32)], static fn() => ['response_code' => 200]), InvalidArgumentException::class) !== null,
    '4 webhook: deliver() itself refuses http without the opt-out');
$aigSetupSource = (string)file_get_contents(TEST_CLI_DIR . '/../src/Setup.php');
assert_true(str_contains($aigSetupSource, "'provider.webhook_config'") && str_contains($aigSetupSource, 'Webhook::invalidEndpoints($config)'), '4 webhook: setup doctor reports invalid endpoints');

$pdo->exec("UPDATE event_outbox SET status = 'dead' WHERE status IN ('pending', 'retry')");
$aigEvents = [];
$pdo->beginTransaction();
foreach ([1, 2, 3] as $aigN) $aigEvents[] = Outbox::record('order.created', 'order', 990000 + $aigN, ['n' => $aigN]);
$pdo->commit();
$aigCalls = [];
$aigTransport = static function (string $url, string $body, array $headers) use (&$aigCalls): array {
    $aigCalls[] = $url;
    return str_contains($url, 'slow') ? ['response_code' => null, 'error' => 'Operation timed out', 'timed_out' => true] : ['response_code' => 204];
};
$aigCfg = ['outbound_webhooks' => [
    'broken' => ['url' => 'http://insecure.example/hook', 'secret' => $aigWhSecret, 'events' => ['order.*']],
    'good' => ['url' => 'https://good.example/hook', 'secret' => $aigWhSecret, 'events' => ['order.*']],
    'slow' => ['url' => 'https://slow.example/hook', 'secret' => $aigWhSecret, 'events' => ['order.*']],
]];
$aigRun = OutboxDispatcher::run($aigCfg, false, 20, $aigTransport);
$aigDelivery = static fn(string $event, string $endpoint): string => (string)$pdo->query("SELECT status FROM webhook_deliveries WHERE event_id = " . $pdo->quote($event) . " AND endpoint_code = " . $pdo->quote($endpoint) . " ORDER BY id DESC LIMIT 1")->fetchColumn();
assert_equals(3, $aigRun['events'], '4 outbox: every event is processed although one endpoint is misconfigured');
assert_true($aigDelivery($aigEvents[0], 'broken') === 'failed' && $aigDelivery($aigEvents[2], 'good') === 'delivered',
    '4 outbox: the misconfigured endpoint gets a failed audit row and the next endpoints still receive the event');
assert_true(str_contains((string)$pdo->query("SELECT error FROM webhook_deliveries WHERE endpoint_code = 'broken' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'invalid endpoint configuration'), '4 outbox: the audit row says why');
assert_equals('retry', (string)$pdo->query("SELECT status FROM event_outbox WHERE id = " . $pdo->quote($aigEvents[0]))->fetchColumn(), '4 outbox: the event is retried for the failed endpoint only');
assert_equals(2, count(array_filter($aigCalls, static fn(string $u): bool => str_contains($u, 'slow'))), '14 outbox: an endpoint that timed out twice is skipped for the rest of the sweep');
assert_equals(['slow'], $aigRun['skipped_endpoints'], '14 outbox: the sweep reports the skipped endpoint');
$pdo->exec("UPDATE event_outbox SET status = 'pending', available_at = '2000-01-01 00:00:00' WHERE id IN (" . implode(',', array_map([$pdo, 'quote'], $aigEvents)) . ")");
$aigClock = 0.0;
$aigRun = OutboxDispatcher::run($aigCfg, false, 20, $aigTransport, static function () use (&$aigClock): float { $aigClock += 15.0; return $aigClock; });
assert_true($aigRun['budget_exhausted'] && $aigRun['events'] < 3, '14 outbox: the sweep stops claiming events once its time budget is spent');
$pdo->exec("UPDATE event_outbox SET status = 'dead' WHERE id IN (" . implode(',', array_map([$pdo, 'quote'], $aigEvents)) . ")");
$aigWorker = (string)file_get_contents(TEST_CLI_DIR . '/worker.php');
assert_true(str_contains($aigWorker, 'OutboxDispatcher::run($cfg, $verbose)'), '4/14 worker: the outbox sweep runs through OutboxDispatcher');

// ── 13. Stock digest for every store ─────────────────────────────────────────────────────────────
assert_true((bool)preg_match('/foreach \(Bootstrap::stores\(\)\[\'stores\'\] \?\? \[\] as \$digestStoreCode => \$_\) \{.*?Bootstrap::initStoreContext\(\'\/\', \'\', \(string\)\$digestStoreCode\);\s*StockAlerts::digest\(\);/s', $aigWorker),
    '13 worker: the low-stock digest runs in every store');

// ── 16/17. Extension listener endpoints and API actions ──────────────────────────────────────────
assert_true(ExtensionApi::listenerEndpoint('acme-a', 'b') !== ExtensionApi::listenerEndpoint('acme', 'a-b'), '16 extensions: "<code>:<name>" endpoints cannot collide');
assert_equals('ext:acme:points', ExtensionApi::listenerEndpoint('acme', 'points'), '16 extensions: listener endpoint format');
Outbox::recordDelivery($aigEvents[0], 'ext:acme:points', 99, true, null, null);
assert_true(Outbox::endpointDelivered($aigEvents[0], 'ext:acme:points'), '16 extensions: the endpoint is accepted by the delivery audit');
$aigExtDir = $aigRoot . '/extensions';
mkdir($aigExtDir . '/aig-ext', 0700, true);
file_put_contents($aigExtDir . '/aig-ext/extension.php', <<<'PHP'
<?php
return ['code' => 'aig-ext', 'name' => 'AIG', 'version' => '1', 'register' => static function (ExtensionApi $api): void {
    $api->api('read', static fn(array $q): array => ['ok' => 'read']);
    $api->api('write', static fn(array $q): array => ['ok' => 'write'], ['method' => 'POST']);
    $api->api('hook', static fn(array $q): array => ['ok' => 'hook'], ['method' => 'POST', 'csrf' => false]);
}];
PHP);
Extensions::reset();
Extensions::boot(['extensions' => ['aig-ext']], $aigExtDir);
assert_equals([], Extensions::errors(), '17 extensions: fixture extension loads');
$aigSession = $_SESSION ?? [];
$aigServer = $_SERVER;
$_SESSION['csrf_token'] = str_repeat('c', 64);
$aigCall = static function (string $action, string $method, string $token = ''): array {
    $_SERVER['REQUEST_METHOD'] = $method;
    if ($token !== '') $_SERVER['HTTP_X_CSRF_TOKEN'] = $token; else unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    http_response_code(200);
    $result = Extensions::api($action) ?? [];
    return [http_response_code(), $result['ok'] ?? $result['error'] ?? ''];
};
assert_equals([200, 'read'], $aigCall('aig-ext.read', 'GET'), '17 extensions: GET action without CSRF works');
assert_equals(403, $aigCall('aig-ext.read', 'POST')[0], '17 extensions: a non-GET request to an action needs the CSRF token by default');
assert_equals([200, 'read'], $aigCall('aig-ext.read', 'POST', str_repeat('c', 64)), '17 extensions: ... and passes with it');
assert_equals(405, $aigCall('aig-ext.write', 'GET')[0], '17 extensions: a POST-declared action refuses GET');
assert_equals(403, $aigCall('aig-ext.write', 'POST', 'wrong')[0], '17 extensions: a POST-declared action enforces CSRF');
assert_equals([200, 'write'], $aigCall('aig-ext.write', 'POST', str_repeat('c', 64)), '17 extensions: POST with the token runs the action');
assert_equals([200, 'hook'], $aigCall('aig-ext.hook', 'POST'), '17 extensions: csrf => false is an explicit per-action opt-out');
$_SESSION = $aigSession;
$_SERVER = $aigServer;
http_response_code(200);
Extensions::reset();
assert_true(str_contains((string)file_get_contents(TEST_CLI_DIR . '/../docs/EXTENSIONS.md'), "'method' => 'POST'"), '17 extensions: method/CSRF options are documented');

// ── 5. Mail: single-pass rendering, per-order limit, retryable rate limit, unsubscribe headers ───
$aigRendered = MailService::renderString('<p>{{customer_name}} {{order_number}}</p>{{#each items}}<i>{{name}}</i>{{/each}}',
    ['customer_name' => 'Eve {{reset_url}}', 'order_number' => 'A1', 'reset_url' => 'https://secret.example/t', 'items' => [['name' => 'Box {{order_number}}']]]);
assert_true(!str_contains($aigRendered, 'secret.example') && str_contains($aigRendered, 'Eve {{reset_url}}') && str_contains($aigRendered, '<i>Box {{order_number}}</i>'),
    '5 mail: placeholders inside user data are output literally, never expanded');
assert_equals('Hi Ann, , 3', MailService::renderString('Hi {{name}}, {{missing}}, {{n}}', ['name' => 'Ann', 'n' => 3], false), '5 mail: unknown placeholders still render empty');
MailService::useAdapter(new LogMail(['log_file' => $aigRoot . '/mail.log']));
$pdo->exec("DELETE FROM email_log WHERE recipient = 'b2b-buyer@example.test'");
for ($aigI = 0; $aigI < 10; $aigI++) MailService::send('order_confirmation', 'b2b-buyer@example.test', 'en', ['order_number' => 'B' . $aigI], 880000 + $aigI);
assert_equals('sent', MailService::send('order_confirmation', 'b2b-buyer@example.test', 'en', ['order_number' => 'B11'], 880011)['status'], '5 mail: the 11th order of one buyer in an hour still gets its confirmation (limit is per order)');
for ($aigI = 0; $aigI < 9; $aigI++) MailService::send('order_confirmation', 'b2b-buyer@example.test', 'en', ['order_number' => 'B0'], 880000);
$aigLimited = MailService::send('order_confirmation', 'b2b-buyer@example.test', 'en', ['order_number' => 'B0'], 880000);
assert_true($aigLimited['status'] === 'rate_limited' && (int)($aigLimited['retry_after'] ?? 0) > 3600, '5 mail: a spent budget is "rate_limited" with a retry delay longer than the window');
$aigQueueFiles = static fn(): array => glob($aigRoot . '/jobs/pending/*.json') ?: [];
array_map('unlink', $aigQueueFiles());
assert_true(MailService::deferRateLimited(['template' => 'order_confirmation', 'order_id' => 880000, 'to' => 'x@example.test'], $aigLimited), '5 mail: a rate-limited order mail is queued again');
$aigDeferred = json_decode((string)file_get_contents($aigQueueFiles()[0] ?? '/dev/null'), true) ?: [];
assert_true(($aigDeferred['payload']['_rate_limit_retries'] ?? 0) === 1 && strtotime(($aigDeferred['retry_after'] ?? '') . ' UTC') > time() + 3600 && JobQueue::pull(10) === [],
    '5 mail: ... after the limit window (not pulled before)');
assert_true(!MailService::deferRateLimited(['template' => 'order_confirmation', 'order_id' => 880000, '_rate_limit_retries' => 3], $aigLimited)
    && !MailService::deferRateLimited(['template' => 'cart_reminder', 'to' => 'x@example.test'], $aigLimited), '5 mail: retries are capped, cart reminders are not retried');
array_map('unlink', $aigQueueFiles());
assert_true(str_contains($aigWorker, "'rate_limited') MailService::deferRateLimited(\$payload, \$result)"), '5 worker: email_send retries rate-limited mail instead of completing it');
LogMail::clearCaptured();
MailService::send('cart_reminder', 'aig-reminder@example.test', 'en', ['cart_url' => 'https://shop.example/?cart_recover=x', 'unsubscribe_url' => 'https://shop.example/?cart_unsubscribe=abc', 'cart_total' => '1 €', 'items' => []]);
$aigHeaders = (array)(LogMail::lastCaptured()['headers'] ?? []);
assert_equals(['<https://shop.example/?cart_unsubscribe=abc>', 'List-Unsubscribe=One-Click'], [$aigHeaders['List-Unsubscribe'] ?? null, $aigHeaders['List-Unsubscribe-Post'] ?? null], '6 mail: marketing mail carries List-Unsubscribe and one-click List-Unsubscribe-Post');
MailService::send('order_paid', 'aig-reminder@example.test', 'en', ['order_number' => 'X', 'unsubscribe_url' => 'https://shop.example/?x=1'], 870001);
assert_true(!isset(LogMail::lastCaptured()['headers']['List-Unsubscribe']), '6 mail: transactional mail has no unsubscribe header');
MailService::useAdapter(null);

// ── 8/15. MIME, SES raw sending, SMTP hardening ──────────────────────────────────────────────────
$aigMime = MimeMessage::build(['to' => 'a@example.test', 'from' => 'shop@example.test', 'subject' => 'S', 'text' => 'T', 'html' => '<p>T</p>',
    'attachments' => [['filename' => "evil\"\r\nX-Injected: 1.pdf", 'data' => 'PDF'], ['filename' => 'Faktúra č. 1.pdf', 'data' => 'PDF2']]]);
assert_true(!preg_match('/^X-Injected:/m', $aigMime) && str_contains($aigMime, 'filename="evil\\"X-Injected: 1.pdf"'), '15 mime: quotes are escaped and CR/LF removed in attachment names');
assert_true(str_contains($aigMime, "filename*=UTF-8''Fakt%C3%BAra%20%C4%8D.%201.pdf"), '15 mime: non-ASCII attachment names use RFC 2231');
$aigSes = [];
$aigSesEnv = [];
foreach (['MAIL_SES_KEY' => 'AKIAEXAMPLE', 'MAIL_SES_SECRET' => 'secret', 'MAIL_SES_REGION' => 'eu-central-1'] as $aigVar => $aigValue) {
    $aigSesEnv[$aigVar] = getenv($aigVar);
    putenv($aigVar . '=' . $aigValue);
}
$aigSesMail = new SesMail(['mail_ses_key' => 'AKIAEXAMPLE', 'mail_ses_secret' => 'secret', 'mail_ses_region' => 'eu-central-1'], static function (string $url, string $headers, string $body) use (&$aigSes): string {
    parse_str($body, $aigSes);
    return '<SendRawEmailResponse><SendRawEmailResult><MessageId>aig-1</MessageId></SendRawEmailResult></SendRawEmailResponse>';
});
$aigSesResult = $aigSesMail->send(['to' => 'buyer@example.test', 'from' => 'shop@example.test', 'subject' => 'Invoice', 'text' => 'see PDF', 'html' => '<p>see PDF</p>',
    'headers' => ['List-Unsubscribe' => '<https://shop.example/u>'], 'attachments' => [['filename' => 'INV-1.pdf', 'content_type' => 'application/pdf', 'data' => '%PDF-1.4 aig']]]);
foreach ($aigSesEnv as $aigVar => $aigValue) putenv($aigValue === false ? $aigVar : $aigVar . '=' . $aigValue);
$aigRaw = base64_decode((string)($aigSes['RawMessage_Data'] ?? $aigSes['RawMessage.Data'] ?? ''));
assert_true($aigSesResult['status'] === 'sent' && ($aigSes['Action'] ?? '') === 'SendRawEmail', '8 ses: messages go out through SendRawEmail');
assert_true(str_contains($aigRaw, 'filename="INV-1.pdf"') && str_contains($aigRaw, base64_encode('%PDF-1.4 aig')) && str_contains($aigRaw, 'List-Unsubscribe: <https://shop.example/u>'),
    '8 ses: the attachment and custom headers are in the raw MIME message (no longer dropped)');
$aigPlain = (new SmtpMail(['smtp_host' => '127.0.0.1', 'smtp_port' => 1, 'smtp_user' => 'u', 'smtp_pass' => 'p', 'smtp_encryption' => 'none']))
    ->send(['to' => 'a@example.test', 'from' => 'shop@example.test', 'subject' => 's', 'text' => 't']);
assert_true($aigPlain['status'] === 'failed' && str_contains($aigPlain['message'], 'unencrypted'), '15 smtp: AUTH over plaintext is refused before connecting');
file_put_contents($aigRoot . '/smtp.php', <<<'PHP'
<?php
[$_, $portFile, $logFile, $mode] = $argv;
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
file_put_contents($portFile, (string)parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT));
$conn = stream_socket_accept($server, 20);
if (!$conn) exit(1);
if ($mode === 'hang') { sleep(4); exit(0); }
fwrite($conn, "220 aig ESMTP\r\n");
while (($line = fgets($conn)) !== false) {
    file_put_contents($logFile, $line, FILE_APPEND);
    $verb = strtoupper(substr(trim($line), 0, 4));
    if (in_array($verb, ['EHLO', 'MAIL', 'RCPT', 'AUTH'], true)) { fwrite($conn, $verb === 'AUTH' ? "334 VXNlcm5hbWU6\r\n" : "250 ok\r\n"); continue; }
    if ($verb === 'QUIT') { fwrite($conn, "221 bye\r\n"); break; }
    if ($verb === 'DATA') {
        fwrite($conn, "354 go\r\n");
        while (($chunk = fgets($conn)) !== false && $chunk !== ".\r\n") {}
        fwrite($conn, "250 queued\r\n");
    }
}
PHP);
$aigSmtp = static function (string $mode, array $cfg) use ($aigRoot): array {
    $portFile = $aigRoot . '/smtp-' . $mode . '.port';
    $log = $aigRoot . '/smtp-' . $mode . '.log';
    $proc = proc_open([PHP_BINARY, $aigRoot . '/smtp.php', $portFile, $log, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    for ($i = 0; $i < 100 && !is_file($portFile); $i++) usleep(50000);
    $started = microtime(true);
    $result = (new SmtpMail($cfg + ['smtp_host' => '127.0.0.1', 'smtp_port' => (int)@file_get_contents($portFile), 'smtp_encryption' => 'none']))
        ->send(['to' => 'buyer@example.test', 'from' => 'shop@example.test', 'subject' => 's', 'text' => 't']);
    $elapsed = microtime(true) - $started;
    fclose($pipes[0]);
    proc_terminate($proc);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return [$result, (string)@file_get_contents($log), $elapsed];
};
[$aigSmtpResult, $aigSmtpLog] = $aigSmtp('ok', ['smtp_helo' => 'mail.shop.example']);
assert_true($aigSmtpResult['status'] === 'sent' && str_contains($aigSmtpLog, "EHLO mail.shop.example\r\n") && !str_contains($aigSmtpLog, 'EHLO 127.0.0.1'),
    '15 smtp: EHLO names this client (SMTP_HELO), not the server');
[$aigSmtpResult, , $aigElapsed] = $aigSmtp('hang', ['smtp_timeout' => 1]);
assert_true($aigSmtpResult['status'] === 'failed' && $aigElapsed < 3.5, '15 smtp: a silent server times out after SMTP_TIMEOUT instead of hanging the worker');

// ── 6. Abandoned carts: consent, store scope, store links ────────────────────────────────────────
$pdo->exec("DELETE FROM abandoned_carts");
$aigProduct = (int)$pdo->query("SELECT id FROM products WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
Cart::clear();
Cart::add($aigProduct, null, 1);
AbandonedCarts::capture('noconsent@example.test', 'aig-cart-1');
assert_equals(0, (int)$pdo->query("SELECT COUNT(*) FROM abandoned_carts")->fetchColumn(), '6 carts: without reminder consent no cart/e-mail is stored');
AbandonedCarts::capture('consent@example.test', 'aig-cart-1', ['version' => 'v7', 'text' => 'Remind me once']);
$aigCart = $pdo->query("SELECT * FROM abandoned_carts WHERE session_id = 'aig-cart-1'")->fetch(PDO::FETCH_ASSOC) ?: [];
assert_true(($aigCart['reminder_consent_version'] ?? '') === 'v7' && ($aigCart['reminder_consent_text'] ?? '') === 'Remind me once' && ($aigCart['reminder_consent_at'] ?? null) !== null,
    '6 carts: consent version, text and time are recorded with the cart');
AbandonedCarts::capture('consent@example.test', 'aig-cart-1');
$aigCart = $pdo->query("SELECT * FROM abandoned_carts WHERE session_id = 'aig-cart-1'")->fetch(PDO::FETCH_ASSOC) ?: [];
assert_true(($aigCart['customer_email'] ?? null) === null && ($aigCart['reminder_consent_at'] ?? null) === null, '6 carts: unticking the consent on a later submit withdraws it');
AbandonedCarts::capture('consent@example.test', 'aig-cart-1', ['version' => 'v7', 'text' => 'Remind me once']);
$pdo->exec("INSERT INTO abandoned_carts (session_id, customer_email, cart_data, store_id, recovery_token, cart_value, item_count) VALUES ('aig-legacy', 'legacy@example.test', '[]', 'default', '" . str_repeat('b', 32) . "', 100, 1)");
$pdo->prepare("INSERT INTO abandoned_carts (session_id, customer_email, cart_data, store_id, recovery_token, cart_value, item_count, reminder_consent_version, reminder_consent_at) VALUES ('aig-cz', 'cz@example.test', '[]', 'cz', ?, 100, 1, 'v7', datetime('now'))")
    ->execute([str_repeat('c', 32)]);
$pdo->exec("UPDATE abandoned_carts SET created_at = datetime('now', '-30 hours'), updated_at = datetime('now', '-30 hours')");
array_map('unlink', $aigQueueFiles());
assert_equals(2, AbandonedCarts::sweep(['base_url' => 'https://shop.example']), '6 carts: only carts with recorded consent are reminded');
$aigReminders = [];
foreach (JobQueue::pull(50) as $aigJob) if (($aigJob['payload']['template'] ?? '') === 'cart_reminder') $aigReminders[(string)$aigJob['payload']['to']] = $aigJob['payload']['vars'];
assert_true(!isset($aigReminders['legacy@example.test']), '6 carts: a cart captured before consent was required is not reminded');
assert_equals('https://shop.example/cs/?cart_recover=' . str_repeat('c', 32), (string)($aigReminders['cz@example.test']['cart_url'] ?? ''), '6 carts: reminder links use the cart store prefix (/cs)');
assert_equals('https://shop.example/?cart_unsubscribe=' . (string)$aigCart['recovery_token'], (string)($aigReminders['consent@example.test']['unsubscribe_url'] ?? ''), '6 carts: default-store links keep the app_url');
assert_equals('https://cz-shop.example/base/cs', (static function (): string {
    $stores = Bootstrap::stores();
    $prop = new ReflectionProperty(Bootstrap::class, 'stores');
    $saved = $prop->getValue();
    $stores['stores']['cz']['domains'] = ['cz-shop.example'];
    $prop->setValue(null, $stores);
    try { return AbandonedCarts::storeBaseUrl('cz', 'https://shop.example/base'); } finally { $prop->setValue(null, $saved); }
})(), '6 carts: a store with its own domain gets links on that domain');
$aigListed = array_column(AbandonedCarts::list('all'), 'session_id');
assert_true(in_array('aig-cart-1', $aigListed, true) && !in_array('aig-cz', $aigListed, true), '6 carts: the admin list shows only the current store');
Bootstrap::initStoreContext('/', '', 'cz');
assert_equals(['aig-cz'], array_column(AbandonedCarts::list('all'), 'session_id'), '6 carts: ... and the other store sees its own');
Bootstrap::initStoreContext('/', '', 'default');
$pdo->exec("DELETE FROM abandoned_carts");
array_map('unlink', $aigQueueFiles());
Cart::clear();
assert_true(str_contains((string)file_get_contents(TEST_CLI_DIR . '/../config.example.php'), "'cart_reminder' => ['enabled' => false")
    && str_contains($aigWorker, "(\$reminderCfg['enabled'] ?? false) === true"), '6 carts: reminders are off by default');
assert_true(str_contains($aigApi, 'Cart::setEmail($email, CheckoutService::cartReminderConsent($input, $country))'), '6 checkout: the cart e-mail is only captured with the reminder consent');
ConsentService::setDefinitionsForTesting(['cart_reminder' => ['version' => 'v9', 'text' => 'One reminder please', 'required' => false]]);
assert_equals(['version' => 'v9', 'text' => 'One reminder please'], CheckoutService::cartReminderConsent(['consents' => ['cart_reminder' => true]], 'SK'), '6 checkout: a ticked cart_reminder consent is passed on');
assert_equals(null, CheckoutService::cartReminderConsent(['consents' => ['cart_reminder' => '1']], 'SK'), '6 checkout: anything but an explicit true is no consent');
ConsentService::setDefinitionsForTesting(null);

// ── 18. Digital downloads: Range, counting once, resume window, single fulfilment ────────────────
assert_equals([[0, 99], [100, 999], [900, 999], false, null, null, [10, 999]], [
    DigitalDelivery::parseRange('bytes=0-99', 1000), DigitalDelivery::parseRange('bytes=100-', 1000), DigitalDelivery::parseRange('bytes=-100', 1000),
    DigitalDelivery::parseRange('bytes=1000-', 1000), DigitalDelivery::parseRange('bytes=0-1,5-9', 1000), DigitalDelivery::parseRange('', 1000),
    DigitalDelivery::parseRange('bytes=10-5000', 1000),
], '18 download: single byte ranges parse (suffix, open end, clamp); several ranges serve the file; past the end is 416');
$aigPaidOrder = (int)$pdo->query("SELECT id FROM orders WHERE payment_status = 'paid' AND status NOT IN ('cancelled', 'refunded') ORDER BY id LIMIT 1")->fetchColumn();
$pdo->exec('PRAGMA foreign_keys = OFF');
$pdo->prepare("INSERT INTO digital_entitlements (order_id, order_item_id, asset_id, token_hash, status, download_limit) VALUES (?, 0, 0, ?, 'active', 1)")
    ->execute([$aigPaidOrder, hash('sha256', 'aig-download')]);
$pdo->exec('PRAGMA foreign_keys = ON');
$aigEntId = (int)$pdo->lastInsertId();
$aigEnt = static fn(): array => $pdo->query("SELECT * FROM digital_entitlements WHERE id = {$aigEntId}")->fetch(PDO::FETCH_ASSOC) ?: [];
$aigNow = time();
$aigCounted = DigitalDelivery::beginTransfer($aigEnt(), 0, $aigNow);
assert_true($aigPaidOrder > 0 && $aigCounted === $aigNow, '18 download: the first transfer from byte 0 counts the download');
assert_equals(1, (int)$aigEnt()['download_count'], '18 download: one download counted');
assert_true(!DigitalDelivery::authorizeTransfer($aigEnt(), 1, $aigNow + 30), '18 download: "Range: bytes=1-" before the counted download served anything is refused once the limit is spent');
DigitalDelivery::recordProgress($aigEntId, $aigCounted, 8192);
DigitalDelivery::recordProgress($aigEntId, $aigCounted, 4000);
assert_equals(8192, (int)$aigEnt()['last_download_offset'], '18 download: the bytes served by the counted download are recorded (only ever growing)');
assert_true(DigitalDelivery::authorizeTransfer($aigEnt(), 8192, $aigNow + 60) && (int)$aigEnt()['download_count'] === 1, '18 download: a legit resume at the bytes already served is not counted again (limit 1 already used)');
assert_true(!DigitalDelivery::authorizeTransfer($aigEnt(), 8193, $aigNow + 60), '18 download: a range starting past the bytes the counted download served is refused (a new download, limit spent)');
assert_true(!DigitalDelivery::authorizeTransfer($aigEnt(), 0, $aigNow + 60), '18 download: a new download from byte 0 is refused once the limit is used');
// A long partial transfer: "bytes=1-" is no resume (it would be a free near-complete copy), a resume near the cut is.
$pdo->exec("UPDATE digital_entitlements SET last_download_offset = " . (64 * 1024 * 1024) . " WHERE id = {$aigEntId}");
assert_true(!DigitalDelivery::authorizeTransfer($aigEnt(), 1, $aigNow + 60), '18 download: after 64 MiB served, "Range: bytes=1-" is not a free resume (limit spent, refused)');
assert_true(DigitalDelivery::authorizeTransfer($aigEnt(), 64 * 1024 * 1024 - 1024 * 1024, $aigNow + 60), '18 download: a resume a little below the served bytes (data in flight) is free');
$pdo->exec("UPDATE digital_entitlements SET last_download_offset = 8192, resume_count = 1 WHERE id = {$aigEntId}");
for ($aigResume = 2; $aigResume <= DigitalDelivery::MAX_FREE_RESUMES; $aigResume++) DigitalDelivery::authorizeTransfer($aigEnt(), 4096, $aigNow + 60);
assert_equals(DigitalDelivery::MAX_FREE_RESUMES, (int)$aigEnt()['resume_count'], '18 download: each free resume is counted');
assert_true(!DigitalDelivery::authorizeTransfer($aigEnt(), 4096, $aigNow + 60), '18 download: free resumes are capped');
$pdo->exec("UPDATE digital_entitlements SET resume_count = 0 WHERE id = {$aigEntId}");
assert_true(!DigitalDelivery::authorizeTransfer($aigEnt(), 4096, $aigNow + 3600), '18 download: after the resume window a resume counts as a new download (and the limit stops it)');
$pdo->exec("UPDATE digital_entitlements SET download_limit = 3 WHERE id = {$aigEntId}");
$aigRecount = DigitalDelivery::beginTransfer($aigEnt(), 1, $aigNow + 3600);
assert_true($aigRecount === $aigNow + 3600 && (int)$aigEnt()['download_count'] === 2 && (int)$aigEnt()['last_download_offset'] === 0 && (int)$aigEnt()['resume_count'] === 0,
    '18 download: with downloads left, a range that is no resume counts a new download and starts its own resume bound');
$pdo->exec("DELETE FROM digital_entitlements WHERE id = {$aigEntId}");
$aigDownload = (string)file_get_contents(TEST_CLI_DIR . '/../download.php');
assert_true(str_contains($aigDownload, 'http_response_code(206)') && str_contains($aigDownload, "header('Accept-Ranges: bytes')") && str_contains($aigDownload, 'http_response_code(416)'),
    '18 download: the endpoint answers ranges with 206 / 416 and advertises Accept-Ranges');
$aigClaim = DigitalDelivery::claimFulfilment(770001);
assert_true($aigClaim !== null && DigitalDelivery::claimFulfilment(770001) === null, '18 fulfil: a second concurrent fulfilment of one order is refused');
DigitalDelivery::releaseFulfilment((string)$aigClaim);
$aigClaim = DigitalDelivery::claimFulfilment(770001);
assert_true($aigClaim !== null, '18 fulfil: released after the run, so a later fulfilment can claim it');
DigitalDelivery::releaseFulfilment((string)$aigClaim);
assert_true((bool)preg_match('/function handleDigitalFulfill\(.*?DigitalDelivery::claimFulfilment\(\$orderId\).*?finally \{\s*DigitalDelivery::releaseFulfilment\(\$claim\);/s', $aigWorker), '18 worker: digital_fulfill runs under the per-order claim');
$aigClaim = (string)DigitalDelivery::claimFulfilment(770002);
$aigRenewed = DigitalDelivery::renewFulfilment($aigClaim);
assert_true($aigRenewed !== null && $aigRenewed !== $aigClaim && DigitalDelivery::renewFulfilment($aigClaim) === null, '18 fulfil: renewing gives a fresh claim token; the old token can no longer renew');
DigitalDelivery::releaseFulfilment($aigClaim);
assert_true(DigitalDelivery::claimFulfilment(770002) === null, '18 fulfil: a stale claim token cannot release the current claim');
$pdo->prepare("UPDATE processed_events SET claimed_at = ? WHERE event_id = 'digital_fulfill_770002'")->execute([(time() - DigitalDelivery::FULFIL_CLAIM_SECONDS - 5) * 1000000 + 7]);
$aigTakeover = DigitalDelivery::claimFulfilment(770002);
assert_true($aigTakeover !== null && DigitalDelivery::renewFulfilment((string)$aigRenewed) === null, '18 fulfil: a lapsed claim is taken over in the same second without the old holder renewing it');
DigitalDelivery::releaseFulfilment((string)$aigRenewed);
assert_true(DigitalDelivery::claimFulfilment(770002) === null, '18 fulfil: the old holder cannot release the new holder\'s claim');
DigitalDelivery::releaseFulfilment((string)$aigTakeover);
assert_true(DigitalDelivery::claimFulfilment(770002) !== null, '18 fulfil: the holder releases its own claim');
$pdo->exec("DELETE FROM processed_events WHERE event_id = 'digital_fulfill_770002'");
assert_true((bool)preg_match('/function fulfilDigitalOrder\(int \$orderId, \?string &\$claim = null\).*?DigitalDelivery::renewFulfilment\(\$claim\).*?MailService::send\(\'digital_delivery\'/s', $aigWorker)
    && DigitalDelivery::FULFIL_CLAIM_SECONDS >= 300, '18 worker: the claim is renewed right before the delivery mail and its lease outlasts a slow mail');

// Review fix 5: before the new migrations ran, downloads and invoice numbering degrade instead of failing.
$aigLegacy = new PDO('sqlite::memory:');
$aigLegacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$aigLegacy->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$aigLegacy->exec("CREATE TABLE orders (id INTEGER PRIMARY KEY, status TEXT, payment_status TEXT)");
$aigLegacy->exec("CREATE TABLE digital_entitlements (id INTEGER PRIMARY KEY, order_id INTEGER, status TEXT, download_count INTEGER NOT NULL DEFAULT 0,
    download_limit INTEGER, expires_at INTEGER, updated_at TEXT)");
$aigLegacy->exec("INSERT INTO orders VALUES (1, 'paid', 'paid')");
$aigLegacy->exec("INSERT INTO digital_entitlements (id, order_id, status, download_limit) VALUES (1, 1, 'active', 2)");
$aigLegacy->exec("CREATE TABLE invoice_counters (year INTEGER NOT NULL, prefix TEXT NOT NULL DEFAULT '', last_number INTEGER NOT NULL DEFAULT 0, type TEXT NOT NULL DEFAULT 'invoice', PRIMARY KEY (year, prefix))");
$aigLegacy->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, store_id TEXT, number TEXT, type TEXT)");
$aigAllocate = new ReflectionMethod(InvoiceService::class, 'allocateNumber');
$aigSuiteDb = Db::get();
$aigSuiteDriver = Db::driver();
Db::init($aigLegacy, 'sqlite');
try {
    $aigLegacyEnt = $aigLegacy->query('SELECT * FROM digital_entitlements WHERE id = 1')->fetch();
    $aigLegacyResults = [DigitalDelivery::authorizeTransfer($aigLegacyEnt, 0), DigitalDelivery::authorizeTransfer($aigLegacyEnt, 100), (int)$aigLegacy->query('SELECT download_count FROM digital_entitlements')->fetchColumn()];
    $aigLegacyNumbers = [$aigAllocate->invoke(null, $aigLegacy, 'default', 'invoice', 2026, ''), $aigAllocate->invoke(null, $aigLegacy, 'default', 'invoice', 2026, '')];
    $aigLegacy->exec("INSERT INTO invoices (store_id, number, type) VALUES ('default', '20260001', 'invoice'), ('pl', '20260002', 'invoice')");
    (require TEST_CLI_DIR . '/../migrations/20261016140500_invoice_number_counters.php')->up($aigLegacy, true);
    Db::forgetSchema($aigLegacy);
    $aigLegacyNumbers[] = $aigAllocate->invoke(null, $aigLegacy, 'default', 'invoice', 2026, '');
} finally {
    Db::init($aigSuiteDb, $aigSuiteDriver);
}
assert_equals([true, true, 2], $aigLegacyResults, '5 migration window: downloads work (each transfer counted) before the resume columns exist');
assert_equals(['20260001', '20260002', '20260003'], $aigLegacyNumbers, '5 migration window: invoices are numbered from the legacy counters, and the migration continues that sequence');

// ── 7. Delayed jobs on the database queue (used for rate-limited mail) ───────────────────────────
$aigDbQueue = new DatabaseQueue($pdo, 30);
$aigDelayedId = $aigDbQueue->pushAt('aig_probe', ['n' => 1], time() + 600);
assert_true(!in_array($aigDelayedId, array_column($aigDbQueue->pull(100), 'id'), true), '7 queue: a delayed database job is not pulled before its time');
$pdo->prepare("DELETE FROM queue_jobs WHERE id = ?")->execute([$aigDelayedId]);
