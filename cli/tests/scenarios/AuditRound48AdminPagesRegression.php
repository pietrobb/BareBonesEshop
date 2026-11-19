<?php

// Review round 18 (R48, finding 2 and low 4-7): the audit log (reports.view) showed a customer's e-mail, order ids and
// refund notes; the dashboard's job errors named orders; reports linked top products to an editor the admin could not
// open; the health panel counted every store's jobs; a currency without revenue had an axis in cents; the store badge
// listed the problems in English.
echo "\n--- R48: audit log, dashboard and reports show order and customer details only to admins allowed to see them ---\n";

$r48aRoot = test_fixture_path('r48-admin-pages');
mkdir($r48aRoot);
$r48aFixture = $r48aRoot . '/request.php';
file_put_contents($r48aFixture, <<<'PHP'
<?php
[, $phase, $root, $target, $permissions] = $argv;
require $root . '/src/autoload.php';
require $root . '/src/Entrypoints/Admin/Shared.php';
require $root . '/src/Entrypoints/Admin/Orders.php';
require $root . '/src/Entrypoints/Admin/Operations.php';
require $root . '/src/Entrypoints/Admin/Reports.php';
require $root . '/src/Entrypoints/Admin/StoreSettings.php';
class_exists('Security');
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$db = new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', dirname(__FILE__) . '/migrate.lock'))->apply();
    $db->exec("INSERT INTO categories (store_id, name, slug, path) VALUES ('default', 'R48', 'r48', 'r48')");
    $db->prepare("INSERT INTO products (id, store_id, category_id, sku, name, slug, price, stock, status) VALUES (4801, 'default', ?, 'R48-SKU', 'R48 Lamp', 'r48-lamp', 100, 5, 'active')")
        ->execute([(int)$db->lastInsertId()]);
    $db->prepare("INSERT INTO orders (id, order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id, created_at, is_synthetic)
        VALUES (4848, 'R48-ORDER-0001', 'paid', 'paid', 'R48 Buyer', 'r48-buyer@example.test', 'bank', 900, 900, 'eur', 'default', ?, 0)")->execute([gmdate('Y-m-d H:i:s', time() - 600)]);
    $db->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (4848, 4801, 'R48-SKU', 'R48 Lamp', 1, 900, 900)");
    $audit = $db->prepare("INSERT INTO audit_log (entity, entity_id, action, old_value, new_value, note, actor, ip_address, store_id) VALUES (?, ?, 'update', ?, ?, ?, 'admin:r48', '', 'default')");
    // Written before R48: the full address of the customer.
    $audit->execute(['payment_link_sent', 4848, '', 'r48-buyer@example.test', 'Payment link e-mailed by r48']);
    $audit->execute(['order_refund', 4848, '', 'succeeded', 'Refund: r48 customer complained about her divorce']);
    $audit->execute(['product', 4801, '{"price":90}', '{"price":100}', 'r48 product price']);
    $job = $db->prepare("INSERT INTO queue_jobs (id, store_id, type, payload, state, available_at, last_error, correlation_id) VALUES (?, ?, 'r48_job', '{}', ?, '2026-01-01 00:00:00', ?, ?)");
    $job->execute(['r48-own-pending', 'default', 'pending', null, 'r48c1']);
    $job->execute(['r48-own-failed', 'default', 'failed', 'Invoice failed for paid order #4848', 'r48c2']);
    foreach (range(1, 3) as $i) $job->execute(["r48-other-pending-{$i}", 'r48-other', 'retry', null, "r48o{$i}"]);
    foreach (range(1, 2) as $i) $job->execute(["r48-other-failed-{$i}", 'r48-other', 'failed', 'r48 other store failure', "r48f{$i}"]);
    exit(0);
}
StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), '/', '', 'default'));
JobQueue::useBackend(new DatabaseQueue($db));
I18n::setLocale($phase === 'issues' ? 'sk' : 'en');
$_SESSION = ['admin_role'=>'manager', 'admin_identity_id'=>1, 'admin_store_permissions'=>['default'=>explode(',', $permissions)]];
ob_start();
match ($phase) {
    'audit' => pageAudit(),
    'dashboard' => pageDashboard(),
    'reports' => pageReports(),
    'issues' => print(implode(' | ', array_map('adminStoreIdentityIssue', Setup::legalIdentityIssues(['name' => 'Woodle s.r.o.', 'email' => '', 'iban' => 'SK0011112222333344445555'])))),
};
echo ob_get_clean();
PHP);
$r48aRun = static function(array $args) use ($r48aFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r48aFixture], $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string)$stdout, (string)$stderr];
};
$r48aApp = dirname(TEST_CLI_DIR);
$r48aTarget = $r48aRoot . '/shop.sqlite';
[$r48aExit, , $r48aStderr] = $r48aRun(['setup', $r48aApp, $r48aTarget, '']);
assert_equals([0, ''], [$r48aExit, $r48aStderr], 'R48 admin pages: fixture with an order, its audit entries and two stores\' jobs');
$r48aPage = static function(string $page, string $permissions) use ($r48aRun, $r48aApp, $r48aTarget): string {
    [$exit, $stdout, $stderr] = $r48aRun([$page, $r48aApp, $r48aTarget, $permissions]);
    assert_equals([0, ''], [$exit, $stderr], "R48 {$page} ({$permissions}): renders without errors");
    return $stdout;
};

// Finding 2: the audit log.
$r48aHtml = $r48aPage('audit', 'reports.view');
foreach (['r48-buyer@example.test' => 'customer e-mail', 'divorce' => 'refund note', '<td>4848</td>' => 'order id'] as $r48aSecret => $r48aWhat) {
    assert_true(!str_contains($r48aHtml, $r48aSecret), "R48 audit (reports.view): no {$r48aWhat} (was: shown)");
}
assert_true(str_contains($r48aHtml, 'Details hidden') && str_contains($r48aHtml, '<td>4801</td>') && str_contains($r48aHtml, 'r48 product price'),
    'R48 audit (reports.view): order entries say their details are hidden; a product entry still shows in full');
$r48aHtml = $r48aPage('audit', 'reports.view,orders.view');
assert_true(str_contains($r48aHtml, '<td>4848</td>') && str_contains($r48aHtml, 'divorce') && !str_contains($r48aHtml, 'Details hidden'),
    'R48 audit (orders.view): the order entries show in full');
$r48aMask = static fn(string $email): ?string => method_exists(AuditLogLabels::class, 'maskEmail') ? AuditLogLabels::maskEmail($email) : null;
assert_equals(['j***@shop.sk', '***', '***'], [$r48aMask('jana.novak@shop.sk'), $r48aMask('@shop.sk'), $r48aMask('nobody')],
    'R48 audit: an e-mailed payment link keeps the first letter and the domain of the address');
assert_true(str_contains((string)file_get_contents($r48aApp . '/src/Entrypoints/Admin/OrderOperations.php'), "AuditLog::log('payment_link_sent', \$orderId, '', AuditLogLabels::maskEmail("),
    'R48 audit: a payment link sent from the order page writes the masked address (was: the full address)');

// Low 4 and 7: the dashboard's job errors and its health panel.
$r48aHtml = $r48aPage('dashboard', 'reports.view');
assert_true(!str_contains($r48aHtml, 'paid order #4848') && str_contains($r48aHtml, 'Details hidden'), 'R48 dashboard (reports.view): a failed job\'s error names no order (was: "…paid order #9")');
assert_true(str_contains($r48aHtml, '<strong>1 pending · 1 failed</strong>'), 'R48 dashboard: the health panel counts this store\'s jobs (was: 4 pending · 3 failed of every store)');
assert_true(str_contains($r48aPage('dashboard', 'reports.view,orders.view'), 'paid order #4848'), 'R48 dashboard (orders.view): the job error shows in full');

// Low 5: top products link to the editor only for admins who may open it.
assert_true(!str_contains($r48aPage('reports', 'reports.view'), 'p=product&amp;id=4801'), 'R48 reports (reports.view): the top product is not a link to a 403 editor');
assert_true(str_contains($r48aPage('reports', 'reports.view,products.view,products.edit'), 'p=product&amp;id=4801'), 'R48 reports (products.edit): the top product links to the editor');

// Low 6: a currency without revenue has whole units on its axis.
$r48aChart = SalesChart::dailyRevenue([date('Y-m-d') => ['orders' => 0, 'revenue' => 0], date('Y-m-d', strtotime('-1 day')) => ['orders' => 0, 'revenue' => 0]], 'pln');
preg_match_all('~class="chart-axis"[^>]*>([^<]*)<~', $r48aChart, $r48aAxis);
assert_true(count($r48aAxis[1]) === 5 && count(array_unique($r48aAxis[1])) === 5 && !preg_grep('~0,0[1-9]~', $r48aAxis[1]),
    'R48 chart: a currency without revenue has an axis in whole units (was: 0,00 zł, 0,00 zł, 0,01 zł…) — ' . json_encode($r48aAxis[1], JSON_UNESCAPED_UNICODE));

// Low 7: KPI tiles without a link do not react to the pointer; the store badge speaks the admin's language.
$r48aCss = (string)file_get_contents($r48aApp . '/src/Entrypoints/Admin.php');
assert_true(str_contains($r48aCss, 'a.kpi:hover{') && !preg_match('~(^|[\s},])\.kpi:hover~', $r48aCss), 'R48 dashboard: only a linked KPI tile has a hover state');
[$r48aExit, $r48aIssues, $r48aStderr] = $r48aRun(['issues', $r48aApp, $r48aTarget, '']);
assert_equals([0, '', true, false], [$r48aExit, $r48aStderr, str_contains($r48aIssues, 'ukážková hodnota „Woodle s.r.o.“') && str_contains($r48aIssues, 'chýba'), (bool)preg_match('~company name|missing|check digits~', $r48aIssues)],
    'R48 stores: the example-data badge names the fields in the admin\'s language (was: English) — ' . $r48aIssues);
