<?php

// Review round 20 (R50, shop and consumer law, medium):
// - a return request sent no e-mail (no confirmation on a durable medium, the merchant was not told) and its form
//   offered the e-book whose right of withdrawal the customer had waived;
// - the order confirmation named neither the payment nor the delivery method and did not repeat the consent to lose
//   the right of withdrawal for digital content;
// - a rejected complaint did not name the ADR body (SOI / ČOI) and the admin could reject it without an expert assessment;
// - a Czech store run by a Slovak seller pre-selected Slovakia (Slovak VAT for Czech customers);
// - the search of the Czech store did not find the Czech product names.
echo "\n--- Audit round 50: return e-mails, confirmation contents, complaint rejection, store country, translated search ---\n";
$r50lRoot = test_fixture_path('legal-r50');
mkdir($r50lRoot);
$r50lChild = $r50lRoot . '/check.php';
file_put_contents($r50lChild, <<<'PHP'
<?php
[, $root, $dir, $driver] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO('sqlite:' . $dir . '/legal.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$target = 'bbe_r50l_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
if ($driver === 'mysql') { $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4"); $db->exec("USE `{$target}`"); }
Db::init($db, $driver);
$checks = [];
$check = static function (bool $ok, string $label) use (&$checks): void { $checks[] = [$label, $ok]; };
$section = static function (string $name, callable $run) use ($check, $db): void {
    try {
        $run();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $check(false, "{$name} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
};
try {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $stores = Bootstrap::stores();
    $default = $stores['stores']['default'];
    $default['legal']['country'] = 'SK';
    unset($default['country']);
    // The Czech store of the same Slovak seller: its own language and URL, the seller's legal block.
    $cz = ['id' => 'cz', 'default_locale' => 'cs', 'url_prefix' => '/cs', 'languages' => ['cs', 'en'], 'catalog_scope' => 'default', 'domains' => []] + $default;
    $stores['stores'] = ['default' => $default, 'cz' => $cz];
    (new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
    Bootstrap::initStoreContext('/', '', 'default');
    JobQueue::setDir($dir . '/jobs-' . $driver);
    $mails = new class implements MailAdapter {
        public array $sent = [];
        public function send(array $msg): array { $this->sent[] = $msg; return ['status' => 'sent', 'message' => '']; }
        public function adapterId(): string { return 'r50l-capture'; }
    };
    MailService::useAdapter($mails);
    $html = static fn(array $m): string => html_entity_decode((string)($m['html'] ?? ''), ENT_QUOTES, 'UTF-8');
    $to = static fn(string $address): array => array_values(array_filter($mails->sent, static fn(array $m): bool => strtolower((string)($m['to'] ?? '')) === $address));

    $db->prepare("INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES ('default', 'r50l', 'R50L', NULL, 'r50l', 0)")->execute();
    $category = (int)$db->lastInsertId();
    $product = $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, product_type, translations) VALUES ('default', ?, ?, ?, ?, ?, 10, 'active', ?, ?)");
    $product->execute([$category, 'R50L-CHAIR', 'r50l-chair', 'R50L Wooden chair', 2000, 'physical', json_encode(['cs' => ['name' => 'Dřevěná židle Ořech']])]);
    $chairId = (int)$db->lastInsertId();
    $product->execute([$category, 'R50L-EBOOK', 'r50l-ebook', 'R50L E-book', 500, 'downloadable', '{}']);
    $ebookId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO customers (store_id, email, password_hash, name, status) VALUES ('default', 'r50l@example.test', '!test!', 'R50L Buyer', 'active')")->execute();
    $customer = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_id, customer_name, customer_email, payment_method, payment_method_snapshot, shipping_method,
        shipping_rate_snapshot, subtotal, total, currency, store_id, locale, delivered_at) VALUES ('R50L-1', 'delivered', 'paid', ?, 'R50L Buyer', 'r50l@example.test', 'bank_transfer', ?, 'gls', ?, 2500, 2500, 'eur', 'default', 'sk', ?)")
        ->execute([$customer, json_encode(['code' => 'bank_transfer', 'label' => 'R50L Prevod na účet', 'service_type' => 'bank_transfer']),
            json_encode(['method_code' => 'gls', 'label' => 'R50L GLS kuriér']), gmdate('Y-m-d H:i:s', time() - 2 * 86400)]);
    $orderId = (int)$db->lastInsertId();
    $item = $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, ?, ?)");
    $item->execute([$orderId, $chairId, 'R50L-CHAIR', 'R50L Wooden chair', 2000, 2000]);
    $chairLine = (int)$db->lastInsertId();
    $item->execute([$orderId, $ebookId, 'R50L-EBOOK', 'R50L E-book', 500, 500]);
    $ebookLine = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO digital_assets (sku, storage_path, download_name) VALUES ('R50L-EBOOK', 'default/r50l.pdf', 'r50l.pdf')")->execute();
    $db->prepare("INSERT INTO digital_entitlements (order_id, order_item_id, asset_id, token_hash, status) VALUES (?, ?, ?, ?, 'active')")
        ->execute([$orderId, $ebookLine, (int)$db->lastInsertId(), hash('sha256', 'r50l')]);
    $db->prepare("INSERT INTO order_consents (order_id, consent_key, version, consent_text, user_agent) VALUES (?, 'digital_delivery', '2026-10-22', 'R50L súhlasím so stratou práva na odstúpenie', '')")
        ->execute([$orderId]);
    $order = Orders::byId($orderId);

    // --- Returns ------------------------------------------------------------------------------------------------------
    $section('returns', static function () use ($orderId, $customer, $chairLine, $ebookLine, $check, $mails, $to, $html, $dir, $driver): void {
        $offered = array_map(static fn(array $i): int => (int)$i['id'], Rma::returnableItems($orderId));
        $check($offered === [$chairLine], 'the return form offers the chair, not the e-book whose right of withdrawal the customer waived (' . json_encode($offered) . ')');
        $check(Rma::create($orderId, $customer, 'R50L nechcem', [$ebookLine => 1]) === [false, null, 'no_items'], 'a return of the waived e-book is refused');
        [$ok, $rmaId] = Rma::create($orderId, $customer, 'R50L stolička nesedí', [$chairLine => 1]);
        $check($ok === true && $rmaId > 0, 'the chair can be returned');
        $ack = $to('r50l@example.test')[0] ?? [];
        $rma = Rma::byId((int)$rmaId) ?? [];
        $check(str_contains($html($ack), (string)($rma['rma_number'] ?? '-')) && str_contains($html($ack), 'R50L Wooden chair') && str_contains($html($ack), 'R50L stolička nesedí'),
            'the customer gets the return request confirmed by e-mail (durable medium): its number, the line and the reason');
        $jobs = array_map(static fn(string $f): array => json_decode((string)file_get_contents($f), true) ?: [], glob($dir . '/jobs-' . $driver . '/pending/*.json') ?: []);
        $events = array_map(static fn(array $j): string => ($j['type'] ?? '') . ' ' . ($j['payload']['event'] ?? ''), $jobs);
        $check(in_array('merchant_notify return:' . $rmaId, $events, true), 'the merchant is notified through a merchant_notify job (' . json_encode($events) . ')');
        $sent = MerchantNotifier::send($orderId, 'return:' . $rmaId);
        $merchant = $to('r50l-merchant@example.test')[0] ?? [];
        $check($sent === 1 && str_contains($html($merchant), (string)($rma['rma_number'] ?? '-')) && str_contains($html($merchant), 'R50L Wooden chair')
            && !str_contains($html($merchant), 'R50L E-book') && str_contains($html($merchant), 'R50L stolička nesedí'),
            'the merchant e-mail names the return, only its line and the reason (sent ' . $sent . ')');
        // R54 (review round 19, UX): delivered two days ago, so within the withdrawal period — as the customer was told.
        $check(!str_contains($html($merchant), 'schváľte alebo zamietnite') && str_contains($html($merchant), 'nepotrebuje vaše schválenie'),
            'R54: the merchant is not asked to approve a return within the withdrawal period (was: "Žiadosť schváľte alebo zamietnite")');
    });

    // --- Order confirmation -------------------------------------------------------------------------------------------
    $section('order confirmation', static function () use ($order, $check): void {
        $vars = ConsumerNotice::mailVars($order) + ['order_number' => 'R50L-1', 'customer_name' => 'R50L Buyer', 'order_status' => 'paid', 'order_total' => '25,00 €', 'items' => []];
        $check([$vars['payment_method'] ?? null, $vars['shipping_method'] ?? null, $vars['digital_consent'] ?? null]
            === ['R50L Prevod na účet', 'R50L GLS kuriér', 'R50L súhlasím so stratou práva na odstúpenie'], 'the confirmation knows the payment and delivery method and the digital consent given');
        $missing = [];
        foreach (['sk', 'cs', 'en', 'de', 'pl', 'hu', 'es'] as $locale) {
            $rendered = html_entity_decode(MailService::render('order_confirmation', $locale, $vars)['html'], ENT_QUOTES, 'UTF-8');
            foreach (['R50L Prevod na účet', 'R50L GLS kuriér', 'R50L súhlasím so stratou práva na odstúpenie'] as $text) if (!str_contains($rendered, $text)) $missing[] = "{$locale}: {$text}";
        }
        $check($missing === [], 'every language of the order confirmation prints them (' . json_encode($missing, JSON_UNESCAPED_UNICODE) . ')');
        $plain = ConsumerNotice::mailVars(['id' => 0, 'order_number' => 'R50L-X', 'payment_method' => 'cod'] + $order);
        $check(($plain['digital_consent'] ?? null) === '', 'an order without the consent repeats none');
    });

    // --- Complaint rejection ------------------------------------------------------------------------------------------
    $section('complaint rejection', static function () use ($order, $chairLine, $check, $mails, $to, $html): void {
        $submitted = Complaints::submit($order, $chairLine, 'R50L Buyer', 'R50L prasknutá noha', 'repair');
        $check($submitted['ok'] === true, 'the complaint is filed');
        $check(Complaints::setStatus($submitted['id'], 'rejected', 'R50L poškodenie používaním') === 'assessment_required'
            && (Complaints::byId($submitted['id'])['status'] ?? '') === 'received', 'a complaint is not rejected without an expert assessment');
        $mails->sent = [];
        $check(Complaints::setStatus($submitted['id'], 'rejected', 'R50L poškodenie používaním', 'R50L Znalec Ing. Kováč: mechanické poškodenie') === null,
            'with the assessment the complaint is rejected');
        $row = Complaints::byId($submitted['id']) ?? [];
        $decided = $html($to('r50l@example.test')[0] ?? []);
        $check(($row['assessment'] ?? '') === 'R50L Znalec Ing. Kováč: mechanické poškodenie', 'the assessment is kept with the decision');
        $check(str_contains($decided, 'R50L Znalec Ing. Kováč') && str_contains($decided, 'Slovenská obchodná inšpekcia') && str_contains($decided, 'https://www.soi.sk'),
            'the rejection e-mail names the assessment and the ADR body of the seller\'s country (SOI)');
    });

    // --- Store country and translated search -------------------------------------------------------------------------
    $section('Czech store', static function () use ($db, $chairId, $check): void {
        Bootstrap::initStoreContext('/cs/', '', 'cz');
        $check(StoreContext::current()->marketCountry() === 'CZ', 'the Czech store of a Slovak seller sells to the Czech Republic by default (' . StoreContext::current()->marketCountry() . ')');
        $countries = ShippingService::destinationCountries();
        if (isset($countries['CZ'])) $check(CheckoutService::formState([], false)['country'] === 'CZ', 'its checkout pre-selects the Czech Republic, not the seller\'s Slovakia');
        Search::refreshNames([$chairId]);
        $found = static fn(string $q): array => array_map(static fn(array $p): string => (string)$p['sku'], Search::query($q)['products']);
        $check($found('židle') === ['R50L-CHAIR'] && $found('Ořech') === ['R50L-CHAIR'], 'the Czech store finds the product by its Czech name (' . json_encode([$found('židle'), $found('Ořech')], JSON_UNESCAPED_UNICODE) . ')');
        $check($found('chair') === ['R50L-CHAIR'], 'the base name is still found');
        $db->prepare('UPDATE products SET translations = ? WHERE id = ?')->execute([json_encode(['cs' => ['name' => 'Dubová lavice']]), $chairId]);
        Search::refreshNames([$chairId]);
        $check($found('židle') === [] && $found('lavice') === ['R50L-CHAIR'], 'a changed translation replaces the old name in the index');
        Bootstrap::initStoreContext('/', '', 'default');
    });
    echo 'R50LRESULT' . json_encode(['checks' => $checks], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if ($driver === 'mysql') $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
PHP);
$r50lSuiteConfig = TEST_DATA_DIR . '/' . AppConfig::SUITE_FILE;
$r50lSavedConfig = (string)file_get_contents($r50lSuiteConfig);
try {
    $r50lConfig = require $r50lSuiteConfig;
    $r50lConfig['merchant_notifications'] = ['enabled' => true, 'recipients' => ['r50l-merchant@example.test']];
    file_put_contents($r50lSuiteConfig, '<?php return ' . var_export($r50lConfig, true) . ";\n");
    foreach (['sqlite', 'mysql'] as $r50lDriver) {
        if ($r50lDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { print "  [SKIP] R50 legal native MySQL (set TEST_MYSQL_DSN)\n"; continue; }
        $r50lProcess = proc_open([PHP_BINARY, $r50lChild, dirname(TEST_CLI_DIR), $r50lRoot, $r50lDriver], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r50lPipes);
        fclose($r50lPipes[0]);
        $r50lRaw = stream_get_contents($r50lPipes[1]) . "\n" . stream_get_contents($r50lPipes[2]);
        fclose($r50lPipes[1]); fclose($r50lPipes[2]);
        $r50lExit = proc_close($r50lProcess);
        $r50lAt = strrpos($r50lRaw, 'R50LRESULT');
        $r50lResult = $r50lAt === false ? null : json_decode(strtok(substr($r50lRaw, $r50lAt + 10), "\n"), true);
        assert_true($r50lExit === 0 && is_array($r50lResult), "R50 legal {$r50lDriver}: the isolated shop completes (" . ($r50lAt === false ? substr($r50lRaw, 0, 1500) : '') . ')');
        foreach ($r50lResult['checks'] ?? [] as [$r50lLabel, $r50lOk]) assert_true($r50lOk, "R50 legal {$r50lDriver}: {$r50lLabel}");
    }
} finally {
    file_put_contents($r50lSuiteConfig, $r50lSavedConfig);
}
