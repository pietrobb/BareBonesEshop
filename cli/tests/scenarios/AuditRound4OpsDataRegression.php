<?php

// Review round 4 (ops/data): SQLite search plan, CSV import encoding, API catalog cache invalidation,
// backup/update snapshot retention and idempotent GDPR scrubbing.
echo "\n--- Review round 4: ops / data ---\n";
[$r4SavedDb, $r4SavedDriver] = [Db::get(), Db::driver()];
$r4Tmp = sys_get_temp_dir() . '/bbe-r4ops-' . bin2hex(random_bytes(4));
mkdir($r4Tmp, 0700, true);
$r4Db = new class('sqlite::memory:') extends PDO {
    /** @var list<string> */
    public array $prepared = [];
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->prepared[] = $query;
        return parent::prepare($query, $options);
    }
};
$r4Db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$r4Db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
try {
    (new Migrator($r4Db, TEST_CLI_DIR . '/../migrations', $r4Tmp . '/migrate.lock'))->apply(static fn($m) => null);
    Db::init($r4Db, 'sqlite');
    Cache::setDir($r4Tmp . '/cache');

    // CSV import: Excel SK/CZ "CSV" is Windows-1250; it is converted, a UTF-8 BOM is skipped, anything else refused.
    $r4Csv = static function (string $name, string $bytes) use ($r4Tmp): string {
        file_put_contents($r4Tmp . '/' . $name, $bytes);
        return $r4Tmp . '/' . $name;
    };
    $r4Cp = Import::csv($r4Csv('cp1250.csv', "sku,name,price\nR4-CP,\x8Eelezn\xFD \x9A\xE1l,10.00\nR4-CP2,Apple tea,2.00\n"));
    $r4Name = static fn(string $sku): ?string => ($row = $r4Db->query('SELECT name FROM products WHERE sku = ' . $r4Db->quote($sku))->fetch()) ? (string)$row['name'] : null;
    assert_equals([true, 2, 'windows-1250'], [$r4Cp['success'], $r4Cp['imported'], $r4Cp['encoding'] ?? null], 'R4 import: a Windows-1250 CSV is imported and reported as converted');
    assert_equals('Železný šál', $r4Name('R4-CP'), 'R4 import: Windows-1250 names are stored as valid UTF-8');
    $r4Bom = Import::csv($r4Csv('bom.csv', "\xEF\xBB\xBFsku,name,price\nR4-U8,Čaj,1.00\n"));
    assert_equals([true, 1, 'utf-8', 'Čaj'], [$r4Bom['success'], $r4Bom['imported'], $r4Bom['encoding'] ?? null, $r4Name('R4-U8')], 'R4 import: a UTF-8 CSV with BOM is imported unchanged');
    $r4Bad = Import::csv($r4Csv('bad.csv', "sku,name\nR4-X,\x81\x90\n"));
    assert_equals([false, 0, 'invalid', null], [$r4Bad['success'], $r4Bad['imported'], $r4Bad['encoding'] ?? null, $r4Name('R4-X')], 'R4 import: a file that is neither UTF-8 nor Windows-1250 is refused');
    assert_true(str_contains((string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Api.php'), 'JSON_INVALID_UTF8_SUBSTITUTE'), 'R4 API: invalid UTF-8 never turns a JSON response into an empty body');

    // Search: every SQLite FTS statement drives from the FTS index, never one FTS lookup per product row.
    $r4Db->prepared = [];
    $r4Found = Search::query('a', 1, 24)['total'] + count(Search::suggest('ap', 5)) + count(Search::withHighlights('apple', 1, 24));
    assert_true($r4Found > 0, 'R4 search: FTS queries find the imported products');
    $r4Bad = [];
    foreach (array_unique(array_filter($r4Db->prepared, static fn(string $sql): bool => str_contains($sql, 'products_fts MATCH'))) as $r4Sql) {
        $r4Plan = $r4Db->prepare('EXPLAIN QUERY PLAN ' . $r4Sql);
        preg_match_all('/:([a-z_0-9]+)/', $r4Sql, $r4Names);
        foreach (array_unique($r4Names[1]) as $r4Param) {
            $r4Plan->bindValue(':' . $r4Param, in_array($r4Param, ['limit', 'offset'], true) ? 10 : ($r4Param === 'store' ? 'default' : '"a" *'), in_array($r4Param, ['limit', 'offset'], true) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $r4Plan->execute();
        $r4Details = array_column($r4Plan->fetchAll(), 'detail');
        $r4Fts = $r4Products = null;
        foreach ($r4Details as $i => $r4Detail) {
            if ($r4Fts === null && str_contains($r4Detail, 'VIRTUAL TABLE')) $r4Fts = $i;
            if ($r4Products === null && preg_match('/^(SCAN|SEARCH) p\b/', $r4Detail)) $r4Products = $i;
        }
        if ($r4Fts === null || $r4Products === null || $r4Fts > $r4Products) $r4Bad[] = implode(' | ', $r4Details);
    }
    assert_equals([], $r4Bad, 'R4 search: the FTS table is the outer loop of every search statement (no products scan)');

    // API catalog cache: product and stock writes make cached product pages and listings stale.
    $r4Path = Cache::localePath('en', 'product/r4-cached.json');
    // Entries are seeded as written before "now"; a catalog change marks the current second.
    $r4Seed = static function (int $age = 30) use ($r4Path): void {
        Cache::writeJson($r4Path, ['product' => ['name' => 'old']]);
        touch(Cache::path($r4Path), time() - $age);
    };
    $r4Seed(-2);
    assert_equals('old', Cache::readCatalogJson($r4Path, 3600)['product']['name'] ?? null, 'R4 cache: an entry written after the last catalog change is served');
    $r4Seed();
    $r4Id = (int)$r4Db->query("SELECT id FROM products WHERE sku = 'R4-CP2'")->fetchColumn();
    ProductBulk::apply('archive', [$r4Id]);
    assert_equals(null, Cache::readCatalogJson($r4Path, 3600), 'R4 cache: deactivating a product makes cached product pages stale');
    $r4Seed();
    $r4Db->exec("UPDATE products SET stock = 5 WHERE sku = 'R4-CP'"); // no warehouse rows: the sync sells it out
    Warehouse::syncProductStock(['R4-CP']);
    assert_equals(null, Cache::readCatalogJson($r4Path, 3600), 'R4 cache: a stock sync that sells a product out makes cached product pages stale');
    $r4Seed();
    Import::csv($r4Csv('again.csv', "sku,name,price\nR4-U8,Čaj zelený,1.00\n"));
    assert_equals(null, Cache::readCatalogJson($r4Path, 3600), 'R4 cache: a CSV import makes cached product pages stale');
    assert_equals(4, substr_count((string)file_get_contents(TEST_CLI_DIR . '/../src/Http/ApiHandlers.php'), 'Cache::readCatalogJson('), 'R4 cache: API product, listing, filter and category reads honour catalog changes');

    // GDPR scrub_pii: orders.notes is scrubbed, a re-run touches no row scrubbed before.
    $r4Policy = json_decode((string)file_get_contents(TEST_CLI_DIR . '/../config/data-retention.json'), true)['policies']['orders_pii'];
    assert_true(in_array('notes', $r4Policy['scrub_fields'], true), 'R4 GDPR: orders_pii scrubs orders.notes (GdprService::PII_REGISTRY)');
    $r4Db->exec("INSERT INTO orders (order_number, store_id, customer_name, customer_email, customer_phone, shipping_addr, ip_address, notes, payment_method, subtotal, total, created_at)
        VALUES ('R4-1', 'default', 'Jana', 'jana@example.test', '+421900', '{}', '203.0.113.7', 'ring twice', 'transfer', 100, 100, '2000-01-01 00:00:00')");
    $r4First = GdprService::applyRetention('orders_pii', $r4Policy, 'apply');
    $r4Row = $r4Db->query("SELECT customer_email, notes FROM orders WHERE order_number = 'R4-1'")->fetch();
    assert_true(str_starts_with((string)$r4Row['notes'], '[erased') && str_starts_with((string)$r4Row['customer_email'], '[erased'), 'R4 GDPR: old order PII including notes is scrubbed');
    $r4Second = GdprService::applyRetention('orders_pii', $r4Policy, 'apply');
    assert_equals([1, 0], [(int)($r4First['affected'] ?? -1), (int)($r4Second['affected'] ?? -1)], 'R4 GDPR: a second scrub run affects no already scrubbed row');
} catch (Throwable $e) {
    assert_true(false, 'Round 4 ops/data regression raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    Db::init($r4SavedDb, $r4SavedDriver);
    Cache::setDir(TEST_CLI_DIR . '/../cache');
}

// Backup GC: pre-migration backups are capped, manual backups expire only past manual_max_age_days.
$r4Backups = $r4Tmp . '/backup';
$r4Live = new PDO('sqlite:' . $r4Tmp . '/live.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r4Live->exec('CREATE TABLE x (id INTEGER PRIMARY KEY)');
try {
    $r4Rows = [];
    foreach (range(1, 7) as $m) $r4Rows[] = [sprintf('2025-%02d-10T04:00:00+00:00', $m), 'pre-migration'];
    $r4Rows[] = ['2025-08-01T04:00:00+00:00', 'manual'];
    $r4Rows[] = ['2025-08-02T04:00:00+00:00', 'manual'];
    $r4Rows[] = [gmdate('Y-m-d\TH:i:s+00:00', time() - 3600), 'manual'];
    foreach ($r4Rows as [$r4When, $r4Label]) {
        $r4T = strtotime($r4When);
        $r4Dir = $r4Backups . '/storage/backups/' . gmdate('Y/m/d', $r4T);
        if (!is_dir($r4Dir)) mkdir($r4Dir, 0700, true);
        $r4File = $r4Dir . '/eshop-' . gmdate('Ymd-His', $r4T) . '.db';
        file_put_contents($r4File, str_repeat("\0", 32));
        file_put_contents($r4File . '.meta.json', json_encode(['timestamp' => $r4When, 'label' => $r4Label, 'sha256' => '0', 'size_bytes' => 32]));
        touch($r4File, $r4T);
    }
    $r4Labels = static function (): array {
        $out = [];
        foreach (BackupService::list() as $row) $out[] = (string)($row['meta']['label'] ?? '');
        $out = array_count_values($out);
        ksort($out);
        return $out;
    };
    $r4Cfg = ['enabled' => true, 'storage' => 'local', 'compression' => false, 'auto_gc_on_backup' => false,
        'retention' => ['daily' => 1, 'weekly' => 1, 'monthly' => 1, 'minimum_kept' => 1]];
    BackupService::init($r4Live, $r4Cfg, $r4Backups);
    BackupService::gc();
    clearstatcache();
    assert_equals(['manual' => 3, 'pre-migration' => 5], $r4Labels(), 'R4 backup GC: defaults keep the newest 5 pre-migration and every manual backup');
    $r4Cfg['retention'] += ['pre_migration' => 2, 'manual_max_age_days' => 30];
    BackupService::init($r4Live, $r4Cfg, $r4Backups);
    BackupService::gc();
    clearstatcache();
    assert_equals(['manual' => 1, 'pre-migration' => 2], $r4Labels(), 'R4 backup GC: pre_migration and manual_max_age_days cap labelled backups');
} catch (Throwable $e) {
    assert_true(false, 'Round 4 backup GC regression raised ' . get_class($e) . ': ' . $e->getMessage());
} finally {
    BackupService::reset();
    $r4Live = null;
}

// Updater: only the newest N pre-update snapshots stay, plus the installed release's and manual backups.
try {
    mkdir($r4Tmp . '/app', 0700, true);
    $r4State = $r4Tmp . '/updates';
    $r4Ids = [];
    foreach (range(1, 5) as $i) {
        $r4Ids[$i] = sprintf('2026010100000%d-%012x', $i, $i);
        mkdir($r4State . '/snapshots/' . $r4Ids[$i], 0700, true);
        file_put_contents($r4State . '/snapshots/' . $r4Ids[$i] . '/snapshot.json', json_encode(['id' => $r4Ids[$i], 'reason' => 'pre-update', 'status' => 'applied', 'created_at' => "2026-01-01T00:00:0{$i}+00:00"]));
    }
    $r4Manual = '20251201000000-00000000000a';
    mkdir($r4State . '/snapshots/' . $r4Manual, 0700, true);
    file_put_contents($r4State . '/snapshots/' . $r4Manual . '/snapshot.json', json_encode(['id' => $r4Manual, 'reason' => 'manual-backup', 'status' => 'prepared', 'created_at' => '2025-12-01T00:00:00+00:00']));
    file_put_contents($r4State . '/installed.json', json_encode(['version' => '1.0.0', 'snapshot' => $r4Ids[1]]));
    $r4Updater = new Updater($r4Tmp . '/app', $r4State);
    $r4Deleted = $r4Updater->pruneSnapshots(2);
    sort($r4Deleted);
    $r4Left = array_column($r4Updater->listSnapshots(), 'id');
    sort($r4Left);
    $r4Expect = [$r4Manual, $r4Ids[1], $r4Ids[4], $r4Ids[5]];
    sort($r4Expect);
    assert_equals([[$r4Ids[2], $r4Ids[3]], $r4Expect], [$r4Deleted, $r4Left], 'R4 update: pruneSnapshots keeps the newest N, the installed release snapshot and manual backups');
} catch (Throwable $e) {
    assert_true(false, 'Round 4 update snapshot regression raised ' . get_class($e) . ': ' . $e->getMessage());
} finally {
    FileSystem::removeTree($r4Tmp);
}
