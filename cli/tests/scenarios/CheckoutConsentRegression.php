<?php

// The actual HTML and JSON boundaries share a temporary database, never deployment config.
echo "\n--- Checkout consent HTTP regression ---\n";
$ccRoot = test_fixture_path('checkout-consents');
mkdir($ccRoot, 0700, true);
$ccSnapshot = new PDO('sqlite:' . $testDbPath);
$ccSnapshot->exec('VACUUM INTO ' . $ccSnapshot->quote($ccRoot . '/shop.sqlite'));
$ccSnapshot = null;
$ccDb = new PDO('sqlite:' . $ccRoot . '/shop.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$ccDb->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'cc-fixture', 'Consent fixture', 'cc-fixture', 0)");
$ccCategory = (int)$ccDb->lastInsertId();
$ccProducts = [];
foreach (['digital' => 'downloadable', 'physical' => 'simple'] as $ccKind => $ccType) {
    $ccDb->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, product_type, price, currency, stock, status) VALUES ('default', ?, ?, ?, ?, ?, 599, 'eur', 100, 'active')")->execute([$ccCategory, 'CC-' . $ccKind, 'cc-' . $ccKind, 'Consent ' . $ccKind, $ccType]);
    $ccProducts[$ccKind] = (int)$ccDb->lastInsertId();
}
$ccDb->exec("INSERT INTO warehouses (code, name, is_default) VALUES ('cc-fixture', 'Consent fixture', 1)");
$ccWarehouse = (int)$ccDb->lastInsertId();
$ccDb->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', ?, 'CC-physical', 100, 0)")->execute([$ccWarehouse]);
$ccDb->exec("INSERT INTO payment_methods (code, provider, name, service_type, requires_online, active) VALUES ('consentpay', 'manual', 'Consent offline', 'offline', 0, 1)");
$ccDb->exec("INSERT INTO payment_method_countries (method_code, country) VALUES ('consentpay', 'SK'), ('consentpay', 'DE')");
$ccDefinitions = [
    'terms' => ['version' => 'v2', 'required' => true, 'text' => 'Terms <script>unsafe</script>', 'url' => '/terms'],
    'digital_delivery' => ['version' => 'v3', 'required' => true, 'text' => 'Immediate digital delivery', 'countries' => ['SK'], 'product_types' => ['downloadable']],
    'physical_delivery' => ['required' => true, 'text' => 'Physical consent', 'countries' => ['SK'], 'product_types' => ['simple']],
    'de_terms' => ['required' => true, 'text' => 'German consent', 'countries' => ['DE']],
    'marketing' => ['required' => false, 'text' => 'Optional marketing'],
    'disabled' => ['active' => false, 'required' => true, 'text' => 'Disabled consent'],
];
file_put_contents($ccRoot . '/router.php', '<?php
require ' . var_export(dirname(TEST_CLI_DIR) . '/src/autoload.php', true) . ';
require ' . var_export(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront/CheckoutPages.php', true) . ';
$db = new PDO("sqlite:" . __DIR__ . "/shop.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Db::init($db, "sqlite");
Security::setRateDbForTesting(new PDO("sqlite::memory:"));
JobQueue::setDir(__DIR__ . "/jobs");
(new ReflectionProperty(GdprService::class, "cachedSalt"))->setValue(null, "consent-fixture-salt");
Bootstrap::initStoreContext("/", "", "default");
Template::setTheme("default");
I18n::setLocale("sk");
Security::sessionStart();
$_SESSION["csrf_token"] = "checkout-consent-test";
$_SESSION["cart"] = [];
$ids = ' . var_export($ccProducts, true) . ';
$kind = $_GET["kind"] ?? "digital";
foreach ($kind === "mixed" ? $ids : [$ids[$kind]] as $id) Cart::add($id, null, 1);
ConsentService::setDefinitionsForTesting(' . var_export($ccDefinitions, true) . ');
$price = static fn(int $value): string => I18n::price($value, "eur");
$t = static fn(string $key): string => I18n::t($key);
$storeCurrency = "eur";
if (($_GET["transport"] ?? "html") !== "html") require ' . var_export(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php', true) . ';
if (($_GET["transport"] ?? "html") === "json") {
    header("Content-Type: application/json");
    echo json_encode(apiCheckout(), JSON_THROW_ON_ERROR);
} elseif (($_GET["transport"] ?? "html") === "definitions") {
    header("Content-Type: application/json");
    echo json_encode(apiConsentDefinitions(), JSON_THROW_ON_ERROR);
} else {
    if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["country"])) $_POST["country"] = $_GET["country"];
    echo ($_GET["page"] ?? "") === "order-done" ? renderOrderDone((string)($_GET["order"] ?? "")) : renderCheckout();
}
');
$ccSocket = stream_socket_server('tcp://127.0.0.1:0', $ccErrno, $ccError);
$ccAddress = stream_socket_get_name($ccSocket, false);
fclose($ccSocket);
$ccServer = proc_open([PHP_BINARY, '-S', $ccAddress, $ccRoot . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $ccRoot . '/server.log', 'a'], 2 => ['file', $ccRoot . '/server.log', 'a']], $ccPipes);
fclose($ccPipes[0]);
try {
    $ccReady = false;
    for ($ccTry = 0; $ccTry < 50; $ccTry++) {
        $ccProbe = @stream_socket_client('tcp://' . $ccAddress, $ccErrno, $ccError, 0.1);
        if ($ccProbe) { fclose($ccProbe); $ccReady = true; break; }
        usleep(100000);
    }
    assert_true($ccReady, 'Consent: isolated HTTP fixture starts');
    $ccRequest = static function(string $kind, string $transport, ?array $input = null, string $country = 'SK') use ($ccAddress): array {
        $json = $transport === 'json';
        $context = ['method' => $input === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15];
        $context['header'] = 'Content-Type: ' . ($json ? 'application/json' : 'application/x-www-form-urlencoded') . "\r\nX-CSRF-Token: checkout-consent-test\r\n";
        if ($input !== null) $context['content'] = $json ? json_encode($input, JSON_THROW_ON_ERROR) : http_build_query($input);
        $stream = fopen('http://' . $ccAddress . '/?kind=' . $kind . '&transport=' . $transport . '&country=' . $country, 'r', false, stream_context_create(['http' => $context]));
        $body = stream_get_contents($stream);
        $headers = stream_get_meta_data($stream)['wrapper_data'];
        fclose($stream);
        preg_match('/\s(\d{3})\s/', $headers[0], $status);
        return [(int)$status[1], $body, $headers];
    };
    $ccXpath = static function(string $html): DOMXPath {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new DOMXPath($doc);
    };
    foreach (['digital', 'physical', 'mixed'] as $ccKind) {
        foreach (['SK', 'DE'] as $ccCountry) {
            [$ccStatus, $ccHtml] = $ccRequest($ccKind, 'html', null, $ccCountry);
            $ccContext = ['country' => $ccCountry, 'product_types' => $ccKind === 'mixed' ? ['downloadable', 'simple'] : [$ccKind === 'digital' ? 'downloadable' : 'simple']];
            ConsentService::setDefinitionsForTesting($ccDefinitions);
            $ccExpected = ConsentService::definitions($ccContext);
            $ccDom = $ccXpath($ccHtml);
            $ccBoxes = $ccDom->query('//input[@type="checkbox" and starts-with(@name,"consents[")]');
            assert_equals(array_keys($ccExpected), array_map(static fn(DOMElement $el): string => substr($el->getAttribute('name'), 9, -1), iterator_to_array($ccBoxes)), "Consent {$ccKind}/{$ccCountry}: HTML matches canonical active definitions");
            assert_equals(0, $ccDom->query('//input[@type="checkbox" and @checked]')->length, 'Consent: no implicit checked consent');
            assert_equals($ccKind === 'digital' ? 0 : 3, $ccDom->query('//input[@required and (@name="street" or @name="city" or @name="zip")]')->length, 'Checkout: address requirement follows cart type');
            if ($ccKind === 'digital') assert_equals('digital', $ccDom->evaluate('string(//input[@name="shipping_method"]/@value)'), 'Checkout: digital HTML shows only electronic delivery');
            foreach ($ccBoxes as $ccBox) {
                $ccKey = substr($ccBox->getAttribute('name'), 9, -1);
                assert_equals($ccExpected[$ccKey]['required'], $ccBox->hasAttribute('required'), 'Consent: required follows definition');
            }
            $ccApiDefinitions = json_decode($ccRequest($ccKind, 'definitions', null, $ccCountry)[1], true, 512, JSON_THROW_ON_ERROR);
            assert_equals(array_values($ccExpected), $ccApiDefinitions['consents'], "Consent {$ccKind}/{$ccCountry}: API uses same country/cart definitions");
            assert_true(!str_contains($ccHtml, '<script>unsafe</script>'), 'Consent: definition text HTML-escaped');
        }
        $ccInput = ['checkout' => '1', 'csrf_token' => 'checkout-consent-test', 'name' => 'Buyer "<img src=x onerror=alert(1)>', 'email' => 'consent-' . $ccKind . '@example.test', 'phone' => '+12345', 'street' => 'Street " <svg>', 'city' => 'City', 'zip' => '12345', 'country' => 'SK', 'payment' => 'consentpay', 'payment_method' => 'consentpay', 'notes' => '</textarea><script>attack</script>'];
        $ccRates = ShippingService::calculateRates('SK', 0, 1198, false, 0);
        $ccInput['shipping_method'] = $ccKind === 'digital' ? 'digital' : (string)$ccRates[0]['method_code'];
        $ccBeforeOrders = (int)$ccDb->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $ccBeforePayments = (int)$ccDb->query('SELECT COUNT(*) FROM payment_transactions')->fetchColumn();
        [$ccStatus, $ccHtml] = $ccRequest($ccKind, 'html', $ccInput);
        assert_equals(422, $ccStatus, "Consent {$ccKind}: missing HTML acceptance returns 422");
        assert_equals($ccBeforeOrders, (int)$ccDb->query('SELECT COUNT(*) FROM orders')->fetchColumn(), 'Consent rejected: no order created');
        assert_equals($ccBeforePayments, (int)$ccDb->query('SELECT COUNT(*) FROM payment_transactions')->fetchColumn(), 'Consent rejected: no payment created');
        $ccDom = $ccXpath($ccHtml);
        assert_equals(1, $ccDom->query('//form[contains(@class,"checkout-form")]')->length, 'Consent rejected: customer remains on checkout form');
        assert_equals($ccInput['name'], $ccDom->evaluate('string(//input[@name="name"]/@value)'), 'Checkout error: safe name retained');
        assert_equals($ccInput['notes'], $ccDom->evaluate('string(//textarea[@name="notes"])'), 'Checkout error: safe notes retained');
        assert_true(!str_contains($ccHtml, '<script>attack</script>'), 'Checkout error: no injected markup');
        assert_true(str_contains($ccHtml, (require dirname(TEST_CLI_DIR) . '/lang/sk.php')['checkout.consent_required']), 'Consent rejected: localized error visible');
        $ccRefreshInput = array_replace($ccInput, ['country' => 'DE', 'checkout_refresh' => '1']);
        [$ccStatus, $ccRefreshHtml] = $ccRequest($ccKind, 'html', $ccRefreshInput);
        $ccRefreshDom = $ccXpath($ccRefreshHtml);
        assert_equals(200, $ccStatus, 'Consent: country refresh does not attempt checkout');
        assert_equals(1, $ccRefreshDom->query('//input[@name="consents[de_terms]"]')->length, 'Consent: POST country change renders new country definitions');
        assert_equals(0, $ccRefreshDom->query('//input[@name="consents[digital_delivery]" or @name="consents[physical_delivery]"]')->length, 'Consent: country change removes inapplicable consents');
        assert_equals('DE', $ccRefreshDom->evaluate('string(//select[@name="country"]/option[@selected]/@value)'), 'Consent: selected country retained after refresh');
        assert_equals($ccBeforeOrders, (int)$ccDb->query('SELECT COUNT(*) FROM orders')->fetchColumn(), 'Consent: country refresh creates no order');
        $ccAccepted = ['terms' => '1', 'digital_delivery' => '1', 'physical_delivery' => '1'];
        [$ccStatus, $ccJson] = $ccRequest($ccKind, 'json', $ccInput + ['consents' => $ccAccepted]);
        assert_equals(422, $ccStatus, 'Consent JSON: string acceptance remains rejected');
        assert_equals($ccBeforeOrders, (int)$ccDb->query('SELECT COUNT(*) FROM orders')->fetchColumn(), 'Consent JSON rejected: no order');
        [$ccStatus] = $ccRequest($ccKind, 'html', $ccInput + ['consents' => $ccAccepted]);
        assert_equals(302, $ccStatus, "Consent {$ccKind}: explicit HTML string 1 succeeds");
        $ccOrder = $ccDb->query('SELECT id FROM orders ORDER BY id DESC LIMIT 1')->fetchColumn();
        $ccRows = $ccDb->query('SELECT consent_key, version, accepted FROM order_consents WHERE order_id = ' . (int)$ccOrder . ' ORDER BY consent_key')->fetchAll();
        $ccExpectedKeys = $ccKind === 'digital' ? ['digital_delivery', 'terms'] : ($ccKind === 'physical' ? ['physical_delivery', 'terms'] : ['digital_delivery', 'physical_delivery', 'terms']);
        assert_equals($ccExpectedKeys, array_column($ccRows, 'consent_key'), 'Consent accepted: only active explicit consents recorded');
        assert_equals(array_fill(0, count($ccRows), 1), array_map('intval', array_column($ccRows, 'accepted')), 'Consent accepted: immutable evidence is true');
        assert_equals($ccBeforeOrders + 1, (int)$ccDb->query('SELECT COUNT(*) FROM orders')->fetchColumn(), 'Consent accepted: exactly one order');
        $ccInput['email'] = 'json-consent-' . $ccKind . '@example.test';
        [$ccStatus, $ccJson] = $ccRequest($ccKind, 'json', $ccInput + ['consents' => array_fill_keys(array_keys($ccAccepted), true)]);
        assert_true(!empty(json_decode($ccJson, true)['success']), "Consent {$ccKind}: JSON boolean true succeeds (" . (json_decode($ccJson, true)['error'] ?? 'ok') . ')');
    }
    if (getenv('TEST_CHECKOUT_BROWSER') === '1') {
        print 'CHECKOUT_BROWSER_FIXTURE ' . json_encode(['address' => $ccAddress, 'root' => $ccRoot], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (ob_get_level() > 0) ob_flush();
        flush();
        while (!is_file($ccRoot . '/browser-done')) usleep(100000);
    }
} finally {
    ConsentService::setDefinitionsForTesting(null);
    proc_terminate($ccServer);
    proc_close($ccServer);
    $ccDb = null;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ccRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $ccFile) {
        $ccFile->isDir() ? rmdir($ccFile->getPathname()) : unlink($ccFile->getPathname());
    }
    rmdir($ccRoot);
}
