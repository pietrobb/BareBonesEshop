<?php
/**
 * Round-10 review (2026-10-25) — operations.
 *
 * M4: the MySQL update rollback (MysqlUpdateDatabaseBackup::restore(), also run automatically when a
 *     migration fails) dropped and re-imported the live database while web traffic and workers were
 *     still connected, and kept no copy of live: a failed import left the shop with an empty
 *     database. It now refuses while other sessions use live, dumps live first and re-imports that
 *     copy if replacing live fails — like restoreBackupDump(). The Updater restores the database
 *     before the code, so a refused rollback changes nothing.
 * M5: HttpErpAdapter pulled the whole catalog (and stock) in one request; past the 1 MiB response
 *     limit Sync::run() threw before it pushed any order. Listings are now paged (limit + cursor),
 *     and a failed catalog or stock pull no longer keeps orders from being pushed.
 */

echo "\n--- Round 10: operations (M4 MySQL update rollback, M5 ERP paging) ---\n";

$r10oRoot = test_fixture_path('r10-ops');
mkdir($r10oRoot, 0700, true);
$r10oError = static function (callable $callback): string {
    try {
        $callback();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return '';
};
$r10oTree = static function (string $root, array $files): void {
    foreach ($files as $path => $content) {
        if (!is_dir(dirname($root . '/' . $path))) mkdir(dirname($root . '/' . $path), 0750, true);
        file_put_contents($root . '/' . $path, $content);
    }
};

final class R10oRefusingBackup implements UpdateDatabaseBackup
{
    public bool $refuse = true;
    public int $restores = 0;

    public function probe(string $workingDirectory): array
    {
        return [];
    }

    public function create(string $snapshotDirectory): array
    {
        return ['driver' => 'mysql', 'fake' => true];
    }

    public function restore(string $snapshotDirectory, array $metadata): void
    {
        if ($this->refuse) {
            throw new RuntimeException('Refusing to roll back the live MySQL database: 1 other session(s) are connected to it');
        }
        $this->restores++;
    }
}

// ---------- M4a: Updater restores the database first; a refused database rollback leaves the code alone ----------
if (getenv('TEST_MYSQL_DSN')) {
    // A MySQL rollback calls Db::reset(); the suite connection is put back afterwards.
    $r10oSuitePdo = Db::get();
    $r10oSuiteDriver = Db::driver();
    try {
        $r10oInstalled = $r10oRoot . '/installed';
        $r10oTree($r10oRoot . '/base', ['src/Core.php' => "r10 old\n"]);
        $r10oTree($r10oRoot . '/target', ['src/Core.php' => "r10 new\n", 'migrations/20991231000001_r10o.php' => "<?php return new class {};\n"]);
        $r10oTree($r10oInstalled, ['src/Core.php' => "r10 old\n"]);
        UpdatePackageBuilder::build($r10oRoot . '/base', $r10oRoot . '/target', $r10oRoot . '/package', '10.1.0', '10.0.0');
        $r10oMysql = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $r10oBackup = new R10oRefusingBackup();
        $r10oUpdater = new Updater($r10oInstalled, $r10oRoot . '/state', $r10oMysql, static function (): void {
            throw new RuntimeException('r10 simulated migration failure');
        }, $r10oBackup);
        $r10oMysql = null;
        $r10oUpdater->baseline('10.0.0');
        $r10oApplyError = $r10oError(static fn() => $r10oUpdater->apply(new UpdatePackage($r10oRoot . '/package', null, true)));
        assert_true(str_contains($r10oApplyError, 'r10 simulated migration failure') && str_contains($r10oApplyError, 'automatic rollback also failed')
            && str_contains($r10oApplyError, 'Refusing to roll back'), 'R10 M4: a refused automatic MySQL rollback is reported with the update failure');
        assert_equals("r10 new\n", (string)@file_get_contents($r10oInstalled . '/src/Core.php'),
            'R10 M4: a refused database rollback leaves the code untouched (database is restored before the files)');
        $r10oSnapshot = preg_match('/--snapshot=(\d{14}-[0-9a-f]{12})/', $r10oApplyError, $r10oM) === 1 ? $r10oM[1] : '';
        assert_true($r10oSnapshot !== '' && str_contains($r10oApplyError, '--force --accept-data-loss'),
            'R10 M4: the error names the rollback command to run once writers are stopped');
        $r10oBackup->refuse = false;
        if ($r10oSnapshot !== '') $r10oUpdater->rollback($r10oSnapshot, true, true);
        assert_true($r10oBackup->restores === 1 && (string)@file_get_contents($r10oInstalled . '/src/Core.php') === "r10 old\n",
            'R10 M4: that manual rollback restores the database and the code');
    } catch (Throwable $r10oFailure) {
        assert_true(false, 'R10 M4 updater order (' . $r10oFailure->getMessage() . ')');
    } finally {
        Db::init($r10oSuitePdo, $r10oSuiteDriver);
    }
} else {
    echo "  [SKIP] R10 M4 updater rollback order (set TEST_MYSQL_DSN)\n";
}

// ---------- M4b: live MySQL/MariaDB — gate, safety copy, automatic re-import ----------
if (trim((string)(getenv('TEST_MYSQL_UPDATE_DATABASE') ?: '')) === '') {
    if (getenv('BAREBONES_REQUIRE_MYSQL_UPDATE_TEST') === '1') {
        assert_true(false, 'R10 M4 live: CI requires TEST_MYSQL_UPDATE_DATABASE');
    } else {
        echo "  [SKIP] R10 M4 live MySQL/MariaDB update rollback (set TEST_MYSQL_UPDATE_DATABASE and TEST_MYSQL_UPDATE_*)\n";
    }
} else {
    $r10oHost = (string)(getenv('TEST_MYSQL_UPDATE_HOST') ?: '127.0.0.1');
    $r10oPort = (int)(getenv('TEST_MYSQL_UPDATE_PORT') ?: 3306);
    $r10oUser = (string)(getenv('TEST_MYSQL_UPDATE_USER') ?: 'root');
    $r10oPass = (string)(getenv('TEST_MYSQL_UPDATE_PASS') ?: '');
    $r10oDumpBin = (string)(getenv('TEST_MYSQL_DUMP_BINARY') ?: 'mysqldump');
    $r10oClientBin = (string)(getenv('TEST_MYSQL_CLIENT_BINARY') ?: 'mysql');
    $r10oTag = substr((string)preg_replace('/[^A-Za-z0-9_]/', '', (string)getenv('TEST_MYSQL_UPDATE_DATABASE')), 0, 24) . '_r10o' . bin2hex(random_bytes(2));
    $r10oLive = $r10oTag . '_live';
    $r10oProbe = $r10oTag . '_update_probe';
    $r10oQ = static fn(string $name): string => '`' . str_replace('`', '``', $name) . '`';
    $r10oServer = new PDO("mysql:host={$r10oHost};port={$r10oPort};charset=utf8mb4", $r10oUser, $r10oPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r10oConnect = static fn(): PDO => new PDO("mysql:host={$r10oHost};port={$r10oPort};dbname={$r10oLive};charset=utf8mb4", $r10oUser, $r10oPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r10oAdapter = static fn(string $client = ''): MysqlUpdateDatabaseBackup => new MysqlUpdateDatabaseBackup(
        $r10oHost, $r10oPort, $r10oLive, $r10oUser, $r10oPass, 'utf8mb4', $r10oProbe, $r10oDumpBin, $client ?: $r10oClientBin
    );
    $r10oSafetyDumps = static fn(string $dir): array => glob($dir . '/pre-rollback-*.sql') ?: [];
    try {
        $r10oServer->exec('CREATE DATABASE ' . $r10oQ($r10oLive) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $r10oPdo = $r10oConnect();
        $r10oOwn = [(int)$r10oPdo->query('SELECT CONNECTION_ID()')->fetchColumn()];
        $r10oPdo->exec('CREATE TABLE orders (id INT AUTO_INCREMENT PRIMARY KEY, note VARCHAR(100) NOT NULL) ENGINE=InnoDB');
        $r10oPdo->exec("INSERT INTO orders (note) VALUES ('one'), ('two'), ('three')");
        $r10oSnap = $r10oRoot . '/snapshot';
        mkdir($r10oSnap, 0700);
        $r10oMeta = $r10oAdapter()->create($r10oSnap);

        // Writes after the update snapshot: what a rollback discards.
        $r10oPdo->exec("INSERT INTO orders (note) VALUES ('paid after the update')");
        $r10oPdo->exec('CREATE TABLE migration_added (id INT PRIMARY KEY) ENGINE=InnoDB');
        $r10oDrifted = static fn(): bool => (int)$r10oPdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 4
            && (int)$r10oPdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migration_added'")->fetchColumn() === 1;

        $r10oIntruder = $r10oConnect();
        $r10oGateError = $r10oError(static fn() => $r10oAdapter()->restore($r10oSnap, $r10oMeta, $r10oOwn));
        $r10oIntruder = null;
        assert_true(str_contains($r10oGateError, 'other session') && $r10oDrifted(),
            'R10 M4 live: an update rollback is refused while another session (web/worker) uses live; live is untouched');
        assert_equals([], $r10oSafetyDumps($r10oSnap), 'R10 M4 live: a refused rollback dumps nothing');
        usleep(300000);

        // The live import fails once: the safety copy of live is re-imported and verified.
        $r10oFailMarker = $r10oRoot . '/fail-live-import';
        touch($r10oFailMarker);
        $r10oFailingClient = $r10oRoot . '/client-fail-live.sh';
        file_put_contents($r10oFailingClient, "#!/bin/sh\n"
            . 'for a in "$@"; do if [ "$a" = ' . escapeshellarg('--database=' . $r10oLive) . ' ] && [ -f ' . escapeshellarg($r10oFailMarker) . ' ]; then'
            . ' rm -f ' . escapeshellarg($r10oFailMarker) . '; echo "simulated live import failure" >&2; exit 1; fi; done' . "\n"
            . 'exec ' . escapeshellarg($r10oClientBin) . ' "$@"' . "\n");
        chmod($r10oFailingClient, 0700);
        $r10oCode = 0;
        $r10oFailed = '';
        try {
            $r10oAdapter($r10oFailingClient)->restore($r10oSnap, $r10oMeta, $r10oOwn);
        } catch (Throwable $e) {
            $r10oFailed = $e->getMessage();
            $r10oCode = $e->getCode();
        }
        $r10oPdo->exec('USE ' . $r10oQ($r10oLive));
        assert_true(str_contains($r10oFailed, 'previous database was re-imported and verified') && $r10oCode === MysqlUpdateDatabaseBackup::RESTORE_FAILED_AFTER_DROP && $r10oDrifted(),
            'R10 M4 live: a failed live import re-imports the pre-rollback copy of live automatically (no empty database)');
        $r10oKept = $r10oSafetyDumps($r10oSnap);
        assert_true(count($r10oKept) === 1 && str_contains($r10oFailed, $r10oKept[0]), 'R10 M4 live: the pre-rollback dump is kept and named in the error');
        foreach ($r10oKept as $r10oFile) @unlink($r10oFile);

        $r10oAdapter()->restore($r10oSnap, $r10oMeta, $r10oOwn);
        $r10oPdo->exec('USE ' . $r10oQ($r10oLive));
        assert_true((int)$r10oPdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 3
            && (int)$r10oPdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migration_added'")->fetchColumn() === 0,
            'R10 M4 live: with no other session the rollback restores the snapshot');
        $r10oKept = $r10oSafetyDumps($r10oSnap);
        assert_true(count($r10oKept) === 1 && (MysqlUpdateDatabaseBackup::countDumpRows($r10oKept[0])['orders'] ?? null) === 4,
            'R10 M4 live: the writes the rollback discarded stay recoverable in the pre-rollback dump');
        if (PHP_OS_FAMILY !== 'Windows' && $r10oKept !== []) {
            assert_equals(0600, fileperms($r10oKept[0]) & 0777, 'R10 M4 live: the pre-rollback dump is owner-only');
        }
    } catch (Throwable $r10oFailure) {
        assert_true(false, 'R10 M4 live (' . $r10oFailure->getMessage() . ')');
    } finally {
        $r10oPdo = null;
        $r10oIntruder = null;
        foreach ([$r10oLive, $r10oProbe] as $r10oDb) {
            try { $r10oServer->exec('DROP DATABASE IF EXISTS ' . $r10oQ($r10oDb)); } catch (Throwable $e) { /* best effort */ }
        }
    }
}

// ---------- M5: HttpErpAdapter pages listings; a failed catalog pull still pushes orders ----------
$r10oErpConfig = ['integrations' => ['http_erp' => ['base_url' => 'https://erp.test', 'api_key' => str_repeat('e', 24), 'page_size' => 2]]];
$r10oErpCalls = [];
$r10oErpPages = [];
JsonHttpClient::setFixtureTransport(static function (string $method, string $url, string $body, array $headers) use (&$r10oErpCalls, &$r10oErpPages): array {
    $r10oErpCalls[] = $method . ' ' . $url;
    $path = (string)parse_url($url, PHP_URL_PATH);
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    if ($path === '/v1/health') return ['status' => 200, 'body' => ['status' => 'ok']];
    if ($path === '/v1/orders' && $method === 'POST') return ['status' => 201, 'body' => ['id' => 'R10O-' . (int)(json_decode($body, true)['order']['id'] ?? 0)]];
    if (str_ends_with($path, '/status')) return ['status' => 200, 'body' => ['status' => 'processing']];
    $page = $r10oErpPages[$path][(string)($query['cursor'] ?? '')] ?? null;
    return $page ?? ['status' => 404, 'body' => ['error' => ['code' => 'fixture_not_found']]];
});
try {
    $r10oErp = new HttpErpAdapter($r10oErpConfig);
    $r10oErpPages['/v1/products'] = [
        '' => ['status' => 200, 'body' => ['data' => [['sku' => 'R10O-1'], ['sku' => 'R10O-2']], 'next_cursor' => 'c2']],
        'c2' => ['status' => 200, 'body' => ['data' => [['sku' => 'R10O-3']], 'next_cursor' => null]],
    ];
    $r10oSkus = array_column(iterator_to_array($r10oErp->pullProducts(new DateTimeImmutable('2026-10-01T00:00:00+00:00')), false), 'sku');
    assert_equals(['R10O-1', 'R10O-2', 'R10O-3'], $r10oSkus, 'R10 M5: the product delta follows next_cursor across pages (it read only the first response)');
    assert_true(count($r10oErpCalls) === 2 && str_contains($r10oErpCalls[1], 'cursor=c2') && str_contains($r10oErpCalls[1], 'limit=2')
        && str_contains($r10oErpCalls[1], 'updated_since=2026-10-01T00%3A00%3A00%2B00%3A00'),
        'R10 M5: every page asks for page_size rows and keeps the updated_since filter');

    $r10oErpPages['/v1/stock'] = [
        '' => ['status' => 200, 'body' => ['data' => [['sku' => 'R10O-1', 'qty' => 1], ['sku' => 'R10O-2', 'qty' => 2]], 'next_cursor' => 's2']],
        's2' => ['status' => 200, 'body' => ['data' => [['sku' => 'R10O-3', 'qty' => 3]]]],
    ];
    assert_equals([1, 2, 3], array_map('intval', array_column($r10oErp->pullStock(), 'qty')), 'R10 M5: stock is paged the same way');

    $r10oErpPages['/v1/products'] = [
        '' => ['status' => 200, 'body' => ['data' => [], 'next_cursor' => 'loop']],
        'loop' => ['status' => 200, 'body' => ['data' => [], 'next_cursor' => 'loop']],
    ];
    assert_true(str_contains($r10oError(static fn() => iterator_to_array($r10oErp->pullProducts())), 'repeated a page cursor'),
        'R10 M5: an ERP that repeats a cursor fails instead of paging forever');
} catch (Throwable $r10oFailure) {
    assert_true(false, 'R10 M5 paging (' . $r10oFailure->getMessage() . ')');
}

// The catalog pull fails on its second page and stock fails outright: the paid order is still pushed.
$r10oAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r10oSavedAdapters = $r10oAdapters->getValue();
$r10oPdo = Db::get();
$r10oTemplate = $r10oPdo->query("SELECT * FROM orders WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$r10oPdo->beginTransaction();
try {
    assert_true(is_array($r10oTemplate), 'R10 M5: the suite has an order to copy');
    unset($r10oTemplate['id'], $r10oTemplate['erp_id'], $r10oTemplate['erp_status']);
    $r10oPdo->exec("UPDATE orders SET erp_id = COALESCE(erp_id, 'r10o-parked') WHERE store_id = 'default'");
    $r10oRow = array_replace($r10oTemplate, ['order_number' => 'R10O-' . bin2hex(random_bytes(4)), 'status' => 'paid', 'erp_next_retry_at' => null]);
    $r10oPdo->prepare('INSERT INTO orders (' . implode(',', array_keys($r10oRow)) . ') VALUES (' . implode(',', array_fill(0, count($r10oRow), '?')) . ')')->execute(array_values($r10oRow));
    $r10oOrderId = (int)$r10oPdo->lastInsertId();
    $r10oErpPages['/v1/products'] = [
        '' => ['status' => 200, 'body' => ['data' => [], 'next_cursor' => 'p2']],
        'p2' => ['status' => 502, 'body' => ['error' => ['code' => 'upstream_timeout']]],
    ];
    $r10oErpPages['/v1/stock'] = [];
    $r10oAdapters->setValue(null, ['integration' => new HttpErpAdapter($r10oErpConfig)] + $r10oSavedAdapters);
    $r10oWatermark = Sync::watermark();
    $r10oRun = [];
    $r10oSyncError = $r10oError(static function () use (&$r10oRun): void { $r10oRun = Sync::run(); });
    assert_equals('', $r10oSyncError, 'R10 M5: a catalog page that fails does not abort the sync run');
    assert_equals('R10O-' . $r10oOrderId, (string)$r10oPdo->query('SELECT erp_id FROM orders WHERE id = ' . $r10oOrderId)->fetchColumn(),
        'R10 M5: the paid order is pushed to the ERP although the catalog pull failed');
    assert_true(str_contains((string)($r10oRun['catalog_error'] ?? ''), 'products: Integration returned HTTP 502')
        && str_contains((string)($r10oRun['catalog_error'] ?? ''), 'stock:'), 'R10 M5: the run reports which catalog pulls failed');
    assert_equals($r10oWatermark, Sync::watermark(), 'R10 M5: an interrupted product pull does not advance the delta watermark');
} catch (Throwable $r10oFailure) {
    assert_true(false, 'R10 M5 sync (' . $r10oFailure->getMessage() . ')');
} finally {
    if ($r10oPdo->inTransaction()) $r10oPdo->rollBack();
    $r10oAdapters->setValue(null, $r10oSavedAdapters);
    JsonHttpClient::setFixtureTransport(null);
}
$r10oSyncCli = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/cli/sync.php');
assert_true(str_contains($r10oSyncCli, "catalog_error") && str_contains($r10oSyncCli, 'exit(1)'), 'R10 M5: cli/sync.php exits non-zero when the catalog pull failed');

// ---------- M9: CSP reports are deduplicated, capped per reporter/store and kept 7 days ----------
$r10oCsp = Db::get();
$r10oCsp->beginTransaction();
try {
    $r10oCsp->exec('DELETE FROM csp_violations');
    $r10oNow = time();
    $r10oCspEntry = static fn(string $blocked): array => ['type' => 'csp-violation', 'url' => 'https://shop.example/pokladna',
        'body' => ['effectiveDirective' => 'img-src', 'blockedURL' => $blocked, 'documentURL' => 'https://shop.example/pokladna', 'disposition' => 'enforce']];
    $r10oSame = (string)json_encode(array_fill(0, 20, $r10oCspEntry('https://tracker.example/p.gif')));
    assert_equals(1, CspReports::ingest($r10oSame, $r10oCsp, $r10oNow, '203.0.113.50'), 'R10 M9: a batch repeating one violation stores it once (it stored 20 rows)');
    $r10oStoredFlood = 0;
    for ($r10oI = 0; $r10oI < 150; $r10oI++) {
        $r10oStoredFlood += CspReports::ingest((string)json_encode([$r10oCspEntry('https://flood' . $r10oI . '.example/x')]), $r10oCsp, $r10oNow, '203.0.113.50');
    }
    assert_equals(99, $r10oStoredFlood, 'R10 M9: one reporter stores at most 100 reports an hour (a flood grew the table without bound)');
    assert_equals(1, CspReports::ingest((string)json_encode([$r10oCspEntry('https://other.example/x')]), $r10oCsp, $r10oNow, '203.0.113.51'),
        'R10 M9: another reporter is not blocked by that flood');
    assert_equals(1, CspReports::ingest((string)json_encode([$r10oCspEntry('https://later.example/x')]), $r10oCsp, $r10oNow + 3601, '203.0.113.50'),
        'R10 M9: the reporter cap is per hour');
    $r10oCsp->exec('DELETE FROM csp_violations');
    $r10oOld = $r10oCsp->prepare("INSERT INTO csp_violations (store_id, directive, blocked, document_path, disposition, created_at) VALUES ('default', 'img-src', '', '/', 'enforce', :at)");
    $r10oOld->execute([':at' => gmdate('Y-m-d H:i:s', $r10oNow - 8 * 86400)]);
    $r10oOld->execute([':at' => gmdate('Y-m-d H:i:s', $r10oNow - 6 * 86400)]);
    assert_true(CspReports::purge($r10oCsp, $r10oNow) === 1 && (int)$r10oCsp->query('SELECT COUNT(*) FROM csp_violations')->fetchColumn() === 1,
        'R10 M9: reports are kept 7 days (was 14)');
} catch (Throwable $r10oFailure) {
    assert_true(false, 'R10 M9 (' . $r10oFailure->getMessage() . ')');
} finally {
    if ($r10oCsp->inTransaction()) $r10oCsp->rollBack();
}

// ---------- F13 + SQLite restore: the updater sets file modes itself; a restored database keeps live's mode ----------
if (PHP_OS_FAMILY === 'Windows') {
    echo "  [SKIP] R10 F13 / SQLite restore file modes (POSIX permissions)\n";
} else {
    try {
        $r10oPerm = $r10oRoot . '/perm';
        $r10oTree($r10oPerm . '/base', ['src/Shop.php' => "<?php // old\n", 'bin/tool.sh' => "#!/bin/sh\necho old\n"]);
        $r10oTree($r10oPerm . '/target', ['src/Shop.php' => "<?php // new\n", 'bin/tool.sh' => "#!/bin/sh\necho new\n"]);
        $r10oTree($r10oPerm . '/installed', ['src/Shop.php' => "<?php // old\n", 'bin/tool.sh' => "#!/bin/sh\necho old\n"]);
        chmod($r10oPerm . '/installed/src/Shop.php', 0644);
        chmod($r10oPerm . '/installed/bin/tool.sh', 0755);
        UpdatePackageBuilder::build($r10oPerm . '/base', $r10oPerm . '/target', $r10oPerm . '/package', '10.2.0', '10.1.0');
        chmod($r10oPerm . '/package/payload/src/Shop.php', 0666);
        chmod($r10oPerm . '/package/payload/bin/tool.sh', 0777);
        $r10oPermUpdater = new Updater($r10oPerm . '/installed', $r10oPerm . '/state');
        $r10oPermUpdater->baseline('10.1.0');
        $r10oPermUpdater->apply(new UpdatePackage($r10oPerm . '/package', null, true));
        clearstatcache();
        assert_equals([0644, 0755], [fileperms($r10oPerm . '/installed/src/Shop.php') & 0777, fileperms($r10oPerm . '/installed/bin/tool.sh') & 0777],
            'R10 F13: installed files get 0644 / 0755, not the package\'s 0666 / 0777');

        // Updater rollback of a SQLite database (installAbsoluteFile): the live file keeps its mode.
        $r10oLiveDb = $r10oPerm . '/live.sqlite';
        $r10oSnapDb = $r10oPerm . '/snapshot.sqlite';
        file_put_contents($r10oLiveDb, 'live');
        file_put_contents($r10oSnapDb, 'snapshot');
        chmod($r10oLiveDb, 0664);
        chmod($r10oSnapDb, 0600);
        $r10oInstall = new ReflectionMethod(Updater::class, 'installAbsoluteFile');
        $r10oInstall->invoke($r10oPermUpdater, $r10oSnapDb, $r10oLiveDb);
        clearstatcache();
        assert_true(file_get_contents($r10oLiveDb) === 'snapshot' && (fileperms($r10oLiveDb) & 0777) === 0664,
            'R10 SQLite rollback: the restored database keeps the live file mode (0664), not the snapshot\'s 0600');

        // BackupService SQLite restore: same.
        $r10oBk = $r10oPerm . '/backup';
        mkdir($r10oBk, 0700, true);
        $r10oBkLive = $r10oBk . '/live.db';
        $r10oBkDb = new PDO('sqlite:' . $r10oBkLive, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $r10oBkDb->exec('PRAGMA journal_mode=WAL');
        $r10oBkDb->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
        $r10oBkDb->exec('CREATE TABLE schema_migrations (name TEXT PRIMARY KEY)');
        $r10oBkDb->exec("INSERT INTO notes (body) VALUES ('backup state')");
        BackupService::init($r10oBkDb, [
            'enabled' => true, 'compression' => false, 'auto_gc_on_backup' => false, 'wal_checkpoint' => true,
            'retention' => ['daily' => 7, 'weekly' => 4, 'monthly' => 6, 'minimum_kept' => 3],
            'log_file' => 'backup.log', 'storage_dir' => $r10oBk . '/storage',
        ], $r10oBk);
        $r10oBackupMeta = BackupService::create(['label' => 'r10o']);
        $r10oBkDb = null;
        chmod($r10oBkLive, 0664);
        BackupService::restore($r10oBackupMeta['remote_path'], ['skip_snapshot' => true]);
        clearstatcache();
        assert_equals(0664, fileperms($r10oBkLive) & 0777, 'R10 SQLite restore: the restored database keeps the live file mode (it became 0640, read-only for www-data)');
    } catch (Throwable $r10oFailure) {
        assert_true(false, 'R10 F13 / SQLite modes (' . $r10oFailure->getMessage() . ')');
    } finally {
        BackupService::reset();
    }
}

// ---------- Setup::createConfig, StoreSettings: .tmp files, backreferences, lost updates; *.tmp denied over HTTP ----------
$r10oSetup = $r10oRoot . '/setup';
mkdir($r10oSetup, 0700, true);
copy(dirname(TEST_CLI_DIR) . '/config.example.php', $r10oSetup . '/config.example.php');
try {
    Setup::createConfig($r10oSetup, '', 'shop$1x$0@example.test');
    $r10oCreated = require $r10oSetup . '/config.php';
    assert_equals('shop$1x$0@example.test', $r10oCreated['admin_email'] ?? null, 'R10 setup: $1 and $0 in a value are written literally (they were regex backreferences)');
} catch (Throwable $e) {
    assert_true(false, 'R10 setup: createConfig with $1 in a value (' . $e->getMessage() . ')');
}
$r10oBroken = $r10oRoot . '/setup-broken';
mkdir($r10oBroken, 0700, true);
file_put_contents($r10oBroken . '/config.example.php', "<?php\nreturn ['admin_identity_key' => '', 'broken' => ;\n");
$r10oBrokenError = $r10oError(static fn() => Setup::createConfig($r10oBroken));
assert_true($r10oBrokenError !== '' && !is_file($r10oBroken . '/config.php') && glob($r10oBroken . '/*.tmp') === [],
    'R10 setup: a generated config that does not parse leaves no .tmp copy with the identity key behind');

$r10oStores = $r10oRoot . '/stores';
mkdir($r10oStores, 0700, true);
$r10oStoresPath = $r10oStores . '/stores.json';
file_put_contents($r10oStoresPath, json_encode(['stores' => [
    'r10a' => ['name' => 'A', 'legal' => ['seller' => 'r10b']],
    'r10b' => ['name' => 'B', 'legal' => ['seller' => 'r10a']],
]], JSON_PRETTY_PRINT) . "\n");
// Another admin (a second process) has read stores.json and is about to save store B.
$r10oOther = $r10oStores . '/other-admin.php';
file_put_contents($r10oOther, '<?php
$path = $argv[1];
$lock = fopen($path . ".lock", "c");
flock($lock, LOCK_EX);
$data = json_decode(file_get_contents($path), true);
echo "READ\n";
usleep(800000);
$data["stores"]["r10b"]["name"] = "B by the other admin";
file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT) . "\n");
flock($lock, LOCK_UN);
');
StoreSettings::setPathForTesting($r10oStoresPath);
try {
    $r10oProc = proc_open([PHP_BINARY, $r10oOther, $r10oStoresPath], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r10oPipes);
    $r10oSignal = (string)fgets($r10oPipes[1]);
    $r10oSaved = StoreSettings::save('r10a', ['store_name' => 'A by this admin']);
    stream_get_contents($r10oPipes[1]);
    foreach ($r10oPipes as $r10oPipe) fclose($r10oPipe);
    proc_close($r10oProc);
    $r10oNames = array_map(static fn(array $s): string => (string)$s['name'], StoreSettings::load()['stores'] ?? []);
    assert_true(trim($r10oSignal) === 'READ' && $r10oSaved['ok'] === true
        && $r10oNames === ['r10a' => 'A by this admin', 'r10b' => 'B by the other admin'],
        'R10 StoreSettings: two admins saving different stores at once both keep their edit (one was lost) ' . json_encode($r10oNames));
    assert_equals([], glob($r10oStores . '/*.tmp') ?: [], 'R10 StoreSettings: no .tmp file is left next to stores.json');
} catch (Throwable $e) {
    assert_true(false, 'R10 StoreSettings lock (' . $e->getMessage() . ')');
} finally {
    StoreSettings::setPathForTesting(null);
}

// *.tmp next to config.php / stores.json is never served (.htaccess, which router.php applies, and nginx).
$r10oHtaccess = str_replace("\r\n", "\n", (string)file_get_contents(dirname(TEST_CLI_DIR) . '/.htaccess'));
$r10oHtDenied = static function (string $path) use ($r10oHtaccess): bool {
    $conditional = false;
    foreach (explode("\n", $r10oHtaccess) as $line) {
        $line = trim($line);
        if (str_starts_with($line, 'RewriteCond ')) { $conditional = true; continue; }
        if (preg_match('/^RewriteRule\s+(\S+)\s+(\S+)\s+\[([^\]]*)\]$/', $line, $m)) {
            if (!$conditional && $m[2] === '-' && preg_match('/\bF\b/', $m[3])
                && preg_match('#' . str_replace('#', '\#', $m[1]) . '#' . (stripos($m[3], 'NC') !== false ? 'i' : ''), $path) === 1) return true;
            $conditional = false;
        }
    }
    return false;
};
$r10oNginx = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/deploy/nginx.conf');
preg_match_all('/location ~\* (\\\\\.\(\?:\S+\)\$) \{ return 403; \}/', $r10oNginx, $r10oNginxRules);
$r10oNgDenied = static function (string $path) use ($r10oNginxRules): bool {
    foreach ($r10oNginxRules[1] as $rule) if (preg_match('#' . str_replace('#', '\#', $rule) . '#i', '/' . $path) === 1) return true;
    return false;
};
foreach (['config.php.1a2b3c4d.tmp', 'stores.json.0123456789ab.tmp', 'stores.json.lock'] as $r10oTmp) {
    assert_true($r10oHtDenied($r10oTmp) && count($r10oNginxRules[1]) === 2 && $r10oNgDenied($r10oTmp),
        "R10 deny: /{$r10oTmp} is refused by .htaccess/router.php and nginx");
}

// ---------- Retention: failed jobs, manual backups and manual update snapshots no longer grow forever ----------
$r10oRet = $r10oRoot . '/retention';
mkdir($r10oRet . '/jobs/failed', 0700, true);
$r10oRetDb = Db::get();
$r10oRetJob = $r10oRetDb->prepare("INSERT INTO queue_jobs (id, type, payload, state, available_at, correlation_id, created_at, updated_at)
    VALUES (:id, 'r10o_ret', '{}', 'failed', :at, 'r10o', :at, :at)");
$r10oRetIds = ['failed_old' => bin2hex(random_bytes(16)), 'failed_recent' => bin2hex(random_bytes(16))];
$r10oRetJob->execute([':id' => $r10oRetIds['failed_old'], ':at' => gmdate('Y-m-d H:i:s', time() - 91 * 86400)]);
$r10oRetJob->execute([':id' => $r10oRetIds['failed_recent'], ':at' => gmdate('Y-m-d H:i:s', time() - 40 * 86400)]);
file_put_contents($r10oRet . '/jobs/failed/old.json', '{}');
touch($r10oRet . '/jobs/failed/old.json', time() - 91 * 86400);
file_put_contents($r10oRet . '/jobs/failed/recent.json', '{}');
try {
    JobQueue::useBackend(new DatabaseQueue($r10oRetDb, 30));
    $r10oRetDeleted = RetentionCleanup::run(['jobs_dir' => $r10oRet . '/jobs']);
    $r10oRetLeft = $r10oRetDb->query("SELECT id FROM queue_jobs WHERE type = 'r10o_ret'")->fetchAll(PDO::FETCH_COLUMN);
    assert_true($r10oRetLeft === [$r10oRetIds['failed_recent']] && ($r10oRetDeleted['failed_jobs'] ?? 0) === 1,
        'R10 retention: failed database jobs older than failed_jobs_days (90) are deleted, younger ones stay (they were kept forever)');
    assert_true(!is_file($r10oRet . '/jobs/failed/old.json') && is_file($r10oRet . '/jobs/failed/recent.json'),
        'R10 retention: failed job files older than failed_jobs_days are deleted, younger ones stay');
} catch (Throwable $e) {
    assert_true(false, 'R10 retention failed jobs (' . $e->getMessage() . ')');
} finally {
    JobQueue::resetBackend();
    $r10oRetDb->exec("DELETE FROM queue_jobs WHERE type = 'r10o_ret'");
}

// Manual backups: default 180 days; an explicit 0 still keeps them.
$r10oRetLive = new PDO('sqlite:' . $r10oRet . '/live.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r10oRetLive->exec('CREATE TABLE x (id INTEGER PRIMARY KEY)');
try {
    foreach ([time() - 200 * 86400, time() - 10 * 86400, time() - 3600] as $r10oT) {
        $r10oDir = $r10oRet . '/backup/storage/backups/' . gmdate('Y/m/d', $r10oT);
        if (!is_dir($r10oDir)) mkdir($r10oDir, 0700, true);
        $r10oFile = $r10oDir . '/eshop-' . gmdate('Ymd-His', $r10oT) . '.db';
        file_put_contents($r10oFile, str_repeat("\0", 32));
        file_put_contents($r10oFile . '.meta.json', json_encode(['timestamp' => gmdate('Y-m-d\TH:i:s+00:00', $r10oT), 'label' => 'manual', 'sha256' => '0', 'size_bytes' => 32]));
    }
    $r10oRetCfg = ['enabled' => true, 'storage' => 'local', 'compression' => false, 'auto_gc_on_backup' => false,
        'retention' => ['daily' => 1, 'weekly' => 1, 'monthly' => 1, 'minimum_kept' => 1]];
    $r10oRetCfg['retention']['manual_max_age_days'] = 0;
    BackupService::init($r10oRetLive, $r10oRetCfg, $r10oRet . '/backup');
    BackupService::gc();
    assert_equals(3, count(BackupService::list()), 'R10 backup GC: manual_max_age_days = 0 still keeps every manual backup');
    unset($r10oRetCfg['retention']['manual_max_age_days']);
    BackupService::init($r10oRetLive, $r10oRetCfg, $r10oRet . '/backup');
    BackupService::gc();
    clearstatcache();
    assert_equals(2, count(BackupService::list()), 'R10 backup GC: by default a manual backup older than 180 days is deleted (manual backups were kept forever)');
} catch (Throwable $e) {
    assert_true(false, 'R10 backup GC (' . $e->getMessage() . ')');
} finally {
    BackupService::reset();
    $r10oRetLive = null;
}

// `cli/update.php backup` snapshots: the newest update_snapshots_keep stay, like pre-update ones.
try {
    mkdir($r10oRet . '/app', 0700, true);
    $r10oRetState = $r10oRet . '/updates';
    $r10oManualIds = [];
    foreach (range(1, 4) as $i) {
        $r10oManualIds[$i] = sprintf('2026020100000%d-%012x', $i, 0xa0 + $i);
        mkdir($r10oRetState . '/snapshots/' . $r10oManualIds[$i], 0700, true);
        file_put_contents($r10oRetState . '/snapshots/' . $r10oManualIds[$i] . '/snapshot.json', json_encode(['id' => $r10oManualIds[$i], 'reason' => 'manual-backup', 'status' => 'prepared', 'created_at' => "2026-02-01T00:00:0{$i}+00:00"]));
    }
    $r10oPruned = (new Updater($r10oRet . '/app', $r10oRetState))->pruneSnapshots(2);
    sort($r10oPruned);
    assert_equals([$r10oManualIds[1], $r10oManualIds[2]], $r10oPruned, 'R10 update: pruneSnapshots keeps only the newest N manual-backup snapshots (they were kept forever)');
    assert_true(str_contains(str_replace("\r\n", "\n", (string)file_get_contents(dirname(TEST_CLI_DIR) . '/cli/update.php')), "\$snapshot = \$updater->backup(\$package);\n        echo \"Snapshot created: {\$snapshot['id']}\\n\";\n        try {\n            \$pruned = \$updater->pruneSnapshots("),
        'R10 update: cli/update.php backup prunes old snapshots after creating one');
} catch (Throwable $e) {
    assert_true(false, 'R10 update snapshots (' . $e->getMessage() . ')');
}

// ---------- Carriers: timestamp by default, delivered on an unshipped order, no invented AWBs ----------
$r10oShipSecret = 'r10o-carrier-secret-0123456789';
putenv('SHIP_PACKETA_WEBHOOK_SECRET=' . $r10oShipSecret);
$r10oShipHook = static function (string $awb, string $status, bool $timestamped = true) use ($r10oShipSecret): array {
    $raw = json_encode(['awb' => $awb, 'status' => $status, 'nonce' => bin2hex(random_bytes(4))]);
    $ts = $timestamped ? (string)time() : '';
    return ShippingService::handleTrackingWebhook('packeta', json_decode($raw, true), hash_hmac('sha256', ($timestamped ? $ts . '.' : '') . $raw, $r10oShipSecret), $raw, $ts);
};
try {
    $r10oBodyOnly = $r10oError(static fn() => $r10oShipHook('R10O-NOPE', 'in_transit', false));
    assert_true(str_contains($r10oBodyOnly, 'timestamp required'), 'R10 carrier webhook: a body-only signature is refused by default (the signed timestamp was optional and off)');
    assert_equals('unknown_awb', $r10oShipHook('R10O-NOPE', 'in_transit')['reason'] ?? null, 'R10 carrier webhook: a timestamped signature is accepted');
    $r10oApiSrc = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php');
    assert_true(str_contains($r10oApiSrc, "http_response_code(\$result['reason'] === 'unknown_awb' ? 404 : 409)"),
        'R10 carrier webhook: an unknown or ambiguous AWB is answered non-2xx so the carrier redelivers (a 200 dropped the event)');

    // "delivered" for an order that was never marked shipped: recorded on the label, no endless 500.
    $r10oShipPdo = Db::get();
    $r10oShipPdo->prepare("INSERT INTO orders (order_number, store_id, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, locale)
        VALUES ('R10O-SHIP', 'default', 'paid', 'paid', 'R10 Buyer', 'r10o-ship@example.test', 'bank_transfer', 1000, 1000, 'eur', 'en')")->execute();
    $r10oShipOrder = (int)$r10oShipPdo->query("SELECT id FROM orders WHERE order_number = 'R10O-SHIP'")->fetchColumn();
    $r10oShipPdo->prepare("INSERT INTO shipping_labels (order_id, store_id, carrier, awb, status, label_data) VALUES (?, 'default', 'packeta', 'R10O-AWB-1', 'created', '')")->execute([$r10oShipOrder]);
    $r10oDelivered = null;
    $r10oDeliveredError = $r10oError(static function () use ($r10oShipHook, &$r10oDelivered): void { $r10oDelivered = $r10oShipHook('R10O-AWB-1', 'delivered'); });
    $r10oLabelNow = (string)$r10oShipPdo->query("SELECT status FROM shipping_labels WHERE awb = 'R10O-AWB-1'")->fetchColumn();
    assert_true($r10oDeliveredError === '' && ($r10oDelivered['handled'] ?? null) === true && ($r10oDelivered['transitioned'] ?? null) === false
        && $r10oLabelNow === 'delivered' && (Orders::byId($r10oShipOrder)['status'] ?? '') === 'paid',
        'R10 carrier webhook: "delivered" for a paid, not shipped order is recorded and acknowledged (it threw, a 500 the carrier retried forever) ' . $r10oDeliveredError);
    assert_equals(1, (int)$r10oShipPdo->query("SELECT COUNT(*) FROM operational_incidents WHERE code = 'shipping.delivered_unshipped_order'")->fetchColumn(),
        'R10 carrier webhook: the operator gets an incident for it');
} catch (Throwable $e) {
    assert_true(false, 'R10 carrier webhook (' . $e->getMessage() . ')');
} finally {
    putenv('SHIP_PACKETA_WEBHOOK_SECRET');
    Db::get()->exec("DELETE FROM shipping_labels WHERE awb = 'R10O-AWB-1'");
    Db::get()->exec("DELETE FROM orders WHERE order_number = 'R10O-SHIP'");
    Db::get()->exec("DELETE FROM operational_incidents WHERE code = 'shipping.delivered_unshipped_order'");
}

// Packeta / GLS without their API: an error, not an invented AWB that looks like a real parcel.
putenv('SHIP_MOCK_LABELS');
$r10oCarriers = ['packeta' => new PacketaShipping(['private_cache_dir' => $r10oRoot . '/packeta']), 'gls' => new GlsShipping()];
foreach ($r10oCarriers as $r10oCode => $r10oCarrier) {
    $r10oLabelError = $r10oError(static fn() => $r10oCarrier->generateLabel(['id' => 4242]));
    $r10oTrackError = $r10oError(static fn() => $r10oCarrier->trackShipment('X4242'));
    assert_true(str_contains($r10oLabelError, 'not available') && str_contains($r10oTrackError, 'not available'),
        "R10 {$r10oCode}: without the API key a label and tracking fail instead of inventing an AWB / transit events");
}
putenv('SHIP_MOCK_LABELS=1');
assert_equals(['PKT0000004242', 'GLS000004242'], [$r10oCarriers['packeta']->generateLabel(['id' => 4242])['awb'], $r10oCarriers['gls']->generateLabel(['id' => 4242])['awb']],
    'R10 carriers: SHIP_MOCK_LABELS=1 still gives deterministic mock labels for development');
putenv('SHIP_MOCK_LABELS');

// ---------- API v1: a database error is not echoed to the client ----------
$r10oApiDb = Db::get();
$r10oApiMysql = Db::driver() === 'mysql';
$r10oApiStore = StoreContext::current()->id();
try {
    $r10oApiClient = ApiAuth::authenticate('Bearer ' . ApiAuth::issueClient('R10 ops', $r10oApiStore, ['inventory:write'])['token']);
    $r10oApiDb->prepare("INSERT INTO products (sku, name, slug, category_id, price, stock, status, store_id) VALUES ('R10O-API', 'R10O API', 'r10o-api', ?, 1000, 0, 'active', ?)")
        ->execute([(int)$r10oApiDb->query('SELECT MIN(id) FROM categories')->fetchColumn(), $r10oApiStore]);
    $r10oApiDb->exec($r10oApiMysql
        ? "CREATE TRIGGER r10o_api_leak BEFORE INSERT ON warehouse_stock FOR EACH ROW BEGIN IF NEW.sku = 'R10O-API' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'r10o_secret_table violates r10o_constraint'; END IF; END"
        : "CREATE TRIGGER r10o_api_leak BEFORE INSERT ON warehouse_stock WHEN NEW.sku = 'R10O-API' BEGIN SELECT RAISE(ABORT, 'r10o_secret_table violates r10o_constraint'); END");
    try {
        $r10oApiResult = ApiV1::dispatch('POST', '/api/v1/inventory/adjustments', [], ['sku' => 'R10O-API', 'delta' => 3], ['Idempotency-Key' => 'r10o-api-leak-0001'], $r10oApiClient);
    } catch (Throwable $e) {
        $r10oApiResult = ApiV1::errorResponse($e);
    }
    $r10oApiBody = json_encode($r10oApiResult['body'] ?? null);
    assert_true(in_array($r10oApiResult['status'] ?? 0, [409, 500], true) && !str_contains((string)$r10oApiBody, 'r10o_secret_table') && !str_contains((string)$r10oApiBody, 'SQLSTATE'),
        'R10 API v1: a database error in a write is answered without the PDOException text (it named SQL, tables and constraints) ' . $r10oApiBody);
} catch (Throwable $e) {
    assert_true(false, 'R10 API v1 (' . $e->getMessage() . ')');
} finally {
    $r10oApiDb->exec('DROP TRIGGER IF EXISTS r10o_api_leak');
    $r10oApiDb->exec("DELETE FROM warehouse_stock WHERE sku = 'R10O-API'");
    $r10oApiDb->exec("DELETE FROM products WHERE sku = 'R10O-API'");
}

// ---------- robots.txt / sitemap / marketing feeds of a store on its own domain ----------
$r10oCfgProp = new ReflectionProperty(Security::class, 'cfg');
$r10oStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r10oCfgBefore = $r10oCfgProp->getValue();
$r10oStoresBefore = $r10oStoresProp->getValue();
$r10oContextBefore = StoreContext::hasCurrent() ? StoreContext::current() : null;
$r10oHostBefore = $_SERVER['HTTP_HOST'] ?? null;
$r10oObLevel = ob_get_level();
try {
    $r10oCfgProp->setValue(null, ['app_url' => 'https://main.example'] + (is_array($r10oCfgBefore) ? $r10oCfgBefore : require dirname(TEST_CLI_DIR) . '/config.php'));
    $r10oDomainStores = Bootstrap::stores();
    $r10oDomainStores['stores']['r10dom'] = array_replace($r10oDomainStores['stores']['default'], ['id' => 'r10dom', 'url_prefix' => '', 'domains' => ['r10-shop.example'],
        'catalog_scope' => 'default', 'provider_scope' => 'default']);
    $r10oStoresProp->setValue(null, $r10oDomainStores);
    $_SERVER['HTTP_HOST'] = 'r10-shop.example';
    StoreContext::reset();
    assert_equals('https://r10-shop.example', Security::currentStoreAppUrl(), 'R10 store domain: the current store base URL is its own domain with the app_url scheme');
    ob_start();
    @require dirname(TEST_CLI_DIR) . '/robots.php'; // @: header() after the suite's output
    $r10oRobots = (string)ob_get_clean();
    assert_true(str_contains($r10oRobots, 'Sitemap: https://r10-shop.example/sitemap.xml') && !str_contains($r10oRobots, 'main.example'),
        'R10 store domain: robots.txt names the store domain sitemap (it pointed at the global app_url) ' . json_encode($r10oRobots));
    foreach (['sitemap.php', 'feed.php'] as $r10oEntry) {
        $r10oEntrySrc = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/' . $r10oEntry);
        assert_true(str_contains($r10oEntrySrc, 'Security::currentStoreAppUrl()') && !str_contains($r10oEntrySrc, 'Security::configuredAppUrl()'),
            "R10 store domain: {$r10oEntry} builds its absolute URLs from the store's domain");
    }
    $r10oFeed = MarketingFeed::google((string)Security::currentStoreAppUrl(), 'en');
    assert_true(str_contains($r10oFeed, '<link>https://r10-shop.example') && !str_contains($r10oFeed, 'main.example'), 'R10 store domain: the Google feed links the store domain');
    $_SERVER['HTTP_HOST'] = 'other.example';
    StoreContext::reset();
    assert_equals('https://main.example', Security::currentStoreAppUrl(), 'R10 store domain: a store without its own domain keeps the app_url');
} catch (Throwable $e) {
    while (ob_get_level() > $r10oObLevel) ob_end_clean();
    assert_true(false, 'R10 store domain (' . get_class($e) . ': ' . $e->getMessage() . ')');
} finally {
    $r10oCfgProp->setValue(null, $r10oCfgBefore);
    $r10oStoresProp->setValue(null, $r10oStoresBefore);
    if ($r10oHostBefore === null) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $r10oHostBefore;
    StoreContext::reset();
    if ($r10oContextBefore !== null) StoreContext::activate($r10oContextBefore);
}
