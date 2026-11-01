<?php

// Round 11 review (2026-11-01) "documents, e-mails and refunds": the order confirmation lists shipping and fees (its
// lines add up to the total); the invoice of a paid order asks for no payment; a discount row does not read as a
// percentage; every refund (partial, RMA, by hand) e-mails the customer the refunded amount; a refund by hand gets a
// credit note; admin refund amounts are entered in the currency; the e-mail template guard cannot be emptied by an
// {{#if}} or hidden CSS, and an import with a wrong --base freezes nothing; a Spanish order in an English store
// returns to Spanish pages and its e-mails link to them.
echo "\n--- Audit round 11: documents, e-mails and refunds ---\n";

$r11dSavedArgv = $argv ?? [];
$argv = ['worker.php', '--once'];
require_once TEST_CLI_DIR . '/../cli/worker.php';
$argv = $r11dSavedArgv;
require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Orders.php';
Bootstrap::initStoreContext('/', '', 'default');
$r11dFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R11 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r11dMailer = new class implements MailAdapter {
    public array $sent = [];
    public function send(array $msg): array { $this->sent[] = $msg; return ['status' => 'sent', 'message' => '', 'raw' => null]; }
    public function adapterId(): string { return 'r11d-capture'; }
};
MailService::useAdapter($r11dMailer);
$r11dJobsProp = new ReflectionProperty(JobQueue::class, 'jobsDir');
$r11dJobsProp->setAccessible(true);
$r11dPrevJobs = $r11dJobsProp->getValue();
$r11dJobsDir = test_fixture_path('r11d-jobs');
JobQueue::setDir($r11dJobsDir);
$r11dRefundMails = static fn(int $orderId): array => array_values(array_map(static fn(array $row): array => $row['job']['payload'],
    JobQueue::fileJobs(static fn(array $job): bool => ($job['type'] ?? '') === 'email_send' && ($job['payload']['template'] ?? '') === 'refund_issued'
        && (int)($job['payload']['order_id'] ?? 0) === $orderId)));
$pdo->exec('DELETE FROM products WHERE id = 98980');
$pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock) VALUES (98980, ?, 1, 'R11D tovar', 'r11d-tovar', 'R11D-A', 'simple', 1000, 'eur', 'active', 100)")
    ->execute([StoreContext::current()->catalogId()]);
$r11dOrder = static function (int $id, string $method, string $paymentStatus, string $paymentId, array $snapshot, string $locale = 'sk') use ($pdo): array {
    $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM payment_transactions WHERE order_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
    $pdo->prepare("INSERT INTO orders (id, store_id, order_number, status, payment_status, customer_name, customer_email, customer_phone, shipping_addr,
        payment_method, payment_id, payment_method_snapshot, subtotal, discount_amount, shipping_cost, payment_fee, tax_amount, total, currency, locale, created_at)
        VALUES (?, ?, ?, 'processing', ?, 'R11D Zákazník', ?, '', ?, ?, ?, ?, 1000, 0, 390, 100, 0, 1490, 'eur', ?, ?)")
        ->execute([$id, StoreContext::current()->id(), 'R11D-' . $id, $paymentStatus, "r11d-{$id}@example.test",
            json_encode(['street' => 'Dokladová 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK']),
            $method, $paymentId, json_encode($snapshot), $locale, gmdate('Y-m-d H:i:s')]);
    $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, 98980, 'R11D-A', 'R11D tovar', 1, 1000, 1000)")
        ->execute([$id]);
    return Orders::byId($id);
};
$r11dCard = ['code' => 'card', 'provider' => 'stripe', 'service_type' => 'card', 'label' => 'Platba kartou'];
$r11dBank = ['code' => 'bank_transfer', 'provider' => 'manual', 'service_type' => 'bank_transfer', 'label' => 'Bankový prevod'];

// The order confirmation lists subtotal, shipping and the payment fee: its lines add up to the total it states.
try {
    $r11dConfirm = $r11dOrder(98981, 'bank_transfer', 'pending', '', $r11dBank);
    $r11dMailer->sent = [];
    handleEmailSend(['template' => 'order_confirmation', 'to' => 'x@example.test', 'order_id' => 98981], []);
    $r11dHtml = (string)($r11dMailer->sent[0]['html'] ?? '');
    $r11dPlain = html_entity_decode(strip_tags($r11dHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    foreach ([[I18n::tIn('admin.order.shipping', 'sk'), 390], [I18n::tIn('admin.order.payment_fee', 'sk'), 100], [I18n::tIn('admin.order.subtotal', 'sk'), 1000]] as [$r11dLabel, $r11dCents]) {
        assert_true((bool)preg_match('~' . preg_quote($r11dLabel, '~') . '\s*' . preg_quote(I18n::price($r11dCents, 'eur'), '~') . '~u', $r11dPlain),
            "R11 confirmation mail: the line \"{$r11dLabel}\" shows " . I18n::price($r11dCents, 'eur'));
    }
    assert_true(str_contains($r11dPlain, I18n::price(1490, 'eur')), 'R11 confirmation mail: the total 14,90 € is the sum of the lines above it');
} catch (Throwable $e) {
    $r11dFail('confirmation mail totals', $e);
}

// The invoice of an order paid by card asks for nothing: no "Spolu k úhrade" with the seller's IBAN; an unpaid one still does.
try {
    $r11dTexts = static function (array $invoice): array {
        preg_match_all('/Td \(([^)]*)\) Tj/', (string)file_get_contents(InvoiceService::pdfAbsolutePath($invoice)), $m);
        return $m[1];
    };
    $r11dOrder(98982, 'stripe', 'paid', 'pi_r11d_98982', $r11dCard);
    $r11dPaidTexts = $r11dTexts(InvoiceService::issue(98982));
    $r11dOrder(98983, 'bank_transfer', 'pending', '', $r11dBank);
    $r11dUnpaidTexts = $r11dTexts(InvoiceService::issue(98983));
    assert_equals([], array_values(preg_grep('/^IBAN: /', $r11dPaidTexts)), 'R11 invoice: a card-paid invoice shows no IBAN to pay to');
    assert_true(preg_grep('/^Uhraden/', $r11dPaidTexts) !== [] && preg_grep('/k \S*hrade: /', $r11dPaidTexts) === [],
        'R11 invoice: a card-paid invoice states it is paid instead of "Spolu k úhrade"');
    assert_true(preg_grep('/^IBAN: /', $r11dUnpaidTexts) !== [] && preg_grep('/k \S*hrade: /', $r11dUnpaidTexts) !== [],
        'R11 invoice: an unpaid bank-transfer invoice still asks for payment with the IBAN');
    $r11dSource = (string)file_get_contents(TEST_CLI_DIR . '/../src/InvoiceService.php');
    assert_true(!str_contains($r11dSource, "(\$row['label'] === 'discount' ? ' ' . self::rateKey(\$row['rate']) . '%'"),
        'R11 invoice: the discount row is named "Zľava", its VAT rate is in the VAT column (no "Zľava 23 %" read as a percentage)');
} catch (Throwable $e) {
    $r11dFail('paid invoice', $e);
}

// Every refund e-mails the customer the amount refunded: a partial provider refund, an RMA refund the worker executes,
// a refund by hand (which also corrects the invoice by a credit note). A rolled-back booking sends nothing.
try {
    $r11dOrder(98984, 'stripe', 'paid', 'pi_r11d_98984', $r11dCard);
    PaymentService::bookProviderRefund(98984, 'stripe', 're_r11d_part', 400, 'charge.refunded', 'r11d_refund_part');
    $r11dMails = $r11dRefundMails(98984);
    assert_equals([400], array_map(static fn(array $p): int => (int)$p['refund_amount'], $r11dMails),
        'R11 refund mail: a partial provider refund queues one refund mail with its amount');
    $r11dMailer->sent = [];
    if ($r11dMails) handleEmailSend($r11dMails[0], []);
    $r11dRefundHtml = (string)($r11dMailer->sent[0]['html'] ?? '');
    assert_true(str_contains($r11dRefundHtml, I18n::price(400, 'eur')) && !str_contains($r11dRefundHtml, I18n::price(1490, 'eur')),
        'R11 refund mail: the customer reads the refunded 4,00 €, not the order total');
    PaymentService::bookProviderRefund(98984, 'stripe', 're_r11d_part', 400, 'charge.refunded', 'r11d_refund_part_again');
    assert_equals(1, count($r11dRefundMails(98984)), 'R11 refund mail: the same refund reported again mails nothing more');

    // The worker's provider refund (RMA credit note, admin request) books 'refund_succeeded': one mail.
    $r11dGateway = new class implements PaymentAdapter {
        public function createSession(array $order): array { return []; }
        public function handleWebhook(array $payload): array { return []; }
        public function refund(string $paymentId, int $amount): array { return ['status' => 'succeeded', 'refund_id' => 're_r11d_rma']; }
        public function getStatus(string $paymentId): string { return 'paid'; }
    };
    PaymentService::refund(98984, 'pi_r11d_98984', 300, $r11dGateway, 'r11d_rma_refund', 'eur');
    $r11dAmounts = array_map(static fn(array $p): int => (int)$p['refund_amount'], $r11dRefundMails(98984));
    sort($r11dAmounts);
    assert_equals([300, 400], $r11dAmounts,
        'R11 refund mail: an RMA refund executed at the provider mails its amount too');

    // A booking inside a transaction that rolls back: its queued mail announces nothing.
    $r11dOrder(98985, 'stripe', 'paid', 'pi_r11d_98985', $r11dCard);
    $pdo->beginTransaction();
    PaymentService::bookProviderRefund(98985, 'stripe', 're_r11d_rolled', 500, 'charge.refunded', 'r11d_refund_rolled');
    $pdo->rollBack();
    PaymentService::bookProviderRefund(98985, 'stripe', 're_r11d_kept', 200, 'charge.refunded', 'r11d_refund_kept');
    $r11dMailer->sent = [];
    foreach ($r11dRefundMails(98985) as $r11dPayload) handleEmailSend($r11dPayload, []);
    $r11dSentHtml = implode("\n", array_map(static fn(array $m): string => (string)($m['html'] ?? ''), $r11dMailer->sent));
    assert_true(count($r11dMailer->sent) === 1 && str_contains($r11dSentHtml, I18n::price(200, 'eur')) && !str_contains($r11dSentHtml, I18n::price(500, 'eur')),
        'R11 refund mail: a refund rolled back with its transaction is never announced; the booked one is');

    // A refund by hand: refund mail plus a credit note for the amount on the order's invoice.
    $r11dOrder(98986, 'bank_transfer', 'paid', '', $r11dBank);
    $r11dInvoice = InvoiceService::issue(98986);
    PaymentService::completeManualRefund(98986, 590, 'r11d-admin', 'Vrátené prevodom');
    assert_equals([590], array_map(static fn(array $p): int => (int)$p['refund_amount'], $r11dRefundMails(98986)),
        'R11 refund mail: a refund by hand mails the customer its amount');
    $r11dCredits = $pdo->query("SELECT total_gross, refers_to_invoice_id, number FROM invoices WHERE order_id = 98986 AND type = 'credit_note'")->fetchAll(PDO::FETCH_ASSOC);
    assert_true(count($r11dCredits) === 1 && (int)$r11dCredits[0]['total_gross'] === -590 && (int)$r11dCredits[0]['refers_to_invoice_id'] === (int)$r11dInvoice['id'],
        'R11 manual refund: a credit note of -5,90 € corrects the order\'s invoice (' . json_encode($r11dCredits) . ')');
    assert_equals(1490 - 590, PaymentService::refundableBalance(98986), 'R11 manual refund: the credit note does not count the refunded money twice');
    $r11dCreditRow = InvoiceService::byNumber((string)($r11dCredits[0]['number'] ?? ''));
    assert_true($r11dCreditRow !== null && is_file(InvoiceService::pdfAbsolutePath($r11dCreditRow)), 'R11 manual refund: the credit note has its PDF');

    // Changing the order status to refunded without money moving announces no refund.
    $r11dHandle = new ReflectionMethod(OrderWorkflow::class, 'handleRefund');
    $r11dHandle->setAccessible(true);
    assert_equals([], array_values(array_filter($r11dHandle->invoke(null, Orders::byId(98986)), static fn(array $j): bool => $j[0] === 'email_send')),
        'R11 refund mail: the order status "refunded" itself queues no second refund mail');
} catch (Throwable $e) {
    $r11dFail('refund mails', $e);
}

// Admin refund and payment amounts are entered in the currency ("5,90"), not in cents.
try {
    assert_equals([590, 590, 129900, -1, -1, -1], [Money::parseInput('5,90', 'eur'), Money::parseInput('5.90', 'eur'), Money::parseInput('1 299,00', 'eur'),
        Money::parseInput('abc', 'eur'), Money::parseInput(['5'], 'eur'), Money::parseInput('-5', 'eur')],
        'R11 admin amounts: an amount in euros is read as cents; anything else is refused');
    $r11dAdminSource = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin/Orders.php');
    assert_true(!str_contains($r11dAdminSource, 'amount_cents') && substr_count($r11dAdminSource, 'Money::decimal($refundableBalance') === 2
        && substr_count($r11dAdminSource, "Security::int(\$_POST['amount']") === 0,
        'R11 admin amounts: refund forms prefill the balance in the currency and no handler reads the amount as cents');
} catch (Throwable $e) {
    $r11dFail('admin amounts', $e);
}

// The template guard: the consumer information must be printed where the customer sees it.
try {
    $r11dDefault = str_replace("\r\n", "\n", (string)EmailTemplates::fileSource('order_confirmation', 'sk')['html']);
    assert_equals([], EmailTemplates::validate('order_confirmation', 'Objednávka {{order_number}}', $r11dDefault, ''), 'R11 template guard: the default confirmation passes');
    $r11dLink = '<a href="{{withdrawal_url}}">';
    $r11dBypasses = [
        'an empty {{#if}}' => str_replace($r11dLink, '{{#if withdrawal_url}}{{/if}}<a href="#">', $r11dDefault),
        'a block of another condition' => str_replace($r11dLink, '{{#if adr_name}}' . $r11dLink . '{{/if}}', $r11dDefault),
        'an element style rule' => str_replace('<body', '<head><style>p { display: none }</style></head><body', $r11dDefault),
        'an entity-encoded inline style' => str_replace('<h3>Odstúpenie od zmluvy</h3>' . "\n" . '<p>', '<h3>Odstúpenie od zmluvy</h3>' . "\n" . '<p style="display&#58;none">', $r11dDefault),
        'a 1px font' => str_replace('<h3>Odstúpenie od zmluvy</h3>' . "\n" . '<p>', '<h3>Odstúpenie od zmluvy</h3>' . "\n" . '<p style="font-size:1px">', $r11dDefault),
        'a closed <details>' => str_replace('<h3>Odstúpenie od zmluvy</h3>' . "\n" . '<p>', '<details><summary>Viac</summary><p>', $r11dDefault),
        'a title attribute' => str_replace('<a href="{{terms_url}}">{{terms_url}}</a>', '<span title="{{terms_url}}">tu</span>', $r11dDefault),
    ];
    foreach ($r11dBypasses as $r11dWhat => $r11dHtml) {
        $r11dErrors = implode(' ', EmailTemplates::validate('order_confirmation', 'Objednávka {{order_number}}', $r11dHtml, ''));
        assert_true($r11dHtml !== $r11dDefault && $r11dErrors !== '' && (str_contains($r11dErrors, 'withdrawal_url') || str_contains($r11dErrors, 'terms_url')),
            "R11 template guard: the consumer information hidden by {$r11dWhat} is refused ({$r11dErrors})");
    }
} catch (Throwable $e) {
    $r11dFail('template guard', $e);
}

// An import whose --base is not the release the shop ran freezes nothing.
try {
    $r11dImport = test_fixture_path('r11d-import');
    $r11dWrite = static function (string $file, string $content): void {
        if (!is_dir(dirname($file))) mkdir(dirname($file), 0777, true);
        file_put_contents($file, $content);
    };
    $r11dShipped = str_replace("\r\n", "\n", (string)EmailTemplates::fileSource('order_shipped', 'sk')['html']);
    $r11dWrite("{$r11dImport}/custom/sk/order_shipped.html", $r11dShipped);
    $r11dWrite("{$r11dImport}/custom/sk/order_paid.html", '<p>{{order_number}}</p>');
    $r11dWrite("{$r11dImport}/other/sk/order_shipped.html", '<p>another release {{order_number}}</p>');
    $r11dWrite("{$r11dImport}/other/sk/order_paid.html", '<p>another release paid {{order_number}}</p>');
    mkdir("{$r11dImport}/empty", 0777, true);
    $r11dRefused = [];
    foreach (['installed' => TEST_CLI_DIR . '/../templates/emails', 'empty' => "{$r11dImport}/empty", 'other' => "{$r11dImport}/other"] as $r11dWhat => $r11dBase) {
        try {
            EmailTemplates::importCustomised("{$r11dImport}/custom", $r11dBase, false);
        } catch (InvalidArgumentException $e) {
            $r11dRefused[] = $r11dWhat;
        }
    }
    assert_equals(['installed', 'empty', 'other'], $r11dRefused, 'R11 template import: a --base that is the installed templates, empty or another release is refused');
    assert_true(EmailTemplates::override('order_shipped', 'sk') === null && EmailTemplates::override('order_paid', 'sk') === null,
        'R11 template import: a refused import stores no override (no template is frozen)');
    $r11dRm = static function (string $dir) use (&$r11dRm): void {
        foreach (glob($dir . '/*') ?: [] as $entry) is_dir($entry) ? $r11dRm($entry) : unlink($entry);
        rmdir($dir);
    };
    $r11dRm($r11dImport);
} catch (Throwable $e) {
    $r11dFail('template import base', $e);
}

// A Spanish order in the English US store: back to Spanish pages, Spanish links in its e-mails.
try {
    Bootstrap::initStoreContext('/en/', '', 'us');
    $r11dEs = ['id' => 98987, 'order_number' => 'R11D-ES', 'locale' => 'es'];
    $r11dEn = ['id' => 98988, 'order_number' => 'R11D-EN', 'locale' => 'en'];
    assert_equals(['/?page=order-done&order=R11D-ES&lang=es', '/?page=order-done&order=R11D-EN'],
        [Template::withOrderLang('/?page=order-done&order=R11D-ES', $r11dEs), Template::withOrderLang('/?page=order-done&order=R11D-EN', $r11dEn)],
        'R11 ES: the page after a Spanish order keeps ?lang=es; the store language needs none');
    $r11dLinks = ConsumerNotice::mailVars($r11dEs);
    assert_true(str_contains($r11dLinks['withdrawal_url'], 'lang=es') && str_contains($r11dLinks['terms_url'], 'lang=es') && str_contains($r11dLinks['complaint_url'], 'lang=es'),
        'R11 ES: the withdrawal, terms and complaint links of a Spanish order open the Spanish pages');
    $r11dCheckoutSource = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Storefront/CheckoutPages.php')
        . file_get_contents(TEST_CLI_DIR . '/../src/Http/ApiHandlers.php') . file_get_contents(TEST_CLI_DIR . '/../src/PaymentLink.php');
    assert_equals(6, substr_count($r11dCheckoutSource, 'Template::withOrderLang('),
        'R11 ES: the checkout redirect, the gateway return/cancel URLs (checkout and pay again) and the mailed pay link carry the order language');
} catch (Throwable $e) {
    $r11dFail('Spanish order', $e);
} finally {
    Bootstrap::initStoreContext('/', '', 'default');
}

$r11dRmJobs = static function (string $dir) use (&$r11dRmJobs): void {
    foreach (glob($dir . '/*') ?: [] as $entry) is_dir($entry) ? $r11dRmJobs($entry) : unlink($entry);
    if (is_dir($dir)) rmdir($dir);
};
$r11dRmJobs($r11dJobsDir);
$r11dJobsProp->setValue(null, $r11dPrevJobs);
MailService::useAdapter(null);
