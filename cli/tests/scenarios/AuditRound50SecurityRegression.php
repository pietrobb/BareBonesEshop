<?php

// Review round 50 (security, low):
// - an e-book download limit was bypassed with "Range: bytes=1-": a counted download that had already served the whole
//   file could be "resumed" for free five times (limit 2 → 12 near-complete copies); a resume may only continue a
//   download that has not served the whole file yet, a little below where it stopped;
// - the MFA lockout after 5 wrong codes was checked before the code and written afterwards from a stale read, so
//   concurrent attempts (from several IPs) were all checked and the slowest one erased the lock.
echo "\n--- Audit round 50: security lows (download Range limit, atomic MFA lockout) ---\n";

$r50xRoot = test_fixture_path('r50-security');
mkdir($r50xRoot);
$r50xSavedDb = Db::get();
$r50xSavedDriver = Db::driver();
$r50xDb = new class('sqlite:' . $r50xRoot . '/shop.sqlite') extends PDO {
    /** @var (callable(): void)|null runs once, inside the first check of an MFA code (a concurrent request) */
    public $duringCheck = null;
    public int $checks = 0;
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (preg_match('/^\s*UPDATE\s+admin_recovery_codes\b/i', $query)) {
            $this->checks++;
            if ($this->duringCheck !== null) {
                $run = $this->duringCheck;
                $this->duringCheck = null;
                $run();
            }
        }
        return parent::prepare($query, $options);
    }
};
$r50xDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$r50xDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
putenv('BBE_ADMIN_IDENTITY_KEY=' . base64_encode(str_repeat('R', 32)));
try {
    (new Migrator($r50xDb, TEST_CLI_DIR . '/../migrations', $r50xRoot . '/migrate.lock'))->apply(static fn($m) => null);
    Db::init($r50xDb, 'sqlite');

    // ── Download limit under Range ───────────────────────────────────────────────────────────────
    $r50xDb->exec("INSERT INTO orders (id, order_number, customer_name, customer_email, payment_method, subtotal, total, status, payment_status)
        VALUES (5001, 'R50X-1', 'R50X', 'r50x@example.com', 'card', 1000, 1000, 'completed', 'paid')");
    $r50xDb->exec("INSERT INTO digital_assets (id, sku, storage_path) VALUES (5001, 'R50X-EB', 'default/r50x.epub')");
    $r50xSize = 1000;
    $r50xEnt = static function (int $limit) use ($r50xDb): int {
        $r50xDb->prepare("INSERT INTO digital_entitlements (order_id, order_item_id, asset_id, token_hash, status, download_limit) VALUES (5001, 0, 5001, ?, 'active', ?)")
            ->execute([bin2hex(random_bytes(32)), $limit]);
        return (int)$r50xDb->lastInsertId();
    };
    $r50xRow = static fn(int $id): array => $r50xDb->query("SELECT * FROM digital_entitlements WHERE id = {$id}")->fetch() ?: [];
    $r50xNow = time();

    // A complete counted download: no free "resume" may follow it.
    $r50xFull = $r50xEnt(1);
    DigitalDelivery::endTransfer($r50xFull, DigitalDelivery::openTransfer($r50xRow($r50xFull), 0, $r50xSize, $r50xNow), $r50xSize);
    $r50xCopies = 0;
    for ($r50xTry = 0; $r50xTry < 8; $r50xTry++) if (DigitalDelivery::authorizeTransfer($r50xRow($r50xFull), 1, $r50xSize, $r50xNow + 10)) $r50xCopies++;
    assert_equals([1, 0], [(int)$r50xRow($r50xFull)['download_count'], $r50xCopies],
        'R50 download: after a complete download (limit 1) "Range: bytes=1-" gets no further copy (was 5 free near-complete copies)');

    // An interrupted download: a resume continues near where it stopped, not from the start.
    $r50xCut = $r50xEnt(1);
    DigitalDelivery::endTransfer($r50xCut, DigitalDelivery::openTransfer($r50xRow($r50xCut), 0, $r50xSize, $r50xNow), $r50xSize - 1);
    assert_true(!DigitalDelivery::authorizeTransfer($r50xRow($r50xCut), 1, $r50xSize, $r50xNow + 10),
        'R50 download: a download cut one byte before the end cannot be "resumed" from byte 1 (a second near-complete copy)');
    $r50xResume = DigitalDelivery::openTransfer($r50xRow($r50xCut), $r50xSize - 101, $r50xSize, $r50xNow + 10);
    assert_true($r50xResume !== null, 'R50 download: a resume a little below the cut (data in flight) is still free');
    DigitalDelivery::endTransfer($r50xCut, $r50xResume, $r50xSize);
    assert_true(!DigitalDelivery::authorizeTransfer($r50xRow($r50xCut), $r50xSize - 101, $r50xSize, $r50xNow + 10)
        && (int)$r50xRow($r50xCut)['download_count'] === 1,
        'R50 download: once the resumed download served the whole file, a further range is a new download (limit spent, refused)');

    // Capability review (run 617): "bytes=0-0" (counted, served 1 byte), then six concurrent "bytes=1-" before any of
    // them records progress: one continues the download, the others are no free copies (limit spent, refused).
    $r50xPar = $r50xEnt(1);
    DigitalDelivery::endTransfer($r50xPar, DigitalDelivery::openTransfer($r50xRow($r50xPar), 0, $r50xSize, $r50xNow), 1);
    $r50xResumes = [];
    for ($r50xTry = 0; $r50xTry < 6; $r50xTry++) {
        $r50xResume = DigitalDelivery::openTransfer($r50xRow($r50xPar), 1, $r50xSize, $r50xNow + 5);
        if ($r50xResume !== null) $r50xResumes[] = $r50xResume;
    }
    assert_equals([1, 1], [(int)$r50xRow($r50xPar)['download_count'], count($r50xResumes)],
        'R50 download: concurrent resumes from one recorded offset — only one continues the download (was 5 free near-complete copies)');
    DigitalDelivery::endTransfer($r50xPar, $r50xResumes[0], 400);
    assert_true(DigitalDelivery::authorizeTransfer($r50xRow($r50xPar), 400, $r50xSize, $r50xNow + 10),
        'R50 download: once the resume stopped, a dropped client resumes again where it stopped');

    // Capability review (run 620): a 128 MiB file, a counted "bytes=0-" that is still streaming records 8 MiB, then
    // 16 MiB; a parallel "bytes=1-" each time was a free second near-complete copy (the slack reached byte 1).
    $r50xRun = $r50xEnt(1);
    $r50xRunSize = 128 * 1024 * 1024;
    $r50xCounted = DigitalDelivery::beginTransfer($r50xRow($r50xRun), 0, $r50xRunSize, $r50xNow);
    DigitalDelivery::recordProgress($r50xRun, (int)$r50xCounted, 8 * 1024 * 1024);
    $r50xCopies = DigitalDelivery::authorizeTransfer($r50xRow($r50xRun), 1, $r50xRunSize, $r50xNow + 5) ? 1 : 0;
    DigitalDelivery::recordProgress($r50xRun, (int)$r50xCounted, 16 * 1024 * 1024);
    $r50xCopies += DigitalDelivery::authorizeTransfer($r50xRow($r50xRun), 1, $r50xRunSize, $r50xNow + 10) ? 1 : 0;
    assert_equals([1, 0], [(int)$r50xRow($r50xRun)['download_count'], $r50xCopies],
        'R50 download: while the counted download still streams, "Range: bytes=1-" is no free resume (was 2 free near-complete copies)');
    // The same throttled download, now through openTransfer(): once the client drops (the transfer ends), it resumes.
    $r50xRun = $r50xEnt(1);
    $r50xTransfer = DigitalDelivery::openTransfer($r50xRow($r50xRun), 0, $r50xRunSize, $r50xNow);
    DigitalDelivery::recordProgress($r50xRun, (int)$r50xTransfer['counted_at'], 8 * 1024 * 1024);
    $r50xWhileRunning = DigitalDelivery::authorizeTransfer($r50xRow($r50xRun), 8 * 1024 * 1024, $r50xRunSize, $r50xNow + 5);
    DigitalDelivery::endTransfer($r50xRun, $r50xTransfer, 16 * 1024 * 1024);
    $r50xResume = DigitalDelivery::openTransfer($r50xRow($r50xRun), 15 * 1024 * 1024, $r50xRunSize, $r50xNow + 10);
    $r50xBeside = DigitalDelivery::authorizeTransfer($r50xRow($r50xRun), 15 * 1024 * 1024, $r50xRunSize, $r50xNow + 10);
    assert_equals([false, true, false, 1], [$r50xWhileRunning, $r50xResume !== null, $r50xBeside, (int)$r50xRow($r50xRun)['download_count']],
        'R50 download: no free resume beside a running transfer; after it stops one resume continues it, and none beside that resume');

    // A large file keeps the 16 MiB in-flight allowance (R18).
    $r50xBig = $r50xEnt(1);
    $r50xBigSize = 256 * 1024 * 1024;
    DigitalDelivery::endTransfer($r50xBig, DigitalDelivery::openTransfer($r50xRow($r50xBig), 0, $r50xBigSize, $r50xNow), 64 * 1024 * 1024);
    assert_true(!DigitalDelivery::authorizeTransfer($r50xRow($r50xBig), 64 * 1024 * 1024 - 17 * 1024 * 1024, $r50xBigSize, $r50xNow + 10)
        && DigitalDelivery::authorizeTransfer($r50xRow($r50xBig), 64 * 1024 * 1024 - 15 * 1024 * 1024, $r50xBigSize, $r50xNow + 10),
        'R50 download: a large file still resumes up to 16 MiB below the served bytes, not further');

    // ── MFA lockout under concurrent attempts (SQLite here, native MySQL below) ─────────────────
    $r50xMfa = static function (PDO $db, string $driver): void {
        $label = "R50 MFA {$driver}";
        $admin = AdminIdentity::create('r50x-owner', 'R50X-owner-passphrase-2026', 'owner', true);
        $secret = AdminIdentity::enrollTotp($admin)['secret'];
        $wrong = Totp::code($secret, time() + 7200); // a valid-looking code far outside the ±1 step window
        $try = static fn(string $ip): ?array => AdminIdentity::authenticate('r50x-owner', 'R50X-owner-passphrase-2026', $wrong, $ip, 'R50X attacker');
        $db->checks = 0;
        // While the first request checks its code, nine others (other IPs) arrive and run to the end.
        $db->duringCheck = static function () use ($try): void {
            for ($i = 1; $i <= 9; $i++) $try('198.51.100.' . $i);
        };
        $before = time();
        with_expected_structured_log('admin.login_failed', static fn() => $try('203.0.113.50'));
        $account = $db->query("SELECT failed_attempts, locked_until FROM admin_accounts WHERE id = {$admin}")->fetch();
        $lockSeconds = $account['locked_until'] === null ? null : strtotime((string)$account['locked_until'] . ' UTC') - $before;
        assert_true($db->checks <= 5, "{$label}: ten concurrent wrong codes — at most 5 are checked before the lock (checked: {$db->checks})");
        assert_true($account['locked_until'] !== null && (int)$account['failed_attempts'] === 5,
            "{$label}: the slowest of the concurrent attempts does not erase the lock (" . json_encode($account) . ')');
        assert_true($lockSeconds !== null && $lockSeconds >= 55 && $lockSeconds <= 65, "{$label}: the 5th attempt locks for 1 minute, as before (got {$lockSeconds} s)");
        $db->checks = 0;
        with_expected_structured_log('admin.login_failed', static function () use ($try): void {
            $try('203.0.113.51');
            Logger::warning('admin.login_failed', ['r50x' => true]); // the refused attempt itself may log nothing
        });
        assert_equals(0, $db->checks, "{$label}: while locked, a further attempt is refused before its code is checked");
        $db->exec("UPDATE admin_accounts SET locked_until = '2000-01-01 00:00:00' WHERE id = {$admin}");
        $before = time();
        with_expected_structured_log('admin.login_failed', static fn() => $try('203.0.113.53'));
        $again = $db->query("SELECT failed_attempts, locked_until FROM admin_accounts WHERE id = {$admin}")->fetch();
        $lockSeconds = strtotime((string)$again['locked_until'] . ' UTC') - $before;
        assert_true((int)$again['failed_attempts'] === 6 && $lockSeconds >= 115 && $lockSeconds <= 125,
            "{$label}: after the lock expires the next wrong code locks for 2 minutes (" . json_encode($again) . ')');
        $db->exec("UPDATE admin_accounts SET locked_until = NULL, failed_attempts = 0 WHERE id = {$admin}");
        $login = AdminIdentity::authenticate('r50x-owner', 'R50X-owner-passphrase-2026', Totp::code($secret), '203.0.113.52', 'R50X owner');
        $after = $db->query("SELECT failed_attempts, locked_until FROM admin_accounts WHERE id = {$admin}")->fetch();
        assert_true(is_array($login) && (int)$after['failed_attempts'] === 0 && $after['locked_until'] === null,
            "{$label}: the right code signs in and clears the attempts");
    };
    $r50xMfa($r50xDb, 'sqlite');

    if (getenv('TEST_MYSQL_DSN')) {
        $r50xBase = (string)preg_replace('/;?dbname=[^;]*/', '', (string)getenv('TEST_MYSQL_DSN'));
        $r50xUser = getenv('TEST_MYSQL_USER') ?: null;
        $r50xPass = getenv('TEST_MYSQL_PASS') ?: null;
        $r50xName = 'bbe_r50x_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $r50xServer = new PDO($r50xBase, $r50xUser, $r50xPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $r50xServer->exec("CREATE DATABASE `{$r50xName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            $r50xClass = get_class($r50xDb);
            $r50xMy = new $r50xClass($r50xBase . ';dbname=' . $r50xName . ';charset=utf8mb4', $r50xUser, $r50xPass);
            $r50xMy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $r50xMy->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            (new Migrator($r50xMy, TEST_CLI_DIR . '/../migrations', $r50xRoot . '/migrate-mysql.lock'))->apply(static fn($m) => null);
            Db::init($r50xMy, 'mysql');
            $r50xMfa($r50xMy, 'mysql');
        } finally {
            Db::init($r50xDb, 'sqlite');
            $r50xMy = null;
            $r50xServer->exec("DROP DATABASE IF EXISTS `{$r50xName}`");
        }
    } else {
        print "  [SKIP] R50 MFA native MySQL (set TEST_MYSQL_DSN)\n";
    }
} catch (Throwable $e) {
    assert_true(false, 'R50 security lows: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    putenv('BBE_ADMIN_IDENTITY_KEY');
    Db::init($r50xSavedDb, $r50xSavedDriver);
    $r50xDb = null;
    gc_collect_cycles();
    foreach (glob($r50xRoot . '/*') ?: [] as $r50xFile) @unlink($r50xFile);
    @rmdir($r50xRoot);
}
