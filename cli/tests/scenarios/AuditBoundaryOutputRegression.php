<?php
/**
 * Audit 2026-10-12 / A10.3 / low findings (output boundaries):
 *  - MinimalPdf pre-mapped "€" to a raw 0x80 byte before iconv, which made the string invalid UTF-8,
 *    so the euro sign vanished from every invoice (Windows libiconv and Linux glibc alike);
 *  - invoices with more than ~45 lines kept writing below the A4 page edge (items/totals lost);
 *  - admin.php?p=invoice_pdf streamed the PDF after the admin HTML layout (corrupt download) and was
 *    gated by reports.view, so fulfillment staff could not open invoices;
 *  - the admin GDPR export buttons linked to cli/gdpr-export.php (HTTP-denied CLI script) instead of
 *    an authorised, CSRF-protected admin action;
 *  - an authenticated admin request with p[]=… ended in a TypeError (array page key).
 * The PDF cases run through the real InvoiceService renderer; admin cases run src/Entrypoints/Admin.php
 * in a child process with a real admin session.
 */

echo "\n--- Audit A10.3: invoice PDF euro/pagination, admin PDF + GDPR export transport ---\n";

// --- MinimalPdf: CP1252 encoding keeps the euro sign (0x80) next to other CP1252 characters. ---
$a103Pdf = new MinimalPdf();
$a103Pdf->text(20, 20, 'Cena: 12,50 € — Košeľa');
$a103Rendered = $a103Pdf->render();
preg_match('/\((Cena[^)]*)\) Tj/', $a103Rendered, $a103M);
// "ľ" is outside CP1252: R9 gives it the first undefined CP1252 byte (0x81) via /Differences instead of transliterating it to "l".
assert_equals(bin2hex("Cena: 12,50 \x80 \x97 Ko\x9Ae\x81a"), bin2hex($a103M[1] ?? ''), 'A10.3 PDF: "€" is encoded as CP1252 0x80 (not dropped), other text intact');
assert_true(str_contains($a103Rendered, '/Differences [129 /lcaron]'), 'A10.3 PDF: the extra letter "ľ" is mapped to its own glyph');

// --- Long invoice: 60 lines paginate, every text baseline stays on the A4 page, nothing is lost. ---
$a103Db = Db::get();
$a103StorageProp = new ReflectionProperty(InvoiceService::class, 'storageDir');
$a103OldStorage = $a103StorageProp->getValue();
$a103Storage = test_fixture_path('a103-invoices');
mkdir($a103Storage);
$a103Storage = realpath($a103Storage);
$a103StorageProp->setValue(null, $a103Storage);
$a103Db->exec("DELETE FROM order_items WHERE order_id = 9431");
$a103Db->exec("DELETE FROM invoices WHERE order_id = 9431");
$a103Db->exec("DELETE FROM orders WHERE id = 9431");
$a103Db->prepare("INSERT INTO orders (id, store_id, order_number, status, customer_name, customer_email, customer_phone, shipping_addr, payment_method,
    subtotal, discount_amount, shipping_cost, tax_amount, total, currency, locale, created_at)
    VALUES (9431, 'default', 'A103-LONG', 'paid', 'Long Invoice', 'a103-long@example.com', '', ?, 'bank_transfer', 60000, 0, 500, 0, 60500, 'eur', 'sk', datetime('now'))")
    ->execute([json_encode(['street' => 'Dlhá 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'])]);
$a103Db->exec("DELETE FROM products WHERE id = 9431");
$a103Db->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock) VALUES (9431, ?, 1, 'A103 line product', 'a103-line', 'A103-LINE', 'simple', 1000, 'eur', 'active', 100)")
    ->execute([StoreContext::current()->catalogId()]);
$a103Item = $a103Db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (9431, 9431, ?, ?, 1, 1000, 1000)");
for ($i = 1; $i <= 60; $i++) $a103Item->execute([sprintf('A103-%02d', $i), sprintf('A103 riadok %02d', $i)]);
$a103Invoice = InvoiceService::issue(9431);
$a103Raw = (string)file_get_contents(InvoiceService::pdfAbsolutePath($a103Invoice));
preg_match('/\/Type \/Pages \/Kids \[[^\]]*\] \/Count (\d+)/', $a103Raw, $a103Count);
assert_true((int)($a103Count[1] ?? 0) >= 2, 'A10.3 PDF: a 60-line invoice spans more than one page (Count=' . ($a103Count[1] ?? '?') . ')');
preg_match_all('/Tf (-?[\d.]+) (-?[\d.]+) Td \(([^)]*)\) Tj/', $a103Raw, $a103Texts, PREG_SET_ORDER);
$a103Low = array_filter($a103Texts, static fn(array $t): bool => (float)$t[2] < 28.0);
assert_equals([], array_values(array_map(static fn(array $t): string => $t[3] . '@' . $t[2], $a103Low)), 'A10.3 PDF: no text is placed below the bottom page margin');
$a103Strings = array_column($a103Texts, 3);
$a103Missing = [];
for ($i = 1; $i <= 60; $i++) if (!in_array(sprintf('A103 riadok %02d', $i), $a103Strings, true)) $a103Missing[] = $i;
assert_equals([], $a103Missing, 'A10.3 PDF: every invoice line is printed');
assert_true((bool)array_filter($a103Strings, static fn(string $s): bool => str_contains($s, '605') && str_contains($s, "\x80")),
    'A10.3 PDF: the gross total with the euro sign is printed after the paginated lines');
$a103InvoiceId = (int)$a103Invoice['id'];

// --- Admin router: invoice PDF before any HTML; GDPR export as an admin action; array page key. ---
foreach ($GLOBALS as $a103Value) if ($a103Value instanceof PDOStatement) $a103Value->closeCursor();
$a103Store = StoreContext::current()->id();
if (!$a103Db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'gdpr_events'")->fetchColumn()) {
    // The suite DB already has cookie_consent (created by an earlier scenario); gdpr_events is created first,
    // so the migration's later "table exists" error is expected here.
    try { (require dirname(TEST_CLI_DIR) . '/migrations/20260425033813_add_gdpr_tables.php')->up($a103Db, true); } catch (PDOException $e) {}
    (require dirname(TEST_CLI_DIR) . '/migrations/20261012180000_gdpr_events_store.php')->up($a103Db, true);
}
$a103Db->exec("DELETE FROM customers WHERE email IN ('a103-gdpr@example.com', 'a103-foreign@example.com')");
$a103Db->prepare("INSERT INTO customers (store_id, email, name, status, password_hash) VALUES (?, 'a103-gdpr@example.com', 'A103 Gdpr', 'active', 'x')")->execute([$a103Store]);
$a103CustomerId = (int)$a103Db->lastInsertId();
$a103Db->exec("INSERT INTO customers (store_id, email, name, status, password_hash) VALUES ('a103-other-store', 'a103-foreign@example.com', 'A103 Foreign', 'active', 'x')");
$a103ForeignId = (int)$a103Db->lastInsertId();

$a103Root = test_fixture_path('a103-admin');
mkdir($a103Root);
mkdir($a103Root . '/sessions');
file_put_contents($a103Root . '/admin.php', <<<'PHP'
<?php
[, $root, $dbPath, $dir, $storage, $role, $method, $getJson, $postJson] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
JobQueue::setDir($dir . '/jobs');
(new ReflectionProperty(InvoiceService::class, 'storageDir'))->setValue(null, $storage);
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = ['csrf_token' => 'a103', 'admin_user' => 'a103-' . $role, 'admin_role' => $role,
    'admin_authenticated' => true, 'admin_last_seen' => time()];
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REMOTE_ADDR'] = '127.0.10.3';
$_GET = json_decode($getJson, true);
$_POST = json_decode($postJson, true);
ob_start();
register_shutdown_function(static function(): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nA103RESULT" . json_encode(['code' => http_response_code(), 'flash' => $_SESSION['flash'] ?? null,
        'out' => base64_encode($out)]);
});
require $root . '/src/Entrypoints/Admin.php';
PHP);
$a103Run = static function (string $role, string $method, array $get, array $post = []) use ($a103Root, $testDbPath, $a103Storage): array {
    $process = proc_open([PHP_BINARY, $a103Root . '/admin.php', dirname(TEST_CLI_DIR), $testDbPath, $a103Root, $a103Storage, $role, $method,
        json_encode($get), json_encode($post)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $at = strrpos($raw, 'A103RESULT');
    $result = $at === false ? null : json_decode(substr($raw, $at + 10), true);
    if (!is_array($result)) return ['code' => null, 'flash' => null, 'out' => '', 'raw' => substr($raw, 0, 600)];
    $result['out'] = (string)base64_decode((string)$result['out']);
    $result['raw'] = $at > 0 ? substr($raw, 0, min($at, 600)) : '';
    return $result;
};
$a103Label = static fn(string $label, array $r): string => $label . ' (code=' . var_export($r['code'], true) . ' flash=' . json_encode($r['flash'])
    . ' head=' . json_encode(substr($r['out'], 0, 80)) . ($r['raw'] !== '' ? ' raw=' . $r['raw'] : '') . ')';
$a103Fatal = static fn(array $r): bool => $r['code'] === null || $r['code'] === 500 || preg_match('/Fatal error|TypeError|Uncaught/', $r['out'] . $r['raw']) === 1;
$a103Events = static function () use ($testDbPath, $a103CustomerId): array {
    $read = new PDO('sqlite:' . $testDbPath);
    $stmt = $read->prepare("SELECT action, actor FROM gdpr_events WHERE customer_id = ? ORDER BY id");
    $stmt->execute([$a103CustomerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

try {
    $r = $a103Run('fulfillment', 'GET', ['p' => 'invoice_pdf', 'id' => (string)$a103InvoiceId]);
    assert_true(str_starts_with($r['out'], '%PDF-') && !str_contains($r['out'], '<!DOCTYPE') && str_ends_with(rtrim($r['out']), '%%EOF'),
        $a103Label('A10.3 admin PDF: invoice_pdf returns only the PDF bytes, no admin HTML before/after', $r));
    assert_true($r['out'] === $a103Raw, 'A10.3 admin PDF: the streamed body is byte-identical to the stored invoice PDF');
    $r = $a103Run('fulfillment', 'GET', ['p' => 'invoice_pdf', 'id' => '99999999']);
    assert_true($r['code'] === 404 && !str_contains($r['out'], '<!DOCTYPE'), $a103Label('A10.3 admin PDF: a missing invoice is a plain 404', $r));
    $r = $a103Run('editor', 'GET', ['p' => 'invoice_pdf', 'id' => (string)$a103InvoiceId]);
    assert_true($r['code'] === 403 && !str_contains($r['out'], '%PDF-'), $a103Label('A10.3 admin PDF: a role without orders.view gets 403 and no PDF', $r));

    foreach ([['customer', ['p' => 'customer', 'id' => (string)$a103CustomerId]], ['gdpr', ['p' => 'gdpr', 'email' => 'a103-gdpr@example.com']]] as [$a103Page, $a103Get]) {
        $r = $a103Run('owner', 'GET', $a103Get);
        assert_true(!$a103Fatal($r) && !str_contains($r['out'], 'cli/gdpr-export.php') && str_contains($r['out'], 'action=export'),
            $a103Label("A10.3 GDPR export: admin {$a103Page} page offers the admin export action, not the CLI script link", $r));
    }
    $a103Before = count($a103Events());
    $r = $a103Run('manager', 'POST', ['p' => 'gdpr', 'action' => 'export'], ['csrf_token' => 'a103', 'customer_id' => (string)$a103CustomerId]);
    $a103Json = json_decode($r['out'], true);
    assert_true(is_array($a103Json) && ($a103Json['customer']['email'] ?? null) === 'a103-gdpr@example.com' && !isset($a103Json['customer']['password_hash']),
        $a103Label('A10.3 GDPR export: manager downloads the customer JSON export (password hash redacted)', $r));
    $a103Logged = array_slice($a103Events(), $a103Before);
    assert_equals([['action' => 'export', 'actor' => 'a103-manager']], $a103Logged, 'A10.3 GDPR export: the admin export is audited with the admin as actor');
    $r = $a103Run('manager', 'POST', ['p' => 'gdpr', 'action' => 'export'], ['csrf_token' => 'wrong', 'customer_id' => (string)$a103CustomerId]);
    assert_true(!str_contains($r['out'], 'a103-gdpr@example.com"') && json_decode($r['out'], true) === null, $a103Label('A10.3 GDPR export: a bad CSRF token exports nothing', $r));
    $r = $a103Run('manager', 'POST', ['p' => 'gdpr', 'action' => 'export'], ['csrf_token' => 'a103', 'customer_id' => (string)$a103ForeignId]);
    assert_true(!str_contains($r['out'], 'a103-foreign@example.com') && !$a103Fatal($r), $a103Label('A10.3 GDPR export: a customer of another store is not exported', $r));
    $r = $a103Run('fulfillment', 'POST', ['p' => 'gdpr', 'action' => 'export'], ['csrf_token' => 'a103', 'customer_id' => (string)$a103CustomerId]);
    assert_true($r['code'] === 403 && !str_contains($r['out'], 'a103-gdpr@example.com'), $a103Label('A10.3 GDPR export: a role without gdpr.view gets 403', $r));
    assert_equals(1, count(array_slice($a103Events(), $a103Before)), 'A10.3 GDPR export: rejected exports are not logged as exports');

    $r = $a103Run('owner', 'GET', ['p' => ['x' => 'orders'], 'action' => ['y'], 'id' => ['1']]);
    assert_true(!$a103Fatal($r) && str_contains($r['out'], '<main>'), $a103Label('A10.3 admin router: p[]/action[] arrays fall back to the dashboard without a TypeError', $r));
} finally {
    $a103StorageProp->setValue(null, $a103OldStorage);
    $a103Db->exec("DELETE FROM customers WHERE email IN ('a103-gdpr@example.com', 'a103-foreign@example.com')");
}
