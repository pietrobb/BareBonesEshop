<?php

// Round 15 review (2026-11-04) "release process and upgrade". High 7: a v1.8.0 shop adopted exactly per the runbook
// answered 503 on /readyz and /admin.php: its stores.json (no default_store, the main store keyed "sk", no channels)
// stayed as it was, while every migrated row belongs to the store 'default' and the workers, cron and admin open it.
echo "\n--- Audit round 15 (ops): v1.x stores.json upgraded on adoption, doctors report it ---\n";
$r15oFail = static fn(string $what, Throwable $e) => assert_true(false, "R15 ops {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
$r15oBase = test_fixture_path('r15-ops');
$r15oRemove = static function (string $path) use (&$r15oRemove): void {
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
};

try {
    $r15oApp = $r15oBase . '/app';
    $r15oData = $r15oBase . '/private';
    mkdir($r15oApp, 0777, true);
    $r15oLegal = static fn(string $name, string $country): array => ['name' => $name, 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '811 01',
        'country' => $country, 'ico' => '12345678', 'dic' => '1020304050', 'ic_dph' => '', 'iban' => '', 'bank' => '', 'email' => 'info@example.sk',
        'phone' => '', 'invoice_prefix' => '', 'payment_terms_days' => 14, 'register_note' => ''];
    // The shape of v1.8.0's stores.json (git show v1.8.0:stores.json), with a merchant's own seller.
    $r15oV18 = ['default_locale' => 'en', 'default_currency' => 'eur', 'stores' => [
        'sk' => ['name' => 'R15 Obchod', 'default_locale' => 'sk', 'currency' => 'eur', 'url_prefix' => '', 'languages' => ['sk', 'cs', 'en'],
            'oss_enabled' => false, 'legal' => $r15oLegal('R15 Legacy s.r.o.', 'SK')],
        'cz' => ['name' => 'R15 Obchod CZ', 'default_locale' => 'cs', 'currency' => 'czk', 'url_prefix' => '/cs', 'languages' => ['cs', 'en'],
            'oss_enabled' => false, 'legal' => $r15oLegal('R15 Legacy CZ s.r.o.', 'CZ')],
        'de' => ['name' => 'R15 Shop', 'default_locale' => 'de', 'currency' => 'eur', 'url_prefix' => '/de', 'languages' => ['de', 'en'],
            'oss_enabled' => false, 'legal' => $r15oLegal('R15 Legacy GmbH', 'DE')],
    ], 'currencies' => ['eur' => ['symbol' => '€', 'decimals' => 2, 'position' => 'after'], 'czk' => ['symbol' => 'Kč', 'decimals' => 0, 'position' => 'after']]];
    $r15oV18Json = json_encode($r15oV18, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($r15oApp . '/stores.json', $r15oV18Json);
    $r15oConfig = ['private_runtime_dir' => $r15oData];
    $r15oDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r15oReady = static fn(): string => (string)(Health::readiness($r15oConfig, $r15oApp, $r15oDb)['checks']['stores'] ?? '');
    $r15oUpdaterCheck = static function (array $config) use ($r15oApp, $r15oBase): ?array {
        $doctor = (new Updater($r15oApp, $r15oBase . '/updates'))->doctor(null, null, false, $config);
        foreach ($doctor['checks'] as $check) if ($check['name'] === 'stores') return [$check['level'], $check['message']];
        return null;
    };

    // Before: what the reviewer saw after the runbook (stores invalid = /readyz 503; no store 'default' for the admin).
    $r15oSetup = array_column(Setup::doctor($r15oConfig, $r15oApp)['checks'] ?? [], null, 'name');
    assert_equals([true, 'invalid', 'fail', true, true],
        [DataOverrides::storesNeedUpgrade($r15oApp, $r15oConfig), $r15oReady(), $r15oSetup['stores.default']['status'] ?? null,
            str_contains((string)($r15oSetup['stores.default']['message'] ?? ''), 'v1.x format'), str_contains((string)($r15oSetup['stores.default']['action'] ?? ''), 'setup.php apply')],
        'R15 high 7: a v1.8.0 stores.json is recognised; readiness fails and setup doctor says how to upgrade it');
    $r15oCheck = $r15oUpdaterCheck($r15oConfig);
    assert_true(($r15oCheck[0] ?? null) === 'warn' && str_contains((string)$r15oCheck[1], 'apply writes the upgraded copy'),
        'R15 high 7: update doctor reports the v1.x stores.json and that apply upgrades it');
    $r15oCheck = $r15oUpdaterCheck([]);
    assert_true(($r15oCheck[0] ?? null) === 'fail' && str_contains((string)$r15oCheck[1], 'private_runtime_dir'),
        'R15 high 7: without a private_runtime_dir update doctor is NOT_READY instead of leaving a shop that answers 503');

    // The upgrade (setup apply, or update apply of an adopted shop): a new private copy, the old file untouched.
    $r15oSeed = DataOverrides::seedStores($r15oApp, $r15oConfig);
    $r15oNew = json_decode((string)@file_get_contents($r15oData . '/stores.json'), true) ?: [];
    assert_true(str_contains((string)($r15oSeed['action'] ?? ''), 'upgraded from the v1.x format') && (string)file_get_contents($r15oApp . '/stores.json') === $r15oV18Json,
        'R15 high 7: the upgraded copy goes to private_runtime_dir, the v1.8.0 file stays unchanged for a rollback');
    assert_equals(['default', ['default', 'cz', 'de'], 'default', 'R15 Legacy s.r.o.', ['default', 'default', 'default'], ['default', 'cz', 'default'], ['storefront', 'api'], ['sk', 'cs', 'en'], 'czk'],
        [$r15oNew['default_store'] ?? null, array_keys($r15oNew['stores'] ?? []), $r15oNew['stores']['default']['id'] ?? null, $r15oNew['stores']['default']['legal']['name'] ?? null,
            array_column($r15oNew['stores'] ?? [], 'catalog_scope'), array_column($r15oNew['stores'] ?? [], 'provider_scope'), $r15oNew['stores']['cz']['channels'] ?? null,
            $r15oNew['stores']['default']['languages'] ?? null, $r15oNew['stores']['cz']['currency'] ?? null],
        'R15 high 7: the main store becomes "default" with its seller; one catalog; own provider scope only for another currency; the API channel; all fields kept');

    // After: the shop runs on it.
    $r15oContext = StoreContext::resolve($r15oNew, '/', '', 'default');
    $r15oCz = StoreContext::resolve($r15oNew, '/cs/', '');
    $r15oApi = StoreContext::resolve($r15oNew, '/', '', 'cz', 'api');
    assert_equals([false, 'ok', 'default', 'eur', 'cz', 'cz', null, 'unchanged'],
        [DataOverrides::storesNeedUpgrade($r15oApp, $r15oConfig), $r15oReady(), $r15oContext->id(), $r15oContext->currency(), $r15oCz->id(), $r15oApi->id(),
            $r15oUpdaterCheck($r15oConfig), DataOverrides::seedStores($r15oApp, $r15oConfig)['action'] ?? null],
        'R15 high 7: after the upgrade readiness passes, the default store, the /cs store and its API resolve, and nothing is upgraded twice');

    $r15oExample = json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/stores.example.json'), true);
    assert_equals(null, DataOverrides::upgradeLegacyStores($r15oExample), 'R15 high 7: a current stores.json is never rewritten');
    $r15oCli = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/cli/update.php');
    assert_true((bool)preg_match('/\$result = \$updater->apply\(\$package\);.*DataOverrides::storesNeedUpgrade\(\$root, \$config\).*DataOverrides::seedStores\(\$root, \$config\)/s', $r15oCli),
        'R15 high 7: update apply upgrades the v1.x stores.json of the adopted shop (--installed) right after the files');
} catch (Throwable $e) {
    $r15oFail('v1.x stores.json', $e);
} finally {
    $r15oRemove($r15oBase);
}

// Medium: the v2.1.0 notes published sha256sum of the key file, which no shop's `update.php fingerprint` shows; tags
// were not signed although the runbooks start from "the signed tag".
try {
    $r15oRoot = dirname(TEST_CLI_DIR);
    $r15oFingerprint = UpdateKeyRotation::fingerprint($r15oRoot . '/releases/update-public.key');
    $r15oNotes = (string)file_get_contents($r15oRoot . '/CHANGELOG.md');
    $r15oNotes210 = substr($r15oNotes, (int)strpos($r15oNotes, '## 2.1.0'));
    assert_true(preg_match('/^ {4}([0-9a-f]{64})\r?$/m', $r15oNotes210, $m) === 1 && $m[1] === $r15oFingerprint,
        'R15 medium: the fingerprint the 2.1.0 notes give is the one update.php fingerprint prints for the shipped key');
    $r15oBuild = (string)file_get_contents($r15oRoot . '/cli/build-update.php');
    assert_true(str_contains($r15oBuild, "'fingerprint'") && str_contains($r15oBuild, 'UpdateKeyRotation::fingerprint($public)'),
        'R15 medium: build-update.php fingerprint prints the value for the release notes with the same function as the shop');
    $r15oOps = (string)file_get_contents($r15oRoot . '/docs/OPERATIONS_RUNBOOK.md');
    $r15oAi = (string)file_get_contents($r15oRoot . '/docs/AI_UPDATE_RUNBOOK.md');
    assert_true(str_contains($r15oOps, 'git tag -s vX.Y.Z') && str_contains($r15oOps, 'git verify-tag vX.Y.Z') && str_contains($r15oAi, 'git verify-tag vX.Y.Z'),
        'R15 medium: the release checklist signs and verifies the tag, and the bootstrap runbook verifies it before exporting');
} catch (Throwable $e) {
    $r15oFail('fingerprint/tags', $e);
}

// Medium: an update rollback counted only orders and payments. Invoices, credit notes and refunds written during the
// update vanished without a word, and the restored counters issued the numbers of the sent documents a second time.
$r15uBase = test_fixture_path('r15-ops-rollback');
try {
    foreach (['base', 'target', 'installed'] as $tree) mkdir("{$r15uBase}/{$tree}/src", 0750, true);
    mkdir($r15uBase . '/target/migrations', 0750, true);
    file_put_contents($r15uBase . '/base/src/Core.php', "before\n");
    file_put_contents($r15uBase . '/target/src/Core.php', "after\n");
    file_put_contents($r15uBase . '/target/migrations/20990101000003_r15.php', "<?php return new class {};\n");
    file_put_contents($r15uBase . '/installed/src/Core.php', "before\n");
    UpdatePackageBuilder::build($r15uBase . '/base', $r15uBase . '/target', $r15uBase . '/package', '4.1.0', '4.0.0');
    $r15uPackage = new UpdatePackage($r15uBase . '/package', null, true);
    $r15uPdo = static fn(string $path): PDO => new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r15uSchema = static function (string $path, bool $perSeller) use ($r15uPdo): void {
        $db = $r15uPdo($path);
        $db->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, note TEXT)');
        $db->exec('CREATE TABLE payment_transactions (id INTEGER PRIMARY KEY, note TEXT)');
        $db->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, number TEXT, type TEXT)');
        $db->exec('CREATE TABLE order_refunds (id INTEGER PRIMARY KEY, amount INTEGER)');
        $db->exec("CREATE TABLE invoice_counters (year INTEGER NOT NULL, prefix TEXT NOT NULL DEFAULT '', last_number INTEGER NOT NULL DEFAULT 0, type TEXT NOT NULL DEFAULT 'invoice', PRIMARY KEY (year, prefix))");
        $db->exec("INSERT INTO invoice_counters (year, prefix, last_number) VALUES (2026, '', 7)");
        if ($perSeller) {
            $db->exec("CREATE TABLE invoice_number_counters (store_id TEXT NOT NULL, type TEXT NOT NULL, year INTEGER NOT NULL, prefix TEXT NOT NULL DEFAULT '', last_number INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (store_id, type, year, prefix))");
            $db->exec("INSERT INTO invoice_number_counters VALUES ('default', 'invoice', 2026, '', 7)");
        }
        $db->exec("INSERT INTO invoices (number, type) VALUES ('20260007', 'invoice')");
    };
    // While the migration ran, the shop issued invoice 8 and 9, credit note 1 and a refund; then the migration failed.
    $r15uUpdater = static function (string $path, string $state, bool $perSeller) use ($r15uBase, $r15uPdo): Updater {
        return new Updater($r15uBase . '/installed', $r15uBase . '/' . $state, $r15uPdo($path), static function () use ($path, $r15uPdo, $perSeller): void {
            $writer = $r15uPdo($path);
            $writer->exec("INSERT INTO invoices (number, type) VALUES ('20260008', 'invoice'), ('20260009', 'invoice'), ('D20260001', 'credit_note')");
            $writer->exec('INSERT INTO order_refunds (amount) VALUES (1299)');
            if ($perSeller) {
                $writer->exec("UPDATE invoice_number_counters SET last_number = 9 WHERE type = 'invoice'");
                $writer->exec("INSERT INTO invoice_number_counters VALUES ('default', 'credit_note', 2026, 'D', 1)");
            } else {
                // The failing update had already created the per-seller series and numbered there.
                $writer->exec("CREATE TABLE invoice_number_counters (store_id TEXT NOT NULL, type TEXT NOT NULL, year INTEGER NOT NULL, prefix TEXT NOT NULL DEFAULT '', last_number INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (store_id, type, year, prefix))");
                $writer->exec("INSERT INTO invoice_number_counters VALUES ('default', 'invoice', 2026, '', 9), ('default', 'credit_note', 2026, 'D', 1)");
            }
            $writer = null;
            throw new RuntimeException('simulated r15 migration failure');
        });
    };
    $r15uApply = static function (Updater $updater) use ($r15uPackage): string {
        try { $updater->apply($r15uPackage); return ''; } catch (Throwable $e) { return $e->getMessage(); }
    };

    $r15uPath = $r15uBase . '/shop.sqlite';
    $r15uSchema($r15uPath, true);
    $r15uOne = $r15uUpdater($r15uPath, 'state', true);
    $r15uOne->baseline('4.0.0');
    $r15uError = $r15uApply($r15uOne);
    $r15uOne = null;
    $r15uDb = $r15uPdo($r15uPath);
    $r15uCounters = $r15uDb->query("SELECT type || '/' || prefix || '=' || last_number FROM invoice_number_counters ORDER BY type")->fetchAll(PDO::FETCH_COLUMN);
    $r15uSnapshot = array_values(array_filter((new Updater($r15uBase . '/installed', $r15uBase . '/state'))->listSnapshots(), static fn(array $s): bool => ($s['status'] ?? '') === 'auto-rolled-back'))[0] ?? [];
    assert_true(str_contains($r15uError, 'simulated r15 migration failure') && str_contains($r15uError, '3 invoices row(s)') && str_contains($r15uError, '1 order_refunds row(s)')
        && str_contains($r15uError, 'their numbers are not issued again'),
        'R15 medium: the automatic rollback names the discarded invoices/credit notes and refunds');
    assert_equals([1, 1, 0], [(int)$r15uDb->query('SELECT COUNT(*) FROM invoices')->fetchColumn(), (int)($r15uSnapshot['discarded_writes']['order_refunds'] ?? -1),
        (int)$r15uDb->query('SELECT COUNT(*) FROM order_refunds')->fetchColumn()], 'R15 medium: the database was restored and the snapshot records the discarded refund');
    assert_equals(['credit_note/D=1', 'invoice/=9'], $r15uCounters,
        'R15 medium: after the rollback the next invoice is 10 and the next credit note 2: no number already sent is issued again');
    $r15uDb = null;

    // Rolled back to before the per-seller series existed: the legacy (year, prefix) series is raised instead.
    $r15uLegacy = $r15uBase . '/legacy.sqlite';
    $r15uSchema($r15uLegacy, false);
    $r15uTwo = $r15uUpdater($r15uLegacy, 'state-legacy', false);
    $r15uTwo->baseline('4.0.0');
    $r15uApply($r15uTwo);
    $r15uTwo = null;
    $r15uDb = $r15uPdo($r15uLegacy);
    assert_equals([9, 1], [(int)$r15uDb->query("SELECT MAX(last_number) FROM invoice_counters WHERE year = 2026 AND prefix = ''")->fetchColumn(),
        (int)$r15uDb->query("SELECT MAX(last_number) FROM invoice_counters WHERE year = 2026 AND prefix = 'D'")->fetchColumn()],
        'R15 medium: rolled back to before the per-seller series, the legacy series it numbers from again continues after the discarded numbers');
    $r15uDb = null;
} catch (Throwable $e) {
    $r15oFail('update rollback of documents', $e);
} finally {
    $r15oRemove($r15uBase);
}
// Medium: the order_item_digital backfill ran one UPDATE over the whole table (156 s at 4 million lines, deadlocks with
// checkout writes), and cli/migrate.php applied it against the live shop.
try {
    $r15mStatementRows = [];
    $r15mDb = new class('sqlite::memory:') extends PDO {
        public array $rows = [];
        public function exec(string $statement): int|false {
            $n = parent::exec($statement);
            if (stripos(ltrim($statement), 'UPDATE') === 0) $this->rows[] = (int)$n;
            return $n;
        }
    };
    $r15mDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $r15mDb->setAttribute(PDO::ATTR_STATEMENT_CLASS, [R15MigrationStatement::class, [$r15mDb]]);
    $r15mDb->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, product_type TEXT)');
    $r15mDb->exec('CREATE TABLE variants (id INTEGER PRIMARY KEY, digital INTEGER)');
    $r15mDb->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY, product_id INTEGER, variant_id INTEGER, attrs TEXT)');
    $r15mDb->exec("INSERT INTO products (id, product_type) VALUES (1, 'physical'), (2, 'digital')");
    $r15mDb->exec('INSERT INTO variants (id, digital) VALUES (10, 0), (11, 1)');
    $r15mInsert = $r15mDb->prepare('INSERT INTO order_items (id, product_id, variant_id, attrs) VALUES (?, ?, ?, ?)');
    $r15mDb->beginTransaction();
    for ($i = 1; $i <= 12000; $i++) $r15mInsert->execute([$i, 1, 10, '{}']);
    // One line of each kind in the last, partial range; ids with a gap before it.
    $r15mInsert->execute([17001, 2, null, '{}']);
    $r15mInsert->execute([17002, 1, 11, '{}']);
    $r15mInsert->execute([17003, 1, 11, json_encode(['_inventory_allocations' => [['warehouse_id' => 1, 'quantity' => 1]]])]);
    $r15mDb->commit();
    $r15mDb->rows = [];
    $r15mMigration = require dirname(__DIR__, 3) . '/migrations/20261103090000_order_item_digital.php';
    $r15mMigration->up($r15mDb, true);
    assert_true($r15mDb->rows !== [] && max($r15mDb->rows) <= 5000,
        'R15 medium: the order_item_digital backfill changes at most one batch of lines per statement (largest: ' . max($r15mDb->rows ?: [0]) . ')');
    $r15mDigital = $r15mDb->query('SELECT id, digital FROM order_items WHERE id >= 11999 ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    assert_equals([11999 => 0, 12000 => 0, 17001 => 1, 17002 => 1, 17003 => 0], array_map('intval', $r15mDigital),
        'R15 medium: the batched backfill gives every line the same digital flag as before, across id gaps and the last partial batch');
    assert_equals(0, (int)$r15mDb->query('SELECT COUNT(*) FROM order_items WHERE digital IS NULL')->fetchColumn(),
        'R15 medium: no line is left without its digital flag');
    $r15mDb->exec('UPDATE order_items SET digital = NULL WHERE id = 17002');
    $r15mMigration->up($r15mDb, true);
    assert_equals(1, (int)$r15mDb->query('SELECT digital FROM order_items WHERE id = 17002')->fetchColumn(),
        'R15 medium: re-running the backfill (after an interrupted run) completes the lines still unset');
} catch (Throwable $e) {
    $r15oFail('batched order_item_digital backfill', $e);
}
$r15mCli = (string)file_get_contents(dirname(__DIR__, 2) . '/migrate.php');
assert_true(preg_match('/MaintenanceMode::enter\(\$root.*finally \{\s*if \(\$ownMaintenance\) MaintenanceMode::leave\(\$root\);/s', $r15mCli) === 1,
    'R15 medium: cli/migrate.php apply puts the shop into maintenance while migrations run and always leaves it afterwards');

/** Records how many rows each executed UPDATE changed. */
class R15MigrationStatement extends PDOStatement {
    protected function __construct(private PDO $owner) {}
    public function execute(?array $params = null): bool {
        $ok = parent::execute($params);
        if (stripos(ltrim($this->queryString), 'UPDATE') === 0 && property_exists($this->owner, 'rows')) $this->owner->rows[] = $this->rowCount();
        return $ok;
    }
}
