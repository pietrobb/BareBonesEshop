<?php

// Review round 8 (scaling, 200k products / 500k orders): admin list queries are served by an index matching their
// WHERE + ORDER BY, never by a store-wide scan or a temporary sort.
echo "\n--- Review round 8: scaling ---\n";
[$r8sSavedDb, $r8sSavedDriver] = [Db::get(), Db::driver()];
$r8sTmp = sys_get_temp_dir() . '/bbe-r8scale-' . bin2hex(random_bytes(4));
mkdir($r8sTmp, 0700, true);
$r8sDb = new class('sqlite::memory:') extends PDO {
    /** @var list<string> */
    public array $prepared = [];
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->prepared[] = $query;
        return parent::prepare($query, $options);
    }
};
$r8sDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$r8sDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
try {
    (new Migrator($r8sDb, TEST_CLI_DIR . '/../migrations', $r8sTmp . '/migrate.lock'))->apply(static fn($m) => null);
    Db::init($r8sDb, 'sqlite');
    $r8sStore = $r8sDb->quote((string)StoreContext::current()->id());
    // A two-store shop with a realistic status mix, analysed so the planner sees the real distribution.
    $r8sDb->exec("INSERT INTO orders (order_number, status, payment_status, customer_id, customer_name, customer_email, payment_method, subtotal, total, store_id, created_at)
        WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 20000)
        SELECT 'R8S-' || i, CASE i % 17 WHEN 0 THEN 'pending' WHEN 1 THEN 'cancelled' ELSE 'delivered' END,
            CASE i % 23 WHEN 0 THEN 'pending' WHEN 1 THEN 'authorized' ELSE 'paid' END, (i % 5000) + 1, 'C', 'c' || i || '@example.invalid',
            'cod', 1000, 1000, CASE WHEN i % 10 = 0 THEN 'r8s-other' ELSE {$r8sStore} END, datetime('2024-01-01', '+' || i || ' minutes') FROM n");
    $r8sDb->exec("INSERT INTO customers (email, password_hash, name, status, store_id, created_at)
        WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 5000)
        SELECT 'c' || i || '@example.invalid', 'x', 'C', 'active', CASE WHEN i % 10 = 0 THEN 'r8s-other' ELSE {$r8sStore} END, datetime('2024-01-01', '+' || i || ' minutes') FROM n");
    $r8sDb->exec('ANALYZE');

    $r8sPlan = static function (string $sql, array $params) use ($r8sDb): string {
        $st = $r8sDb->prepare('EXPLAIN QUERY PLAN ' . $sql);
        $st->execute($params);
        return implode(' | ', array_column($st->fetchAll(), 'detail'));
    };
    $r8sCheck = static function (string $label, string $plan, string $index): void {
        assert_true(str_contains($plan, 'USING INDEX ' . $index) || str_contains($plan, 'USING COVERING INDEX ' . $index), "{$label}: uses {$index} (plan: {$plan})");
        assert_true(!str_contains($plan, 'TEMP B-TREE') && !preg_match('/\bSCAN orders\b|\bSCAN customers\b/', $plan), "{$label}: no scan and no temporary sort (plan: {$plan})");
    };

    // Admin order list (src/Entrypoints/Admin/Orders.php pageOrders): filtered by status or payment status, newest first.
    $r8sOrders = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin/Orders.php');
    assert_true(str_contains($r8sOrders, 'SELECT * FROM orders WHERE {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset'), 'R8 scaling: the admin order list query has the shape measured below');
    $r8sCheck('R8 scaling: order list by status', $r8sPlan("SELECT * FROM orders WHERE store_id = ? AND status = ? ORDER BY created_at DESC LIMIT 25 OFFSET 0", [StoreContext::current()->id(), 'pending']), 'idx_orders_store_status_created');
    $r8sCheck('R8 scaling: order count by status', $r8sPlan("SELECT COUNT(*) FROM orders WHERE store_id = ? AND status = ?", [StoreContext::current()->id(), 'pending']), 'idx_orders_store_status_created');
    $r8sCheck('R8 scaling: order list by payment status', $r8sPlan("SELECT * FROM orders WHERE store_id = ? AND payment_status = ? ORDER BY created_at DESC LIMIT 25 OFFSET 0", [StoreContext::current()->id(), 'authorized']), 'idx_orders_store_payment_created');
    // Sales dashboard open-payment counter, taken verbatim from the entrypoint.
    assert_true(str_contains($r8sOrders, "SELECT COUNT(*) FROM orders WHERE store_id = :store AND payment_status IN ('pending', 'authorized')"), 'R8 scaling: the dashboard open-payment counter has the shape measured below');
    $r8sCheck('R8 scaling: dashboard open-payment count', $r8sPlan("SELECT COUNT(*) FROM orders WHERE store_id = :store AND payment_status IN ('pending', 'authorized')", [':store' => StoreContext::current()->id()]), 'idx_orders_store_payment_created');

    // Admin customer list: the statements Customers::adminList/adminCount really prepare.
    $r8sDb->prepared = [];
    $r8sList = Customers::adminList('', 25, 0);
    Customers::adminCount('');
    assert_equals(25, count($r8sList), 'R8 scaling: the admin customer list returns a page');
    assert_true((int)$r8sList[0]['id'] > (int)$r8sList[24]['id'], 'R8 scaling: the admin customer list is newest first');
    $r8sListSql = array_values(array_filter($r8sDb->prepared, static fn(string $s): bool => str_contains($s, 'FROM customers') && str_contains($s, 'ORDER BY id DESC')));
    assert_equals(1, count($r8sListSql), 'R8 scaling: Customers::adminList prepares one list statement');
    $r8sCheck('R8 scaling: customer list', $r8sPlan((string)($r8sListSql[0] ?? ''), [StoreContext::current()->id(), 25, 0]), 'idx_customers_store_id');
} finally {
    Db::init($r8sSavedDb, $r8sSavedDriver);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r8sTmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $r8sF) {
        $r8sF->isDir() ? @rmdir($r8sF->getPathname()) : @unlink($r8sF->getPathname());
    }
    @rmdir($r8sTmp);
}

// SQLite admin login under concurrent writes: a SELECT left open after one fetch() keeps its WAL snapshot, so the
// following UPDATE fails with SQLITE_BUSY (snapshot) as soon as any other request committed in between — the
// busy timeout cannot help. Here a second connection commits right before every admin write; login, the session
// check and a returning device must still succeed.
[$r8sSavedDb, $r8sSavedDriver] = [Db::get(), Db::driver()];
$r8sTmp = sys_get_temp_dir() . '/bbe-r8login-' . bin2hex(random_bytes(4));
mkdir($r8sTmp, 0700, true);
$r8sFile = $r8sTmp . '/shop.sqlite';
$r8sOther = new PDO('sqlite:' . $r8sFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r8sOther->exec('PRAGMA journal_mode = WAL');
$r8sOther->exec('CREATE TABLE r8s_noise (x INTEGER)');
$r8sLogin = new class('sqlite:' . $r8sFile) extends PDO {
    public ?PDO $other = null;
    public int $concurrentWrites = 0;
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if ($this->other !== null && preg_match('/^\s*(UPDATE|INSERT INTO)\s+admin_(accounts|sessions|devices)\b/i', $query)) {
            $this->other->exec('INSERT INTO r8s_noise VALUES (1)');
            $this->concurrentWrites++;
        }
        return parent::prepare($query, $options);
    }
};
$r8sLogin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$r8sLogin->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$r8sLogin->exec('PRAGMA busy_timeout = 2000');
try {
    (new Migrator($r8sLogin, TEST_CLI_DIR . '/../migrations', $r8sTmp . '/migrate.lock'))->apply(static fn($m) => null);
    Db::init($r8sLogin, 'sqlite');
    AdminIdentity::create('r8scale', 'R8-Scale-Admin-Passphrase-2026!', 'owner', false);
    $r8sLogin->other = $r8sOther;
    $r8sTry = static function (callable $fn): array {
        try { return [$fn(), null]; } catch (PDOException $e) { return [null, $e->getMessage()]; }
    };
    [$r8sFirst, $r8sErr1] = $r8sTry(static fn() => AdminIdentity::authenticate('r8scale', 'R8-Scale-Admin-Passphrase-2026!', '', '203.0.113.8', 'R8 browser'));
    assert_true(is_array($r8sFirst) && $r8sErr1 === null, 'R8 SQLite: admin login succeeds while other requests write (error: ' . ($r8sErr1 ?? 'none') . ')');
    [$r8sSession, $r8sErr2] = $r8sTry(static fn() => is_array($r8sFirst) ? AdminIdentity::session((string)$r8sFirst['token']) : null);
    assert_true(is_array($r8sSession) && $r8sErr2 === null, 'R8 SQLite: the admin session check succeeds while other requests write (error: ' . ($r8sErr2 ?? 'none') . ')');
    [$r8sAgain, $r8sErr3] = $r8sTry(static fn() => AdminIdentity::authenticate('r8scale', 'R8-Scale-Admin-Passphrase-2026!', '', '203.0.113.8', 'R8 browser'));
    assert_true(is_array($r8sAgain) && $r8sErr3 === null && $r8sFirst !== null && $r8sAgain['device_id'] === $r8sFirst['device_id'], 'R8 SQLite: a returning device logs in again while other requests write (error: ' . ($r8sErr3 ?? 'none') . ')');
    assert_true($r8sLogin->concurrentWrites >= 5, 'R8 SQLite: the concurrent writer really committed before each admin write');
} finally {
    Db::init($r8sSavedDb, $r8sSavedDriver);
    $r8sLogin = $r8sOther = null;
    gc_collect_cycles();
    foreach (glob($r8sTmp . '/*') ?: [] as $r8sF) @unlink($r8sF);
    @rmdir($r8sTmp);
}
