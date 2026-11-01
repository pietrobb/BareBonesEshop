<?php
/**
 * Round 9 "merchant onboarding": a new merchant could not run a real shop from the admin. Seller identity, legal data
 * and VAT lived only in stores.json with example data (Woodle s.r.o., invalid IBANs) that went onto invoices while
 * doctor said ready; no "New product" button; an owner did not see stores it was not explicitly granted; invoice PDFs
 * lost diacritics; the invoice variable symbol differed from the payment instructions and a credit note carried an
 * invoice's symbol and IBAN; an order paid by card got no confirmation with the seller data and withdrawal form.
 */

echo "\n--- Audit R9: merchant onboarding (store settings, doctor, owner stores, PDF, VS, confirmation) ---\n";

// --- Invoice PDF keeps Slovak, Czech, Polish and Hungarian letters ----------------------------------------------
$r9oText = 'Faktúra č. 2026 Šťastný Ľubomír, Łódź, Őrség, Žilina, Dvořák';
$r9oPdf = new MinimalPdf();
$r9oPdf->text(20, 20, $r9oText);
$r9oRaw = $r9oPdf->render();
preg_match('/\/Differences \[([^\]]*)\]/', $r9oRaw, $r9oDiff);
$r9oSlots = [];
preg_match_all('/(\d+) \/(\w+)/', $r9oDiff[1] ?? '', $r9oPairs, PREG_SET_ORDER);
foreach ($r9oPairs as [, $r9oByte, $r9oGlyph]) $r9oSlots[(int)$r9oByte] = $r9oGlyph;
$r9oGlyphs = ['tcaron' => 'ť', 'ccaron' => 'č', 'Lcaron' => 'Ľ', 'Lslash' => 'Ł', 'Ohungarumlaut' => 'Ő', 'rcaron' => 'ř', 'nacute' => 'ń', 'zacute' => 'ź'];
preg_match('/\((.*)\) Tj/s', $r9oRaw, $r9oShown);
$r9oDecoded = '';
foreach (str_split(stripcslashes($r9oShown[1] ?? '')) as $r9oByte) {
    $r9oDecoded .= isset($r9oSlots[ord($r9oByte)]) ? ($r9oGlyphs[$r9oSlots[ord($r9oByte)]] ?? '?') : (string)mb_convert_encoding($r9oByte, 'UTF-8', 'Windows-1252');
}
assert_equals($r9oText, $r9oDecoded, 'R9 PDF: the text drawn with the font encoding reads back with every diacritic');

// --- Variable symbol: invoice = payment instructions, credit note = its own --------------------------------------
$r9oInvoice = ['type' => 'invoice', 'order_id' => 4711, 'number' => '2026000042'];
$r9oCredit = ['type' => 'credit_note', 'order_id' => 4711, 'number' => 'CN-2026000007'];
assert_equals(BankTransferInstructions::variableSymbol(['id' => 4711]), InvoiceService::variableSymbol($r9oInvoice), 'R9 VS: the invoice repeats the variable symbol of the order\'s transfer instructions');
assert_true(InvoiceService::variableSymbol($r9oCredit) === '2026000007' && InvoiceService::variableSymbol($r9oCredit) !== InvoiceService::variableSymbol($r9oInvoice),
    'R9 VS: a credit note has the symbol of its own number, not the invoice\'s');

// --- Order confirmation for card payments arrives with the payment -----------------------------------------------
$r9oHandlePaid = new ReflectionMethod(OrderWorkflow::class, 'handlePaid');
$r9oTemplate = static function (array $order) use ($r9oHandlePaid): ?string {
    foreach ($r9oHandlePaid->invoke(null, $order + ['id' => 1, 'customer_email' => 'buyer@example.test', 'locale' => 'sk']) as [$job, $payload]) {
        if ($job === 'email_send' && ($payload['to'] ?? '') === 'buyer@example.test') return $payload['template'] ?? null;
    }
    return null;
};
assert_equals(['order_confirmation', 'order_confirmation', 'order_paid', 'order_paid'], [
    $r9oTemplate(['payment_method' => 'card', 'payment_method_snapshot' => json_encode(['code' => 'card', 'requires_online' => true])]),
    $r9oTemplate(['payment_method' => 'free', 'payment_method_snapshot' => '']),
    $r9oTemplate(['payment_method' => 'bank_transfer', 'payment_method_snapshot' => json_encode(['code' => 'bank_transfer', 'requires_online' => false])]),
    $r9oTemplate(['payment_method' => 'cod', 'payment_method_snapshot' => json_encode(['code' => 'cod'])]),
], 'R9 confirmation: a card or free order gets the order confirmation (seller, withdrawal form) with the payment; offline orders got it at checkout');

// --- Doctor recognises the shipped example seller data ----------------------------------------------------------
$r9oShipped = json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/stores.example.json'), true)['stores'];
foreach ($r9oShipped as $r9oId => $r9oStore) {
    assert_true(Setup::legalIdentityProblems($r9oStore['legal']) !== [], "R9 doctor: the shipped store {$r9oId} is recognised as example data");
}
$r9oReal = ['name' => 'Kníhkupectvo Rubin s.r.o.', 'street' => 'Mlynská 3', 'city' => 'Košice', 'zip' => '040 01', 'country' => 'SK', 'email' => 'obchod@rubin-knihy.sk', 'iban' => 'SK31 1200 0000 1987 4263 7541'];
assert_equals([], Setup::legalIdentityProblems($r9oReal), 'R9 doctor: a real seller passes');
assert_equals(1, count(Setup::legalIdentityProblems(['iban' => 'SK00 1100 0000 0012 3456 7890'] + $r9oReal)), 'R9 doctor: an IBAN with wrong check digits is named');

// --- Rendered admin (own SQLite database and stores.json copy) ---------------------------------------------------
foreach ($GLOBALS as $r9oValue) if ($r9oValue instanceof PDOStatement) $r9oValue->closeCursor();
$r9oRoot = dirname(TEST_CLI_DIR);
$r9oDir = test_fixture_path('r9-onboarding');
mkdir($r9oDir);
mkdir($r9oDir . '/sessions');
copy($r9oRoot . '/stores.example.json', $r9oDir . '/stores.json');
$r9oDbPath = $r9oDir . '/admin.sqlite';
$r9oSuiteDb = Db::get();
$r9oSuiteDriver = Db::driver();
$r9oPdo = new PDO('sqlite:' . $r9oDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$r9oKey = base64_encode(str_repeat('R', 32));
$r9oPreviousKey = getenv('BBE_ADMIN_IDENTITY_KEY');
putenv('BBE_ADMIN_IDENTITY_KEY=' . $r9oKey);
$r9oTokens = [];
try {
    Db::init($r9oPdo, 'sqlite');
    (new Migrator($r9oPdo, $r9oRoot . '/migrations', $r9oDir . '/migrate.lock'))->apply();
    // The owner was created by `admin-user create --store=default`: one explicit grant only.
    AdminIdentity::grantStore(AdminIdentity::create('r9o-owner', 'R9o-password-owner', 'owner', false), 'default', ['*']);
    AdminIdentity::grantStore(AdminIdentity::create('r9o-manager', 'R9o-password-manager', 'manager', false), 'default', ['*']);
    AdminIdentity::grantStore(AdminIdentity::create('r9o-viewer', 'R9o-password-viewer', 'viewer', false), 'default', ['*']);
    foreach (['owner', 'manager', 'viewer'] as $r9oWho) {
        $r9oAuth = AdminIdentity::authenticate("r9o-{$r9oWho}", 'R9o-password-' . $r9oWho, '', '127.0.19.80', 'R9O');
        $r9oTokens[$r9oWho] = (string)($r9oAuth['token'] ?? '');
        if ($r9oWho === 'owner') {
            $r9oOwnerStores = array_keys($r9oAuth['store_permissions'] ?? []);
            sort($r9oOwnerStores);
            $r9oAllStores = array_keys(Bootstrap::stores()['stores'] ?? []);
            sort($r9oAllStores);
            assert_equals($r9oAllStores, $r9oOwnerStores, 'R9 owner: an owner granted one store administers every store of stores.json');
        }
        if ($r9oWho === 'manager') {
            assert_equals(['default'], array_keys($r9oAuth['store_permissions'] ?? []), 'R9 owner: other roles still see only the stores they are granted');
        }
    }
} finally {
    Db::init($r9oSuiteDb, $r9oSuiteDriver);
}
file_put_contents($r9oDir . '/admin.php', <<<'PHP'
<?php
[, $root, $dbPath, $dir, $key, $token, $getJson, $postJson] = $argv;
require $root . '/src/autoload.php';
putenv('BBE_ADMIN_IDENTITY_KEY=' . $key);
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
JobQueue::setDir($dir . '/jobs');
StoreSettings::setPathForTesting($dir . '/stores.json');
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
$post = json_decode($postJson, true);
ini_set('session.use_cookies', '0');
ini_set('session.use_strict_mode', '0');
session_save_path($dir . '/sessions');
session_id('r9o' . substr(hash('sha256', $token), 0, 16));
session_start();
$_SESSION['csrf_token'] = 'r9o';
$_SESSION['admin_identity_token'] = $token;
$_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.19.81';
$_SERVER['HTTP_USER_AGENT'] = 'R9O';
$_SERVER['REQUEST_URI'] = '/admin.php';
$_GET = json_decode($getJson, true);
$_POST = $post === [] ? [] : $post + ['csrf_token' => 'r9o'];
ob_start();
register_shutdown_function(static function(): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR9ORESULT" . json_encode(['code' => http_response_code() ?: 200, 'out' => $out], JSON_INVALID_UTF8_SUBSTITUTE);
});
require $root . '/src/Entrypoints/Admin.php';
PHP);
$r9oRun = static function (string $who, array $get, array $post = []) use ($r9oDir, $r9oRoot, $r9oDbPath, $r9oKey, &$r9oTokens): array {
    $process = proc_open([PHP_BINARY, $r9oDir . '/admin.php', $r9oRoot, $r9oDbPath, $r9oDir, $r9oKey, $r9oTokens[$who], json_encode($get), json_encode($post)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $at = strrpos($stdout, 'R9ORESULT');
    $result = $at === false ? null : json_decode(substr($stdout, $at + 9), true);
    return is_array($result) ? $result : ['code' => null, 'out' => substr($stderr . ' | ' . substr($stdout, -300), 0, 900)];
};
$r9oCheck = static function (string $label, array $r, array $expect, array $absent = []): void {
    $missing = array_values(array_filter($expect, static fn(string $t): bool => !str_contains($r['out'], $t)));
    $present = array_values(array_filter($absent, static fn(string $t): bool => str_contains($r['out'], $t)));
    assert_true($r['code'] === 200 && $missing === [] && $present === [], "{$label} (code=" . var_export($r['code'], true)
        . ' missing=' . json_encode($missing, JSON_UNESCAPED_UNICODE) . ' present=' . json_encode($present, JSON_UNESCAPED_UNICODE)
        . ($missing || $r['code'] !== 200 ? ' main=' . substr((string)(strstr($r['out'], '<main') ?: $r['out']), 0, 700) : '') . ')');
};
$r9oStores = static fn(): array => json_decode((string)file_get_contents($r9oDir . '/stores.json'), true);
$r9oForm = static function (array $legal, array $extra = []): array {
    $form = ['store_name' => 'Rubin knihy'];
    foreach (StoreSettings::LEGAL_TEXT as $field) $form[$field] = (string)($legal[$field] ?? '');
    return array_replace($form, ['payment_terms_days' => '10', 'vat_registered' => '1'], $extra);
};
try {
    $r9oCheck('R9 products: the product list has a "New product" button', $r9oRun('owner', ['p' => 'products', 'lang' => 'en']), ['data-new-product', 'href="admin.php?p=product"', 'New product']);
    $r9oCheck('R9 products: a role that cannot edit products gets no "New product" button', $r9oRun('viewer', ['p' => 'products', 'lang' => 'en']), [], ['data-new-product']);

    $r9oPage = $r9oRun('owner', ['p' => 'stores']);
    $r9oCheck('R9 store settings: the owner sees every store with its example-data warning and the menu entry',
        $r9oPage, array_merge(['p=stores', 'Stores and seller', 'data-store-status="example"', 'Woodle s.r.o.', 'SK00 1100 0000 0012 3456 7890'],
            array_map(static fn(string $id): string => 'data-store-settings="' . $id . '"', array_keys($r9oShipped))), ['data-stores-readonly']);

    $r9oBefore = (string)file_get_contents($r9oDir . '/stores.json');
    $r9oBad = $r9oRun('owner', ['p' => 'stores', 'action' => 'save', 'store' => 'default'], $r9oForm($r9oReal, ['iban' => 'SK00 1100 0000 0012 3456 7890', 'email' => 'nope']));
    $r9oCheck('R9 store settings: an invalid IBAN and e-mail are refused with reasons and the typed values stay in the form', $r9oBad,
        ['check digits are wrong', 'not a valid address', 'value="Kníhkupectvo Rubin s.r.o."']);
    assert_equals($r9oBefore, (string)file_get_contents($r9oDir . '/stores.json'), 'R9 store settings: a refused save leaves stores.json unchanged');
    $r9oCheck('R9 store settings: a VAT payer without a VAT ID is refused',
        $r9oRun('owner', ['p' => 'stores', 'action' => 'save', 'store' => 'default'], $r9oForm($r9oReal, ['ic_dph' => ''])), ['needs a VAT ID']);

    $r9oRun('owner', ['p' => 'stores', 'action' => 'save', 'store' => 'default'], $r9oForm($r9oReal + ['ico' => '55667788', 'dic' => '2120000000', 'ic_dph' => 'SK2120000000', 'bank' => 'SLSP', 'phone' => '+421 55 000 000', 'register_note' => 'OR OS KE, Sro 12/V'], ['oss_enabled' => '1']));
    $r9oAfter = $r9oStores();
    $r9oDefault = $r9oAfter['stores']['default'];
    assert_equals(['Rubin knihy', 'Kníhkupectvo Rubin s.r.o.', 'SK31 1200 0000 1987 4263 7541', 'obchod@rubin-knihy.sk', true, true, 10, '', 'eur', ''],
        [$r9oDefault['name'], $r9oDefault['legal']['name'], $r9oDefault['legal']['iban'], $r9oDefault['legal']['email'], $r9oDefault['legal']['vat_registered'],
            $r9oDefault['oss_enabled'], $r9oDefault['legal']['payment_terms_days'], $r9oDefault['legal']['invoice_prefix'], $r9oDefault['currency'], $r9oDefault['url_prefix']],
        'R9 store settings: the seller, VAT switches and payment terms are saved; currency, URL and invoice prefix stay');
    assert_equals([], Setup::legalIdentityProblems($r9oDefault['legal']), 'R9 store settings: after the save doctor accepts the seller of the default store');
    $r9oOthers = static fn(array $stores): array => array_diff_key($stores['stores'], ['default' => 1]);
    assert_equals($r9oOthers(json_decode($r9oBefore, true)), $r9oOthers($r9oAfter), 'R9 store settings: the other stores are untouched');
    $r9oAudit = $r9oPdo->query("SELECT new_value FROM audit_log WHERE entity = 'store_settings' ORDER BY id DESC")->fetchColumn();
    assert_equals('Kníhkupectvo Rubin s.r.o.', json_decode((string)$r9oAudit, true)['legal']['name'] ?? null, 'R9 store settings: the change is in the audit log');
    $r9oCheck('R9 store settings: the saved store shows as ready with the saved data',
        $r9oRun('owner', ['p' => 'stores']), ['Store default saved.', 'data-store-status="ready"', 'value="Kníhkupectvo Rubin s.r.o."']);

    $r9oDenied = $r9oRun('manager', ['p' => 'stores']);
    assert_true($r9oDenied['code'] === 403 && !str_contains($r9oDenied['out'], 'data-store-settings'), 'R9 store settings: without stores.manage (manager) the page answers 403 (code=' . var_export($r9oDenied['code'], true) . ')');
    $r9oUnchanged = (string)file_get_contents($r9oDir . '/stores.json');
    $r9oRun('manager', ['p' => 'stores', 'action' => 'save', 'store' => 'default'], $r9oForm(['name' => 'Hijack s.r.o.'] + $r9oReal));
    assert_equals($r9oUnchanged, (string)file_get_contents($r9oDir . '/stores.json'), 'R9 store settings: a POST without stores.manage changes nothing');
    $r9oCheck('R9 store settings: the menu hides the page without stores.manage', $r9oRun('manager', ['p' => 'orders']), [], ['p=stores']);
} finally {
    $r9oPdo = null;
    if ($r9oPreviousKey === false) putenv('BBE_ADMIN_IDENTITY_KEY');
    else putenv('BBE_ADMIN_IDENTITY_KEY=' . $r9oPreviousKey);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r9oDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $r9oItem) {
        $r9oItem->isDir() ? @rmdir($r9oItem->getPathname()) : @unlink($r9oItem->getPathname());
    }
    @rmdir($r9oDir);
}
