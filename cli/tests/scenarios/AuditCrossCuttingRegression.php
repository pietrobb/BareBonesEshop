<?php

// Cross-cutting audit follow-up: one symlink-safe tree removal, one cached schema probe, CSP alarm
// fallback rules and retention, migration-window fallbacks, config schema parity, one e-mail
// normalisation and the dead code the PHPStan baseline hid.
echo "\n--- Cross-cutting audit: shared helpers, CSP fallback, schema parity ---\n";
foreach ($GLOBALS as $xcValue) if ($xcValue instanceof PDOStatement) $xcValue->closeCursor();
$xcApp = dirname(TEST_CLI_DIR);
$xcRoot = test_fixture_path('cross-cutting');
$xcDb = Db::get();
$xcDriver = Db::driver();

try {
    // 1. One recursive delete that never follows links, behind all four former copies.
    foreach ([[BackupService::class, true], [ThemeUpgradeGate::class, true], [UpdatePackageBuilder::class, true], [Updater::class, false]] as [$xcClass, $xcStatic]) {
        $xcTree = $xcRoot . '/tree-' . $xcClass;
        $xcOutside = $xcRoot . '/outside-' . $xcClass;
        @mkdir($xcTree . '/a/b', 0700, true);
        @mkdir($xcOutside, 0700, true);
        file_put_contents($xcTree . '/a/b/file.txt', 'x');
        file_put_contents($xcOutside . '/keep.txt', 'keep');
        $xcLinks = @symlink($xcOutside, $xcTree . '/a/dir-link') && @symlink($xcOutside . '/keep.txt', $xcTree . '/file-link');
        $xcMethod = new ReflectionMethod($xcClass, 'removeTree');
        $xcMethod->invoke($xcStatic ? null : (new ReflectionClass($xcClass))->newInstanceWithoutConstructor(), $xcTree);
        assert_true(!file_exists($xcTree) && !is_link($xcTree), "Cross-cutting removeTree ({$xcClass}): the whole tree is removed");
        if ($xcLinks) {
            assert_true(is_file($xcOutside . '/keep.txt'), "Cross-cutting removeTree ({$xcClass}): files behind a directory or file symlink survive");
        }
        assert_true(str_contains((string)file_get_contents($xcApp . '/src/' . $xcClass . '.php'), 'FileSystem::removeTree($path);'), "Cross-cutting removeTree ({$xcClass}): delegates to the shared FileSystem helper");
    }
    FileSystem::removeTree($xcRoot . '/does-not-exist');
    assert_true(!file_exists($xcRoot . '/does-not-exist'), 'Cross-cutting removeTree: a missing path is a no-op');

    // 2. Table/column probes live in Db; a miss is probed again, so a migration run in between is seen.
    $xcMem = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    assert_true(!Db::tableExists('xc_probe', $xcMem) && Db::tableColumns('xc_probe', $xcMem) === [], 'Cross-cutting probe: a missing table reads as absent');
    $xcMem->exec('CREATE TABLE xc_probe (id INTEGER PRIMARY KEY, a TEXT)');
    assert_true(Db::tableExists('xc_probe', $xcMem) && Db::hasColumn('xc_probe', 'a', $xcMem) && !Db::hasColumn('xc_probe', 'b', $xcMem), 'Cross-cutting probe: a table created after a miss is found, columns are exact');
    $xcMem->exec('ALTER TABLE xc_probe ADD COLUMN b TEXT');
    assert_true(Db::hasColumn('xc_probe', 'b', $xcMem), 'Cross-cutting probe: a column added later is found (misses are not cached)');
    $xcThrown = false;
    try { Db::tableColumns('x; DROP TABLE y', $xcMem); } catch (InvalidArgumentException $e) { $xcThrown = true; }
    assert_true($xcThrown, 'Cross-cutting probe: table names are validated before reaching SQL');
    $xcDbSource = (string)file_get_contents($xcApp . '/src/Db.php');
    assert_true(str_contains($xcDbSource, 'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'), 'Cross-cutting probe: MySQL probes are confined to the current schema');
    $xcOrdersSource = str_replace("\r\n", "\n", (string)file_get_contents($xcApp . '/src/Orders.php'));
    $xcHasColumn = preg_match('/function hasColumn\(.*?\n    }\n/s', $xcOrdersSource, $xcMatch) === 1 ? $xcMatch[0] : '';
    assert_true($xcHasColumn !== '' && !str_contains($xcHasColumn, 'PRAGMA') && str_contains($xcHasColumn, 'Db::hasColumn($table, $column)'), 'Cross-cutting probe: Orders::hasColumn no longer runs PRAGMA on MySQL');
    foreach (['GdprService', 'Idempotency', 'OperationalDashboard', 'AdminIdentity', 'ShippingService'] as $xcClass) {
        $xcSource = (string)file_get_contents($xcApp . '/src/' . $xcClass . '.php');
        assert_true(!str_contains($xcSource, 'information_schema') && !str_contains($xcSource, 'PRAGMA table_info') && !str_contains($xcSource, 'sqlite_master'), "Cross-cutting probe: {$xcClass} uses the shared Db probe");
    }

    // 3. Migrations not yet run: CSP reports and the ERP status pull keep working.
    $xcOld = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $xcOld->exec("CREATE TABLE csp_violations (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id TEXT NOT NULL DEFAULT 'default', directive TEXT NOT NULL,
        blocked TEXT NOT NULL DEFAULT '', document_path TEXT NOT NULL DEFAULT '', disposition TEXT NOT NULL DEFAULT 'enforce', created_at TEXT NOT NULL)");
    $xcReport = (string)json_encode(['csp-report' => ['document-uri' => 'https://shop.example/pokladna', 'effective-directive' => 'form-action', 'blocked-uri' => 'https://pay.example/x']]);
    $xcNow = time();
    $xcStored = CspReports::ingest($xcReport, $xcOld, $xcNow, '198.51.100.1') + CspReports::ingest($xcReport, $xcOld, $xcNow - 1, '198.51.100.2');
    $xcCounts = CspReports::counts($xcOld, 'default', $xcNow - 3600);
    assert_true($xcStored === 2 && $xcCounts['form_action_reports'] === 2 && $xcCounts['form_action'] === 1 && $xcCounts['form_action_requests'] === 2,
        'Cross-cutting migration window: CSP ingest/counts work before reporter_hash/request_id exist');
    (require $xcApp . '/migrations/20261016120000_csp_reporter_hash.php')->up($xcOld, true);
    (require $xcApp . '/migrations/20261016140000_csp_request_id.php')->up($xcOld, true);
    (require $xcApp . '/migrations/20261016140000_csp_request_id.php')->up($xcOld, true);
    assert_true(Db::hasColumn('csp_violations', 'request_id', $xcOld) && Db::hasColumn('csp_violations', 'reporter_hash', $xcOld), 'Cross-cutting migration: request_id is added idempotently');
    $xcMissing = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    (require $xcApp . '/migrations/20261016140000_csp_request_id.php')->up($xcMissing, true);
    (require $xcApp . '/migrations/20261015120000_processed_events_claim_state.php')->up($xcMissing, true);
    assert_true(!Db::tableExists('processed_events', $xcMissing), 'Cross-cutting migration: processed_events_claim_state skips a missing table like its siblings');
    $xcMigrator = new ReflectionMethod(Migrator::class, 'checksumMatches');
    $xcClaimFile = $xcApp . '/migrations/20261015120000_processed_events_claim_state.php';
    assert_true($xcMigrator->invoke((new ReflectionClass(Migrator::class))->newInstanceWithoutConstructor(), '20261015120000_processed_events_claim_state',
        '773fdbfd1b627d980bb6487a56a707e94b001a529d9b867e972b36a5563d083b', hash_file('sha256', $xcClaimFile)),
        'Cross-cutting migration: installs that applied the released processed_events_claim_state keep migrating');

    $xcErp = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $xcErp->exec("CREATE TABLE orders (id INTEGER PRIMARY KEY, store_id TEXT NOT NULL, erp_id TEXT, status TEXT NOT NULL, updated_at TEXT)");
    $xcErp->exec("INSERT INTO orders (id, store_id, erp_id, status) VALUES (2, 'default', 'E2', 'paid'), (1, 'default', 'E1', 'processing'), (3, 'default', NULL, 'paid')");
    Db::init($xcErp, 'sqlite');
    try {
        $xcPending = Orders::pendingStatusSync(10);
        Orders::markErpStatusChecked(1);
    } finally {
        Db::init($xcDb, $xcDriver);
    }
    assert_equals([1, 2], array_map('intval', array_column($xcPending, 'id')), 'Cross-cutting migration window: the ERP status pull works before erp_status_checked_at exists');

    // 4. CSP alarm: the raw-count fallback needs >= 2 requests and an empty trusted_proxies.
    (require $xcApp . '/migrations/20261016140000_csp_request_id.php')->up($xcDb, $xcDriver === 'sqlite');
    Db::forgetSchema($xcDb);
    $xcDb->exec('DELETE FROM csp_violations');
    $xcConfig = require TEST_CLI_DIR . '/../config.php';
    $xcConfig['jobs_dir'] = sys_get_temp_dir();
    $xcConfig['private_cache_dir'] = sys_get_temp_dir();
    $xcConfig['trusted_proxies'] = [];
    $xcConfig['monitoring'] = [
        'backup_rpo_seconds' => 86400, 'queue_pending_warning' => 999999, 'failed_jobs_warning' => 999999,
        'failed_mail_warning' => 999999, 'payment_incidents_critical' => 999999, 'webhook_incidents_warning' => 999999, 'csp_violations_warning' => 999999,
    ];
    $xcSnapshot = static fn(array $config): array => OperationalMetrics::snapshot($config, $xcApp, $xcDb, 'default', [['meta' => ['timestamp' => gmdate('c', $xcNow - 60)]]], $xcNow);
    $xcAlarm = static fn(array $s): bool => in_array('csp_form_action_violation', array_column($s['alerts'], 'code'), true);
    $xcBatch = [];
    for ($xcI = 0; $xcI < 8; $xcI++) {
        $xcBatch[] = ['type' => 'csp-violation', 'url' => 'https://shop.example/pokladna', 'body' => ['effectiveDirective' => 'form-action', 'blockedURL' => 'https://evil' . $xcI . '.example/' /* distinct: R10 M9 stores a repeated violation once */, 'documentURL' => 'https://shop.example/pokladna', 'disposition' => 'enforce']];
    }
    assert_equals(8, CspReports::ingest((string)json_encode($xcBatch), $xcDb, $xcNow, '192.0.2.10'), 'Cross-cutting CSP: one Reporting API request stores its batch');
    $xcOne = $xcSnapshot($xcConfig);
    assert_true($xcOne['metrics']['csp_form_action_reports'] === 8 && $xcOne['metrics']['csp_form_action_report_requests'] === 1 && !$xcAlarm($xcOne),
        'Cross-cutting CSP: reports from a single request never raise the raw-count alarm');
    CspReports::ingest($xcReport, $xcDb, $xcNow, '192.0.2.10');
    $xcTwo = $xcSnapshot($xcConfig);
    assert_true($xcTwo['metrics']['csp_form_action_report_requests'] === 2 && $xcAlarm($xcTwo), 'Cross-cutting CSP: without trusted_proxies, reports from two requests reach the raw-count alarm');
    $xcProxied = $xcConfig;
    $xcProxied['trusted_proxies'] = ['10.0.0.1'];
    assert_true(!$xcAlarm($xcSnapshot($xcProxied)), 'Cross-cutting CSP: with trusted_proxies set, only distinct reporters alarm (raw-count fallback off)');
    $xcRunbook = (string)file_get_contents($xcApp . '/docs/OPERATIONS_RUNBOOK.md');
    assert_true(str_contains($xcRunbook, "'csp_form_action_reports_critical' => 5") && str_contains($xcRunbook, 'One client can raise that fallback') && !str_contains($xcRunbook, 'so the critical alert (default 2) cannot be raised by one client'),
        'Cross-cutting CSP: the runbook documents the raw-count fallback honestly');

    // 5. CSP retention runs daily, not only when new reports arrive.
    $xcDb->exec('DELETE FROM csp_violations');
    $xcInsert = $xcDb->prepare("INSERT INTO csp_violations (store_id, directive, blocked, document_path, disposition, created_at) VALUES ('default', 'img-src', '', '/', 'enforce', :at)");
    $xcInsert->execute([':at' => gmdate('Y-m-d H:i:s', $xcNow - 20 * 86400)]);
    $xcInsert->execute([':at' => gmdate('Y-m-d H:i:s', $xcNow - 86400)]);
    @mkdir($xcRoot . '/jobs/completed', 0700, true);
    $xcDeleted = RetentionCleanup::run(['jobs_dir' => $xcRoot . '/jobs'], $xcNow);
    assert_true(($xcDeleted['csp_violations'] ?? null) === 1 && (int)$xcDb->query('SELECT COUNT(*) FROM csp_violations')->fetchColumn() === 1,
        'Cross-cutting CSP: the daily cleanup purges reports older than the retention (7 days) and keeps newer ones');
    $xcDb->exec('DELETE FROM csp_violations');

    // 6. Config schema lists every key config.example.php ships (and nothing the example lacks,
    // except internal test/benchmark switches), plus every root key the bundled adapters read.
    $xcSchema = json_decode((string)file_get_contents($xcApp . '/config/config.schema.json'), true);
    $xcExample = require $xcApp . '/config.example.php';
    $xcSchemaKeys = array_keys((array)$xcSchema['properties']);
    $xcPublicKeys = array_keys(array_filter((array)$xcSchema['properties'], static fn(array $p): bool => empty($p['x-internal'])));
    $xcMissingInSchema = array_values(array_diff(array_keys($xcExample), $xcSchemaKeys));
    $xcMissingInExample = array_values(array_diff($xcPublicKeys, array_keys($xcExample)));
    assert_true($xcMissingInSchema === [], 'Cross-cutting config: every config.example.php key is in config.schema.json' . ($xcMissingInSchema ? ': ' . implode(', ', $xcMissingInSchema) : ''));
    assert_true($xcMissingInExample === [], 'Cross-cutting config: every non-internal schema key is in config.example.php' . ($xcMissingInExample ? ': ' . implode(', ', $xcMissingInExample) : ''));
    $xcMonitoring = array_keys((array)$xcSchema['properties']['monitoring']['properties']);
    sort($xcMonitoring);
    $xcExampleMonitoring = array_keys((array)$xcExample['monitoring']);
    sort($xcExampleMonitoring);
    assert_equals($xcExampleMonitoring, $xcMonitoring, 'Cross-cutting config: monitoring thresholds in the schema match config.example.php');
    $xcAdapterKeys = [];
    foreach (glob($xcApp . '/adapters/contrib/*.php') ?: [] as $xcAdapter) {
        preg_match_all("/\\\$cfg\\['([a-z][a-z0-9_]*)'\\]/", (string)file_get_contents($xcAdapter), $xcMatches);
        $xcAdapterKeys = array_merge($xcAdapterKeys, $xcMatches[1]);
    }
    $xcAdapterMissing = array_values(array_diff(array_unique($xcAdapterKeys), $xcSchemaKeys));
    assert_true($xcAdapterMissing === [], 'Cross-cutting config: root config keys read by bundled adapters are in the schema' . ($xcAdapterMissing ? ': ' . implode(', ', $xcAdapterMissing) : ''));
    assert_true(str_contains((string)file_get_contents($xcApp . '/config/backup.json'), '"include_files"'), 'Cross-cutting config: config/backup.json documents include_files');
    $xcPreflight = (string)file_get_contents($xcApp . '/cli/staging-preflight.php');
    assert_true(str_contains($xcPreflight, "'backup_recovery_dir' =>") && str_contains($xcPreflight, "'update_storage_dir' =>"), 'Cross-cutting config: staging preflight requires private backup_recovery_dir and update_storage_dir');

    // 7. One e-mail normalisation, stored and compared as-is (no SQL LOWER()).
    assert_equals('ann.k@example.sk', Str::normalizeEmail("  Ann.K@Example.SK \n"), 'Cross-cutting e-mail: trimmed and lowercased');
    assert_equals(Str::normalizeEmail('ŽOFIA@EXAMPLE.SK'), Pricing::normalizeEmail('ŽOFIA@EXAMPLE.SK'), 'Cross-cutting e-mail: Pricing uses the shared normalisation');
    assert_true(!str_contains((string)file_get_contents($xcApp . '/src/Pricing.php'), 'LOWER(TRIM(customer_email))'), 'Cross-cutting e-mail: coupon limits compare stored normalised e-mails without LOWER()');
    foreach (['src/Customers.php', 'src/Entrypoints/Storefront/Account.php'] as $xcFile) {
        $xcSource = (string)file_get_contents($xcApp . '/' . $xcFile);
        assert_true(str_contains($xcSource, 'Str::normalizeEmail(') && !str_contains($xcSource, 'strtolower(trim($email))'), "Cross-cutting e-mail: {$xcFile} uses Str::normalizeEmail");
    }
    $xcCoupons = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $xcCoupons->exec('CREATE TABLE coupon_uses (id INTEGER PRIMARY KEY AUTOINCREMENT, coupon_code TEXT, customer_email TEXT)');
    $xcCoupons->exec("INSERT INTO coupon_uses (coupon_code, customer_email) VALUES ('X', ' Ann@Example.SK'), ('X', 'ann@example.sk'), ('X', 'Ž@x.sk')");
    (require $xcApp . '/migrations/20261016150000_normalize_coupon_use_emails.php')->up($xcCoupons, true);
    assert_equals(['ann@example.sk', 'ann@example.sk', Str::normalizeEmail('Ž@x.sk')], $xcCoupons->query('SELECT customer_email FROM coupon_uses ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
        'Cross-cutting e-mail: existing coupon uses are normalised exactly like Str::normalizeEmail');
    $xcNoCoupons = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    (require $xcApp . '/migrations/20261016150000_normalize_coupon_use_emails.php')->up($xcNoCoupons, true);
    assert_true(!Db::tableExists('coupon_uses', $xcNoCoupons), 'Cross-cutting e-mail: the coupon e-mail migration skips a schema without coupon_uses');

    // 8. Dead code the baseline hid is gone, and the baseline no longer carries stale entries.
    assert_true(!method_exists(Cache::class, 'deleteDir') && !method_exists(I18n::class, 'cfg') && !method_exists(StorefrontViewModel::class, 'sectionData'),
        'Cross-cutting dead code: Cache::deleteDir, I18n::cfg and StorefrontViewModel::sectionData are removed');
    $xcBaseline = (string)file_get_contents($xcApp . '/phpstan-baseline.neon');
    assert_true(!str_contains($xcBaseline, 'Variable \\$argv might not be defined') && !str_contains($xcBaseline, 'deleteDir') && !str_contains($xcBaseline, 'sectionData'),
        'Cross-cutting PHPStan: the baseline has no entries that no longer match');
    assert_true(is_file($xcApp . '/cli/phpstan.sh') && str_contains((string)file_get_contents($xcApp . '/cli/phpstan.sh'), 'git -C "$root" archive HEAD'),
        'Cross-cutting PHPStan: cli/phpstan.sh runs the CI analysis on a clean copy');
} finally {
    Db::init($xcDb, $xcDriver);
    FileSystem::removeTree($xcRoot);
}

// LocalStorage without uploads_dir must default to the app root's uploads/ (served as /uploads),
// not adapters/uploads next to the adapter file, which the web server never serves.
$xcUploadsDir = new ReflectionProperty(LocalStorage::class, 'uploadsDir');
$xcStorage = new LocalStorage([]);
assert_equals(realpath(dirname(TEST_CLI_DIR) . '/uploads'), realpath((string)$xcUploadsDir->getValue($xcStorage)),
    'Cross-cutting LocalStorage: the default uploads dir is the app root uploads/');
assert_true(!is_dir(dirname(TEST_CLI_DIR) . '/adapters/uploads'), 'Cross-cutting LocalStorage: no stray adapters/uploads directory is created');
