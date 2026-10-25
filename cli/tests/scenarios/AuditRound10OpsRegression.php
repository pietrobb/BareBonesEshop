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
