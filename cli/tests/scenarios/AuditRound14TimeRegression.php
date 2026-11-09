<?php

// Review round 14 (kolo 6), still open from round 3: T12 the admin printed stored UTC times as they are; T13 feed
// schedules with a wildcard minute or hour lost their runs in the hour the autumn change repeats (and caught up a
// skipped one in spring, which cron does only for fixed-time jobs); T15 the logger left HTTP Basic credentials without
// digits, quoted passwords with spaces and URL passwords with "@" readable; UX6 dates were ISO in every language.
echo "\n--- Audit round 14: admin times, cron at DST, log masking, local dates ---\n";

$r14tFail = static function (string $label, Throwable $e): void {
    assert_true(false, "R14 time {$label}: " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
};

// T12: the order list of a store in Europe/Bratislava shows an order placed 2026-07-01 22:30 UTC at 00:30 on 2 July.
$r14tRoot = test_fixture_path('r14-admin-time');
mkdir($r14tRoot);
$r14tFixture = $r14tRoot . '/request.php';
file_put_contents($r14tFixture, <<<'PHP'
<?php
require $argv[2] . '/src/autoload.php';
require $argv[2] . '/src/Entrypoints/Admin/Shared.php';
require $argv[2] . '/src/Entrypoints/Admin/Orders.php';
require $argv[2] . '/src/Entrypoints/Admin/Reports.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
$db->exec("CREATE TABLE orders (id INTEGER PRIMARY KEY, store_id VARCHAR(40) NOT NULL,
 order_number VARCHAR(100) NOT NULL, customer_name VARCHAR(100) NOT NULL, customer_email VARCHAR(100) NOT NULL,
 total INTEGER NOT NULL, currency VARCHAR(10) NOT NULL, status VARCHAR(20) NOT NULL,
 payment_status VARCHAR(20) NOT NULL, payment_method VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL, is_synthetic INTEGER NOT NULL DEFAULT 0)");
$db->exec("INSERT INTO orders VALUES (5, 'default', 'R14T-1', 'R14 Time', 'r14t@example.test', 1000, 'eur', 'pending', 'pending', 'bank', '2026-07-01 22:30:00', 0)");
$db->exec("CREATE TABLE order_items (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL)");
$db->exec("CREATE TABLE digital_entitlements (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, download_count INTEGER NOT NULL)");
$db->exec("CREATE TABLE email_log (id INTEGER PRIMARY KEY, order_id INTEGER NULL, status VARCHAR(20) NOT NULL, adapter VARCHAR(50) NOT NULL DEFAULT 'smtp')");
$db->exec("CREATE TABLE merchant_notifications (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, status VARCHAR(20) NOT NULL)");
$db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, store_id VARCHAR(40) NOT NULL, order_id INTEGER NOT NULL, number VARCHAR(100) NOT NULL, pdf_path VARCHAR(100) NOT NULL)");
$stores = Bootstrap::stores();
$stores['stores']['default']['timezone'] = 'Europe/Bratislava';
StoreContext::activate(StoreContext::resolve($stores, '/', '', 'default'));
I18n::setLocale($argv[1]);
$_SESSION = ['admin_role'=>'manager', 'admin_identity_id'=>1, 'admin_store_permissions'=>['default'=>['orders.view','orders.edit']]];
$_GET = ['period'=>'all'];
ob_start();
register_shutdown_function(static function(): void {
    $output = ob_get_contents();
    ob_clean();
    print json_encode(['output'=>$output], JSON_THROW_ON_ERROR);
    ob_end_flush();
});
pageOrders();
PHP);
try {
    foreach (['sk' => '2. 7. 2026 00:30', 'de' => '02.07.2026 00:30', 'en' => '2 Jul 2026, 00:30'] as $r14tLocale => $r14tExpected) {
        $r14tProcess = proc_open([PHP_BINARY, $r14tFixture, $r14tLocale, dirname(TEST_CLI_DIR)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r14tPipes);
        fclose($r14tPipes[0]);
        $r14tOut = stream_get_contents($r14tPipes[1]);
        $r14tErr = stream_get_contents($r14tPipes[2]);
        fclose($r14tPipes[1]);
        fclose($r14tPipes[2]);
        $r14tExit = proc_close($r14tProcess);
        $r14tHtml = (string)(json_decode((string)$r14tOut, true)['output'] ?? '');
        assert_true($r14tExit === 0 && $r14tErr === '' && str_contains($r14tHtml, 'R14T-1') && str_contains($r14tHtml, $r14tExpected) && !str_contains($r14tHtml, '2026-07-01 22:30'),
            "R14 T12/UX6 admin ({$r14tLocale}): the order time is the shop's local time in the language's format, {$r14tExpected} (exit {$r14tExit}"
            . ($r14tErr !== '' ? ', ' . substr($r14tErr, 0, 200) : '') . ')');
    }
} catch (Throwable $e) { $r14tFail('T12 admin', $e); }
// No admin page prints a stored timestamp cut to "Y-m-d H:i" or as ISO 8601 any more.
$r14tRaw = [];
foreach (glob(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/*.php') ?: [] as $r14tFile) {
    foreach (file($r14tFile) ?: [] as $r14tNo => $r14tLine) {
        if (preg_match("/substr\\([^;]*_at'\\][^;]*, 0, 1[06]\\)|gmdate\\('c'|esc\\(\\\$\\w+\\['\\w+_at'\\]\\)|date\\('Y-m-d H:i'/", $r14tLine)
            && !str_contains($r14tLine, 'I18n::date')) {
            $r14tRaw[] = basename($r14tFile) . ':' . ($r14tNo + 1);
        }
    }
}
assert_equals([], $r14tRaw, 'R14 T12: admin pages format stored times through I18n::dateTime / I18n::date');

// UX6: calendar dates (invoice, transfer due day, legal texts) in the language's format, without a time zone shift.
try {
    assert_equals(['3. 11. 2026', '03.11.2026', '3 Nov 2026', '2026. 11. 03.', 'not a date'],
        [I18n::date('2026-11-03', 'sk'), I18n::date('2026-11-03', 'de'), I18n::date('2026-11-03', 'en'), I18n::date('2026-11-03', 'hu'), I18n::date('not a date', 'sk')],
        'R14 UX6: I18n::date prints a calendar date in the locale\'s format and leaves anything else as it is');
    $r14tTerms = ConsumerNotice::renderLegal('terms', 'sk', ['name' => 'R14 s.r.o.', 'email' => 'r14@example.test', 'updated_at' => '2026-11-03']);
    assert_true(str_contains($r14tTerms, '3. 11. 2026') && !str_contains($r14tTerms, '2026-11-03'), 'R14 UX6: the legal texts say when they changed in the language\'s format');
    $r14tOrder = ['id' => 9140, 'store_id' => StoreContext::current()->id(), 'order_number' => 'R14T-BT', 'payment_method' => 'bank_transfer',
        'payment_status' => 'pending', 'status' => 'pending', 'total' => 1990, 'currency' => 'eur', 'locale' => 'de', 'created_at' => gmdate('Y-m-d H:i:s')];
    $r14tBank = BankTransferInstructions::forOrder($r14tOrder, ['name' => 'R14 s.r.o.', 'iban' => 'SK31 1200 0000 1987 4263 7541']);
    assert_true(is_array($r14tBank) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$r14tBank['due_date']) === 1
        && $r14tBank['due_date_display'] === I18n::date((string)$r14tBank['due_date']),
        'R14 UX6: the transfer instructions keep the ISO due date for the API and add the local one for the page');
    if (BankTransferInstructions::storeIban() !== '') {
        $r14tMail = BankTransferInstructions::mailVars($r14tOrder);
        assert_equals(I18n::date((string)$r14tBank['due_date'], 'de'), $r14tMail['bank_due_date'] ?? '', 'R14 UX6: the transfer e-mail prints the due date in the order\'s language');
    } else {
        print "  [SKIP] R14 UX6 transfer e-mail due date (the test store has no IBAN)\n";
    }
} catch (Throwable $e) { $r14tFail('UX6 dates', $e); }

// T13: feed schedules through the clock changes of Europe/Bratislava, the way cron runs them.
try {
    $r14tIsDue = new ReflectionMethod(FeedImport::class, 'isDue');
    $r14tDue = static function (string $schedule, int $now, string $lastSync) use ($r14tIsDue): bool {
        ShopTime::setNowForTesting($now);
        try { return (bool)$r14tIsDue->invoke(null, $schedule, $lastSync); } finally { ShopTime::setNowForTesting(null); }
    };
    assert_equals('Europe/Bratislava', ShopTime::zone()->getName(), 'R14 T13: the test store runs on Europe/Bratislava');
    // 2026-10-25: 03:00 CEST becomes 02:00 CET at 01:00 UTC; 02:00–02:59 local happens twice.
    assert_equals([true, true], [
        $r14tDue('0 * * * *', gmmktime(1, 5, 0, 10, 25, 2026), '2026-10-25 00:30:00'),    // 02:05 CET, synced 02:30 CEST
        $r14tDue('*/15 * * * *', gmmktime(1, 5, 0, 10, 25, 2026), '2026-10-25 00:50:00'), // 02:05 CET, synced 02:50 CEST
    ], 'R14 T13: hourly and */15 feeds also run in the repeated hour of the autumn change (they used to skip it)');
    assert_equals([true, false], [
        $r14tDue('30 2 * * *', gmmktime(0, 45, 0, 10, 25, 2026), '2026-10-24 23:00:00'),
        $r14tDue('30 2 * * *', gmmktime(1, 45, 0, 10, 25, 2026), '2026-10-25 00:31:00'),
    ], 'R14 T13: a fixed 02:30 feed still runs once on the autumn change');
    // 2026-03-29: 02:00 CET becomes 03:00 CEST at 01:00 UTC; 02:00–02:59 local never happens.
    assert_equals([false, true], [
        $r14tDue('30 * * * *', gmmktime(1, 5, 0, 3, 29, 2026), '2026-03-29 00:31:00'),    // 03:05 CEST: no catch-up of 02:30
        $r14tDue('30 2 * * *', gmmktime(1, 5, 0, 3, 29, 2026), '2026-03-28 01:31:00'),    // fixed time: runs after the change
    ], 'R14 T13: on the spring change only a fixed-time feed catches up the skipped time, an hourly one does not');
} catch (Throwable $e) { $r14tFail('T13 cron', $e); }

// T15: secrets the logger used to leave readable.
try {
    $r14tLogged = Logger::redact([
        'basic' => 'Authorization: Basic dXNlcjpwYXNzd29yZA',   // base64 of user:password, letters only
        'short' => 'curl -H "Authorization: Basic YTpi"',          // a:b
        'header' => 'Authorization: Bearer abc',
        'url' => 'smtp://shop:p@ssw@rd@mail.example.com:587/x',
        'json' => '{"password":"correct horse battery","user":"r14"}',
        'yaml' => 'smtp_password: correct horse battery staple',
        'kv' => 'password=my secret pass user=bob',
        'plan' => 'Basic shipping plan',
    ]);
    $r14tLeaks = array_filter(['dXNlcjpwYXNzd29yZA', 'YTpi', 'Bearer abc', 'ssw@rd', 'p@ss', 'horse', 'battery', 'secret pass'],
        static fn(string $s): bool => str_contains(implode("\n", $r14tLogged), $s));
    assert_equals([], array_values($r14tLeaks), 'R14 T15 logs: Basic credentials without digits, short ones, URL passwords with "@" and passwords with spaces are masked');
    assert_equals(['smtp://shop:[REDACTED]@mail.example.com:587/x', '{"password":"[REDACTED]","user":"r14"}', 'password=[REDACTED] user=bob', 'Basic shipping plan'],
        [$r14tLogged['url'], $r14tLogged['json'], $r14tLogged['kv'], $r14tLogged['plan']],
        'R14 T15 logs: user, host, the other keys and the word "Basic" stay readable');
} catch (Throwable $e) { $r14tFail('T15 logger', $e); }
