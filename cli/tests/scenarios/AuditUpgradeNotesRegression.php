<?php

// Upgrade-notes follow-up (2026-10-16): the CHANGELOG upgrade notes and doctor/preflight warnings for
// settings that silently change behaviour, review dedupe that cannot lock out a CDN-shared IP, no free
// cart lines from a negative price, UTF-8 e-mail normalisation, the public feed cache fingerprint/lock,
// outbox skips that do not burn attempts, and truncated CSV exports that are reported.
echo "\n--- Audit upgrade notes: doctor checks, reviews, cart prices, e-mail, feed cache, outbox, exports ---\n";
foreach ($GLOBALS as $aunValue) if ($aunValue instanceof PDOStatement) $aunValue->closeCursor();
$aunApp = dirname(TEST_CLI_DIR);
$aunRoot = test_fixture_path('upgrade-notes');
@mkdir($aunRoot, 0700, true);
Bootstrap::initStoreContext('/', '', 'default');
if (!function_exists('reviewDuplicateBuckets')) require_once $aunApp . '/src/Http/ApiHandlers.php';

try {
// ── 1. Upgrade notes and docs ────────────────────────────────────────────────────────────────────
$aunChangelog = (string)file_get_contents($aunApp . '/CHANGELOG.md');
$aunNotes = substr($aunChangelog, (int)strpos($aunChangelog, '### Upgrade notes'), (int)strpos($aunChangelog, '### Added') - (int)strpos($aunChangelog, '### Upgrade notes'));
foreach ([
    'try_files $uri @redirect;', '/cs/api.php', 'allow_insecure_http', 'cart_reminder', 'silently stops sending',
    '<invoice_prefix>CN-', 'paid order', 'legal.seller', "'csrf' => false", 'import_mode', '<digital_assets_base_dir>/<catalog_id>/',
    'unknown `?page=`', 'X-Shipping-Timestamp', 'shipping_rates', 'destination', 'SMTP_ALLOW_PLAINTEXT_AUTH', 'SendRawEmail',
    'rate_limited', 'ext:<code>:<name>', '/page/about', 'adapters/uploads/', 'mb_strtolower',
    '20261016140000_csp_request_id', '20261016140500_invoice_number_counters', '20261016141000_slug_registry_product_store',
    '20261016142000_cart_reminder_consent_download_resume', '20261016150000_normalize_coupon_use_emails',
] as $aunNeedle) {
    assert_true(str_contains($aunNotes, $aunNeedle), "Upgrade notes: the Unreleased upgrade notes cover {$aunNeedle}");
}
foreach (glob($aunApp . '/migrations/2026101*.php') ?: [] as $aunMigration) {
    if (basename($aunMigration) < '20261015') continue;
    assert_true(str_contains($aunNotes, basename($aunMigration, '.php')), 'Upgrade notes: migration ' . basename($aunMigration) . ' is listed');
}
$aunGdpr = (string)file_get_contents($aunApp . '/docs/design/gdpr.md');
assert_true(!str_contains($aunGdpr, 'cart_recovery cookie') && str_contains($aunGdpr, 'checkout consent `cart_reminder`'), 'Upgrade notes: gdpr.md names the cart_reminder checkout consent as the legal basis');
assert_true(str_contains((string)file_get_contents($aunApp . '/README.md'), 'try_files $uri @redirect;'), 'Upgrade notes: README keeps the nginx PHP fallback');

// ── 2. Doctor / preflight warnings ───────────────────────────────────────────────────────────────
$aunSecret = str_repeat('s', 40);
$aunHooks = ['outbound_webhooks' => [
    'legacy' => ['url' => 'http://erp.example/hook', 'secret' => $aunSecret],
    'local' => ['url' => 'http://127.0.0.1:9000/hook', 'secret' => $aunSecret, 'allow_insecure_http' => true],
    'tls' => ['url' => 'https://erp.example/hook', 'secret' => $aunSecret],
    'off' => ['url' => 'http://old.example/hook', 'secret' => $aunSecret, 'active' => false],
]];
assert_equals(['legacy'], array_keys(UpgradeChecks::insecureHttpWebhooks($aunHooks)), 'Doctor: only active plain-http endpoints without the opt-out are reported');
assert_true(UpgradeChecks::cartReminderWithoutConsent(['cart_reminder' => ['enabled' => true]]), 'Doctor: enabled cart reminders without a consent are reported');
assert_true(UpgradeChecks::cartReminderWithoutConsent(['cart_reminder' => ['enabled' => true], 'checkout_consents' => ['cart_reminder' => ['version' => '1', 'text' => 'x', 'active' => false]]]), 'Doctor: an inactive consent counts as missing');
assert_true(!UpgradeChecks::cartReminderWithoutConsent(['cart_reminder' => ['enabled' => true], 'checkout_consents' => ['cart_reminder' => ['version' => '1', 'text' => 'Remind me']]]), 'Doctor: a configured consent passes');
assert_true(!UpgradeChecks::cartReminderWithoutConsent(['cart_reminder' => ['enabled' => false]]), 'Doctor: disabled reminders need no consent');
$aunOldNginx = "server {\n    location / { try_files \$uri @redirect; }\n    location @redirect { rewrite ^/(.+)\$ /redirect.php?path=/\$1 last; return 404; }\n    location ~ \\.php\$ {\n        # try_files \$uri @redirect;\n        include fastcgi_params;\n        fastcgi_pass php:9000;\n    }\n}\n";
assert_true(UpgradeChecks::nginxConfHasPhpFallback((string)file_get_contents($aunApp . '/deploy/nginx.conf')) === true, 'Doctor: the shipped nginx sample has the .php @redirect fallback');
assert_true(UpgradeChecks::nginxConfHasPhpFallback($aunOldNginx) === false, 'Doctor: an old sample (fallback only in a comment) is detected');
assert_true(UpgradeChecks::nginxConfHasPhpFallback("server { location / { root /x; } }") === null, 'Doctor: a config without a PHP location is not determinable');
assert_true(UpgradeChecks::nginxPhpFallback([]) === null && UpgradeChecks::nginxPhpFallback(['operations' => ['nginx_config' => $aunRoot . '/missing.conf']]) === null, 'Doctor: no or unreadable nginx_config is skipped');
file_put_contents($aunRoot . '/site.conf', $aunOldNginx);
assert_true(UpgradeChecks::nginxPhpFallback(['operations' => ['nginx_config' => $aunRoot . '/site.conf']]) === false, 'Doctor: a configured nginx file without the fallback is reported');
$aunDoctorCfg = array_replace(require $aunApp . '/config.php', $aunHooks, [
    'cart_reminder' => ['enabled' => true], 'checkout_consents' => [], 'operations' => ['nginx_config' => $aunRoot . '/site.conf'],
]);
$aunDoctor = [];
foreach (Setup::doctor($aunDoctorCfg, $aunApp, Db::get())['checks'] as $aunCheck) $aunDoctor[$aunCheck['name']] = $aunCheck;
assert_true(($aunDoctor['upgrade.webhook_http']['status'] ?? '') === 'warn' && str_contains($aunDoctor['upgrade.webhook_http']['message'], 'legacy'), 'Doctor: setup doctor warns about the http endpoint');
assert_equals('warn', $aunDoctor['upgrade.cart_reminder_consent']['status'] ?? null, 'Doctor: setup doctor warns about reminders without consent');
assert_equals('warn', $aunDoctor['upgrade.nginx_php_fallback']['status'] ?? null, 'Doctor: setup doctor warns about the nginx sample without the fallback');
$aunDoctorOk = [];
foreach (Setup::doctor(array_replace($aunDoctorCfg, ['outbound_webhooks' => [], 'cart_reminder' => ['enabled' => false], 'operations' => []]), $aunApp, Db::get())['checks'] as $aunCheck) $aunDoctorOk[$aunCheck['name']] = $aunCheck['status'];
assert_true(($aunDoctorOk['upgrade.webhook_http'] ?? '') === 'pass' && ($aunDoctorOk['upgrade.cart_reminder_consent'] ?? '') === 'pass' && !isset($aunDoctorOk['upgrade.nginx_php_fallback']),
    'Doctor: a clean config passes and an unknown nginx config is skipped');
$aunPreflight = (string)file_get_contents($aunApp . '/cli/staging-preflight.php');
foreach (['UpgradeChecks::insecureHttpWebhooks($cfg)', 'UpgradeChecks::cartReminderWithoutConsent($cfg)', 'UpgradeChecks::nginxPhpFallback($cfg)'] as $aunCall) {
    assert_true(str_contains($aunPreflight, $aunCall), "Preflight: staging preflight runs {$aunCall}");
}
assert_true(str_contains((string)file_get_contents($aunApp . '/config/config.schema.json'), '"nginx_config"'), 'Doctor: operations.nginx_config is in the config schema');

// ── 3. Review dedupe: per session for guests, check-then-record ──────────────────────────────────
$aunRateProp = new ReflectionProperty(Security::class, 'rateDb');
$aunRateProp->setAccessible(true);
$aunPreviousRateDb = $aunRateProp->getValue();
$aunRate = new PDO('sqlite::memory:');
Security::setRateDbForTesting($aunRate);
$aunHits = static fn(): int => (int)$aunRate->query('SELECT COUNT(*) FROM hits')->fetchColumn();
assert_true(Security::rateLimitAllows('aun_bucket', 1, 3600, false), 'Reviews: an unused bucket allows');
assert_equals(0, $aunHits(), 'Reviews: checking records nothing');
Security::rateLimitRecord('aun_bucket', false);
assert_true(!Security::rateLimitAllows('aun_bucket', 1, 3600, false), 'Reviews: a recorded hit fills a 1-hit bucket');
for ($aunI = 0; $aunI < 5; $aunI++) Security::rateLimitAllows('aun_bucket', 1, 3600, false);
assert_equals(1, $aunHits(), 'Reviews: refused attempts do not record hits (no rolling block)');
$aunGuestA = reviewDuplicateBuckets(7, 0, 'session-a');
$aunGuestB = reviewDuplicateBuckets(7, 0, 'session-b');
assert_true($aunGuestA[0][0] !== $aunGuestB[0][0] && $aunGuestA[0][1] === 1 && $aunGuestA[0][2] === false, 'Reviews: guests are keyed by their session, not only the shared IP');
assert_true(!str_contains($aunGuestA[0][0], 'session-a'), 'Reviews: the session id is hashed in the bucket key');
assert_equals(['review_once_7_ip', Reviews::GUEST_IP_CAP, true], $aunGuestA[1], 'Reviews: guests also get a coarse per-IP cap');
assert_equals([['review_once_7_c5', 1, false]], reviewDuplicateBuckets(7, 5, 'session-a'), 'Reviews: customers are keyed by account');
assert_equals(1, count(reviewDuplicateBuckets(7, 0, '')), 'Reviews: without a session only the IP cap applies');
foreach ($aunGuestA as [$aunBucket, , $aunBindIp]) Security::rateLimitRecord($aunBucket, $aunBindIp);
assert_true(!Security::rateLimitAllows($aunGuestA[0][0], 1, Reviews::DUPLICATE_WINDOW, false), 'Reviews: the same session cannot review the product twice');
assert_true(Security::rateLimitAllows($aunGuestB[0][0], 1, Reviews::DUPLICATE_WINDOW, false) && Security::rateLimitAllows($aunGuestB[1][0], $aunGuestB[1][1], Reviews::DUPLICATE_WINDOW, true),
    'Reviews: another shopper behind the same IP/CDN edge can still review');
$aunApiSource = (string)file_get_contents($aunApp . '/src/Http/ApiHandlers.php');
$aunRateProp->setValue(null, $aunPreviousRateDb);
assert_true(!str_contains($aunApiSource, 'Security::rateLimit($reviewer') && str_contains($aunApiSource, 'Security::rateLimitRecord($bucket, $bindIp)'), 'Reviews: the handler records only after an accepted review');

// ── 4. Cart: a negative unit price is never sold for free ────────────────────────────────────────
$pdo->exec("DELETE FROM variants WHERE id = 987601");
$pdo->exec("DELETE FROM products WHERE id IN (98761, 98762)");
$aunCat = (int)$pdo->query('SELECT MIN(id) FROM categories')->fetchColumn();
if ($aunCat <= 0) {
    $pdo->exec("INSERT INTO categories (slug, name) VALUES ('aun-cat', 'AUN category')");
    $aunCat = (int)$pdo->lastInsertId();
}
$pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (98761, 'default', {$aunCat}, 'AUN parent', 'aun-parent', 'AUN-PARENT', 'simple', 500, 'eur', 'active', 10, 'deny'),
           (98762, 'default', {$aunCat}, 'AUN fine', 'aun-fine', 'AUN-FINE', 'simple', 300, 'eur', 'active', 10, 'deny')");
$pdo->exec("INSERT INTO variants (id, product_id, sku, name, stock, price_delta, availability, inventory_policy)
    VALUES (987601, 98761, 'AUN-NEG', 'Outgrown delta', 5, -800, 'active', 'deny')");
$aunIncidents = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM operational_incidents WHERE code = 'catalog.negative_price'")->fetchColumn();
$aunIncidentsBefore = $aunIncidents();
Cart::clear();
Cart::add(98761, 987601, 2);
Cart::add(98762, null, 1);
$aunLines = [];
$aunCart = Cart::enriched();
foreach ($aunCart['items'] as $aunLine) $aunLines[$aunLine['key']] = $aunLine;
$aunNeg = $aunLines['v_987601'] ?? [];
assert_true(($aunNeg['available'] ?? true) === false && ($aunNeg['unavailable_reason'] ?? null) === Cart::UNAVAILABLE_PRICE_INVALID, 'Cart: a variant whose delta outgrew the base price is unavailable with a reason');
assert_true((int)($aunNeg['price'] ?? 0) === -300 && (int)($aunNeg['total'] ?? -1) === 0, 'Cart: the line keeps its real (negative) price, so Orders::create refuses it too, and adds nothing');
assert_equals(300, (int)$aunCart['subtotal'], 'Cart: only the purchasable line is in the subtotal');
assert_true(($aunLines['p_98762']['available'] ?? false) === true && array_key_exists('unavailable_reason', $aunLines['p_98762']) && $aunLines['p_98762']['unavailable_reason'] === null, 'Cart: a normal line stays purchasable');
assert_true($aunIncidents() > $aunIncidentsBefore, 'Cart: the negative price raises a catalog.negative_price incident');
$aunCount = $aunIncidents();
Cart::enriched();
assert_equals($aunCount, $aunIncidents(), 'Cart: one incident per SKU and request, not per enrichment');
$aunCheckout = array_values(array_filter($aunCart['items'], static fn(array $item): bool => $item['available'] ?? true));
assert_equals(['AUN-FINE'], array_column($aunCheckout, 'sku'), 'Cart: checkout (which takes available lines only) never orders the negative line');
assert_true(!str_contains((string)file_get_contents($aunApp . '/src/Cart.php'), '$price = max(0,'), 'Cart: no unit price is clamped to zero any more');
Cart::clear();
$pdo->exec("DELETE FROM variants WHERE id = 987601");
$pdo->exec("DELETE FROM products WHERE id IN (98761, 98762)");

// ── 5. E-mail normalisation is UTF-8 aware ───────────────────────────────────────────────────────
assert_equals('éva@példa.hu', Str::normalizeEmail(' Éva@PÉLDA.hu '), 'E-mail: non-ASCII letters are lowercased too');
assert_equals(Str::normalizeEmail('ÉVA@x.hu'), Str::normalizeEmail('éva@x.hu'), 'E-mail: Éva and éva share one per-customer coupon limit');
$aunCoupons = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$aunCoupons->exec('CREATE TABLE coupon_uses (id INTEGER PRIMARY KEY AUTOINCREMENT, coupon_code TEXT, customer_email TEXT)');
$aunCoupons->exec("INSERT INTO coupon_uses (coupon_code, customer_email) VALUES ('X', 'ÉVA@x.hu '), ('X', 'éva@x.hu')");
(require $aunApp . '/migrations/20261016150000_normalize_coupon_use_emails.php')->up($aunCoupons, true);
assert_equals(['éva@x.hu', 'éva@x.hu'], $aunCoupons->query('SELECT customer_email FROM coupon_uses ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'E-mail: the coupon_uses migration normalises like Str::normalizeEmail');

// ── 6. Public feed cache: category checksum, lock, no double writer ──────────────────────────────
$aunCacheDir = new ReflectionProperty(Cache::class, 'cacheDir');
$aunCacheDir->setAccessible(true);
$aunPreviousCacheDir = $aunCacheDir->getValue();
Cache::setDir($aunRoot . '/cache');
$aunCalls = 0;
$aunWriter = static function ($out) use (&$aunCalls): void { $aunCalls++; fwrite($out, "<urlset>{$aunCalls}</urlset>"); };
$aunSend = static function () use ($aunWriter): string { ob_start(); PublicFeedCache::send('aun-sitemap.xml', 'https://shop.example', $aunWriter); return (string)ob_get_clean(); };
$aunFirst = $aunSend();
$aunSecond = $aunSend();
assert_true($aunFirst === '<urlset>1</urlset>' && $aunSecond === $aunFirst && $aunCalls === 1, 'Feed cache: a fresh entry is served without running the writer');
assert_true(is_file(Cache::path('public/aun-sitemap.xml.lock')), 'Feed cache: regeneration is serialised on a per-entry lock file');
$aunCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$aunName = (string)$pdo->query('SELECT name FROM categories WHERE id = ' . $aunCategory)->fetchColumn();
$aunPrint = PublicFeedCache::catalogFingerprint();
$pdo->prepare('UPDATE categories SET name = :n WHERE id = :id')->execute([':n' => $aunName . ' (renamed)', ':id' => $aunCategory]);
assert_true(PublicFeedCache::catalogFingerprint() !== $aunPrint, 'Feed cache: renaming a category changes the fingerprint (COUNT/MAX(id) did not)');
assert_equals('<urlset>2</urlset>', $aunSend(), 'Feed cache: a category edit rebuilds the cached feed');
$pdo->prepare('UPDATE categories SET name = :n WHERE id = :id')->execute([':n' => $aunName, ':id' => $aunCategory]);
Cache::setRenameHookForTesting(static fn(string $from, string $to): bool => false);
$aunResult = Cache::writeStream('public/aun-direct.xml', static function ($out): void { fwrite($out, 'direct'); });
assert_true($aunResult['status'] === Cache::STREAM_NOT_CACHED && is_string($aunResult['file']) && (string)file_get_contents($aunResult['file']) === 'direct',
    'Feed cache: a failed rename reports "written but not cached" with the written copy');
@unlink((string)$aunResult['file']);
$aunCalls = 0;
Cache::flush();
$aunUncached = $aunSend();
assert_true($aunUncached === '<urlset>1</urlset>' && $aunCalls === 1, 'Feed cache: when the entry cannot be renamed into place the writer still runs only once');
assert_equals([], glob(dirname(Cache::path('public/aun-sitemap.xml')) . '/aun-sitemap.xml.tmp.*') ?: [], 'Feed cache: the temporary copy is removed after serving it');
Cache::setRenameHookForTesting(null);
$aunCacheDir->setValue(null, $aunPreviousCacheDir);

// ── 7. Outbox: an event that was only skipped keeps its attempts ─────────────────────────────────
$pdo->exec("UPDATE event_outbox SET status = 'dead' WHERE status IN ('pending', 'retry')");
$aunEvents = [];
$pdo->beginTransaction();
foreach ([1, 2, 3] as $aunN) $aunEvents[] = Outbox::record('order.created', 'order', 971000 + $aunN, ['n' => $aunN]);
$pdo->commit();
$aunIds = implode(',', array_map([$pdo, 'quote'], $aunEvents));
$pdo->exec('UPDATE event_outbox SET attempts = ' . (Outbox::MAX_ATTEMPTS - 1) . " WHERE id IN ({$aunIds})");
$aunTimeouts = 0;
$aunRun = OutboxDispatcher::run(['outbound_webhooks' => ['slow' => ['url' => 'https://slow.example/hook', 'secret' => $aunSecret, 'events' => ['order.*']]]], false, 20,
    static function () use (&$aunTimeouts): array { $aunTimeouts++; return ['response_code' => null, 'error' => 'Operation timed out', 'timed_out' => true]; });
$aunStates = $pdo->query("SELECT status, attempts, last_error FROM event_outbox WHERE id IN ({$aunIds})")->fetchAll(PDO::FETCH_ASSOC);
$aunRetry = array_values(array_filter($aunStates, static fn(array $row): bool => $row['status'] === 'retry'));
assert_equals(2, $aunTimeouts, 'Outbox: the slow endpoint is called twice, then skipped');
assert_equals(['slow'], $aunRun['skipped_endpoints'], 'Outbox: the sweep reports the skipped endpoint');
assert_equals(2, count(array_filter($aunStates, static fn(array $row): bool => $row['status'] === 'dead')), 'Outbox: the two events that really timed out on their last attempt die');
assert_true(count($aunRetry) === 1 && (int)$aunRetry[0]['attempts'] === Outbox::MAX_ATTEMPTS - 1 && str_contains((string)$aunRetry[0]['last_error'], OutboxDispatcher::SKIPPED),
    'Outbox: the skipped event is released without consuming its last attempt (it does not die unattempted)');
$pdo->exec("UPDATE event_outbox SET status = 'dead' WHERE id IN ({$aunIds})");

// ── 8. CSV exports: a failure mid-stream is logged, raised and thrown ────────────────────────────
final class AunFailingStatement extends PDOStatement
{
    public int $served = 0;
    protected function __construct() {}
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (++$this->served > 2) throw new PDOException('SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query');
        return parent::fetch($mode, $cursorOrientation, $cursorOffset);
    }
}
$aunExportDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$aunExportDb->exec('CREATE TABLE t (a TEXT)');
$aunExportDb->exec("INSERT INTO t VALUES ('r1'), ('r2'), ('r3'), ('r4')");
$aunExportDb->setAttribute(PDO::ATTR_STATEMENT_CLASS, [AunFailingStatement::class, []]);
$aunStmt = $aunExportDb->prepare('SELECT a FROM t ORDER BY a');
$aunStmt->execute();
$aunOut = fopen('php://memory', 'w+');
$aunExportIncidents = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM operational_incidents WHERE code = 'export.truncated'")->fetchColumn();
$aunBefore = $aunExportIncidents();
$aunTruncated = null;
try {
    CsvExport::stream($aunOut, $aunStmt, ['a'], static fn(array $row): array => [$row['a']], 'aun');
} catch (CsvExportTruncated $e) {
    $aunTruncated = $e;
}
rewind($aunOut);
assert_true($aunTruncated !== null && $aunTruncated->rowsWritten === 2 && $aunTruncated->getPrevious() instanceof PDOException, 'Export: a PDO error mid-stream throws CsvExportTruncated with the rows written');
assert_equals("a\nr1\nr2\n", (string)stream_get_contents($aunOut), 'Export: no marker row is appended (CSV parsers would read it as data)');
assert_true($aunExportIncidents() > $aunBefore, 'Export: the truncation raises an export.truncated incident');
foreach (['src/OrderExport.php' => 'CsvExport::stream(', 'src/Entrypoints/Admin/Catalog.php' => 'CsvExport::stream(', 'src/Entrypoints/Admin/OrderOperations.php' => 'CsvExport::abortResponse()'] as $aunFile => $aunNeedle) {
    assert_true(str_contains((string)file_get_contents($aunApp . '/' . $aunFile), $aunNeedle), "Export: {$aunFile} uses {$aunNeedle}");
}
assert_true(str_contains((string)file_get_contents($aunApp . '/src/Entrypoints/Admin/Catalog.php'), 'catch (CsvExportTruncated)'), 'Export: the product export handler answers a truncated export');
} catch (Throwable $aunError) {
    assert_true(false, 'Upgrade notes: unexpected ' . get_class($aunError) . ': ' . $aunError->getMessage() . ' at ' . basename($aunError->getFile()) . ':' . $aunError->getLine());
}
