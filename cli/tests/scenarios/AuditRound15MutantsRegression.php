<?php

// Review round 15 (2026-11-04) "tests": operator mutants that survived the suite. MailService: the rate-limit retry of
// an order mail (template missing from the payload, retry_after 0 or missing) and the attachment defaults (file
// name, content type); Security: a URL parse_url() cannot parse is never a form-action redirect target.
echo "\n--- Audit round 15: surviving mutants ---\n";

$r15mFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R15 mutants {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};

// MailService::deferRateLimited: the delay comes from retry_after (at least 60 s, 3660 s when the result has none).
$r15mQueue = new class implements DelayedQueueBackend {
    public array $pushed = [];
    public function name(): string { return 'r15m'; }
    public function push(string $type, array $payload, int $priority = 5): string { return $this->pushAt($type, $payload, time(), $priority); }
    public function pushAt(string $type, array $payload, int $availableAt, int $priority = 5): string {
        $this->pushed[] = ['type' => $type, 'payload' => $payload, 'delay' => $availableAt - time()];
        return 'r15m-' . count($this->pushed);
    }
    public function pull(int $limit = 10): array { return []; }
    public function markProcessing(array $job): string { return ''; }
    public function complete(string $claim): void {}
    public function fail(string $claim, array $job, string $error): void {}
    public function extendLease(string $claim): bool { return true; }
    public function stats(): array { return []; }
    public function failures(int $limit = 20): array { return []; }
};
try {
    JobQueue::useBackend($r15mQueue);
    $r15mMail = ['template' => 'order_confirmation', 'order_id' => 881500, 'to' => 'r15m@example.test'];
    assert_true(MailService::deferRateLimited($r15mMail, ['status' => 'rate_limited']), 'R15 mutants: a rate-limited order mail without retry_after is queued again');
    assert_true(MailService::deferRateLimited($r15mMail, ['status' => 'rate_limited', 'retry_after' => 0]), 'R15 mutants: ... and one with retry_after 0');
    assert_true(MailService::deferRateLimited($r15mMail, ['status' => 'rate_limited', 'retry_after' => 4000]), 'R15 mutants: ... and one with retry_after 4000');
    $r15mDelays = array_map(static fn(array $job): int => $job['delay'], $r15mQueue->pushed);
    assert_true(count($r15mDelays) === 3 && abs($r15mDelays[0] - 3660) <= 2 && abs($r15mDelays[1] - 60) <= 2 && abs($r15mDelays[2] - 4000) <= 2,
        'R15 mutants: the retry is due after retry_after, at least 60 s, and 3660 s when the result names none (got ' . json_encode($r15mDelays) . ')');
    assert_equals(['email_send', 1], [$r15mQueue->pushed[0]['type'] ?? '', $r15mQueue->pushed[0]['payload']['_rate_limit_retries'] ?? null],
        'R15 mutants: the retry is the same email_send job with the retry counted');
    assert_true(!MailService::deferRateLimited(['order_id' => 881500, '_rate_limit_retries' => MailService::RATE_LIMIT_MAX_RETRIES], ['status' => 'rate_limited']),
        'R15 mutants: a spent retry budget drops the mail, even when the payload names no template');
    assert_equals(3, count($r15mQueue->pushed), 'R15 mutants: ... and queues nothing');
} catch (Throwable $e) {
    $r15mFail('rate-limit retry', $e);
} finally {
    JobQueue::resetBackend();
}

// MailService::normalizeAttachments: a path without file name / content type gets the base name and the type of its
// extension; inline data without them gets attachment.bin and application/octet-stream.
try {
    $r15mDir = test_fixture_path('r15-mutants');
    if (!is_dir($r15mDir)) mkdir($r15mDir, 0700, true);
    file_put_contents($r15mDir . '/r15m-invoice.pdf', '%PDF-r15m');
    $r15mNormalize = new ReflectionMethod(MailService::class, 'normalizeAttachments');
    $r15mAttachments = $r15mNormalize->invoke(null, [$r15mDir . '/r15m-invoice.pdf', ['path' => $r15mDir . '/r15m-invoice.pdf'], ['data' => 'raw']]);
    assert_equals([
        ['filename' => 'r15m-invoice.pdf', 'content_type' => 'application/pdf', 'data' => '%PDF-r15m'],
        ['filename' => 'r15m-invoice.pdf', 'content_type' => 'application/pdf', 'data' => '%PDF-r15m'],
        ['filename' => 'attachment.bin', 'content_type' => 'application/octet-stream', 'data' => 'raw'],
    ], $r15mAttachments, 'R15 mutants: attachments without file name or content type get the defaults');
    $r15mNamed = $r15mNormalize->invoke(null, [['path' => $r15mDir . '/r15m-invoice.pdf', 'filename' => 'Faktura.pdf', 'content_type' => 'application/x-r15m'],
        ['data' => 'raw', 'filename' => 'note.txt', 'content_type' => 'text/plain']]);
    assert_equals([['Faktura.pdf', 'application/x-r15m'], ['note.txt', 'text/plain']], array_map(static fn(array $a): array => [$a['filename'], $a['content_type']], $r15mNamed),
        'R15 mutants: a given file name and content type are kept');
} catch (Throwable $e) {
    $r15mFail('attachments', $e);
}

// Security::formActionAllows: parse_url() gives up on "http:///evil.example" — that is not a same-origin redirect.
$r15mHost = $_SERVER['HTTP_HOST'] ?? null;
try {
    $_SERVER['HTTP_HOST'] = 'shop.example';
    assert_true(parse_url('http:///evil.example/pay') === false && !Security::formActionAllows('http:///evil.example/pay'),
        'R15 mutants: a URL parse_url() cannot parse does not pass form-action');
    assert_true(!Security::formActionAllows('javascript:alert(1)') && !Security::formActionAllows('data:text/html,r15m'),
        'R15 mutants: a host-less URL with a scheme (javascript:, data:) does not pass form-action as a relative one');
    unset($_SERVER['HTTP_HOST']);
    assert_true(!Security::formActionAllows('https://r15m.example/pay'), 'R15 mutants: without a Host header an absolute URL does not pass form-action (and raises no warning)');
} catch (Throwable $e) {
    $r15mFail('form-action', $e);
} finally {
    if ($r15mHost === null) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $r15mHost;
}

// Security::formTrapValidate: a token never issued is refused even with no age limits at all.
try {
    assert_true(!Security::formTrapValidate('r15m', 'r15m-never-issued', '', 0, PHP_INT_MAX), 'R15 mutants: a form-trap token that was never issued is refused');
} catch (Throwable $e) {
    $r15mFail('form trap', $e);
}

// Security::clientIp: a config without trusted_proxies (an old config.php) is "no proxy", not a PHP warning.
$r15mCfg = new ReflectionProperty(Security::class, 'cfg');
Security::clientIp();
$r15mCfgBase = $r15mCfg->getValue();
$r15mRemote = $_SERVER['REMOTE_ADDR'] ?? null;
try {
    $r15mCfgNoProxies = $r15mCfgBase;
    unset($r15mCfgNoProxies['trusted_proxies']);
    $r15mCfg->setValue(null, $r15mCfgNoProxies + ['r15m' => true]);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.15';
    assert_equals('203.0.113.15', Security::clientIp(), 'R15 mutants: without trusted_proxies in the config the client IP is REMOTE_ADDR');
} catch (Throwable $e) {
    $r15mFail('client IP', $e);
} finally {
    $r15mCfg->setValue(null, $r15mCfgBase);
    if ($r15mRemote === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $r15mRemote;
}

// ... without REMOTE_ADDR (CLI, worker) it is 127.0.0.1, and a trusted proxy that sends no X-Forwarded-For is the client.
$r15mForwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
try {
    $r15mCfg->setValue(null, ['trusted_proxies' => ['203.0.113.1']] + $r15mCfgBase);
    unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    assert_equals('127.0.0.1', Security::clientIp(), 'R15 mutants: without REMOTE_ADDR the client IP is 127.0.0.1 (and raises no warning)');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
    assert_equals('203.0.113.1', Security::clientIp(), 'R15 mutants: a trusted proxy without X-Forwarded-For is the client (and raises no warning)');
} catch (Throwable $e) {
    $r15mFail('client IP without headers', $e);
} finally {
    $r15mCfg->setValue(null, $r15mCfgBase);
    if ($r15mRemote === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $r15mRemote;
    if ($r15mForwarded !== null) $_SERVER['HTTP_X_FORWARDED_FOR'] = $r15mForwarded;
}

// Security::formTrapValidate: both age limits are inclusive — a 4 s old token passes min age 4, a 10 s old one ttl 10.
$r15mTrapKey = 'form_trap_tokens_' . md5('r15m-age');
try {
    $r15mNow = microtime(true);
    if ($r15mNow - floor($r15mNow) > 0.5) usleep((int)((ceil($r15mNow) - $r15mNow) * 1000000) + 2000);
    $_SESSION[$r15mTrapKey] = ['r15m-min' => time() - 4, 'r15m-ttl' => time() - 10];
    assert_equals([true, true], [Security::formTrapValidate('r15m-age', 'r15m-min', '', 4, 3600), Security::formTrapValidate('r15m-age', 'r15m-ttl', '', 0, 10)],
        'R15 mutants: a form-trap token exactly at the minimum age or exactly at its ttl is accepted');
} catch (Throwable $e) {
    $r15mFail('form trap ages', $e);
} finally {
    unset($_SESSION[$r15mTrapKey]);
}

// Security::adminCanAssumeRole / orderStatusPermission: an owner may assume any role; anyone else never owner and
// only a role whose every permission they hold; cancelling an order takes orders.edit even with orders.status.
$r15mAdmin = array_intersect_key($_SESSION ?? [], ['admin_role' => 1, 'admin_identity_id' => 1, 'admin_store_permissions' => 1]);
try {
    Security::setRolesForTesting(['roles' => [
        'r15m-clerk' => ['permissions' => ['orders.status', 'orders.view']],
        'r15m-viewer' => ['permissions' => ['orders.view']],
        'r15m-wide' => ['permissions' => ['orders.view', 'products.edit']],
        'r15m-products' => ['permissions' => ['products.*']],
    ]]);
    unset($_SESSION['admin_identity_id']);
    $_SESSION['admin_role'] = 'owner';
    assert_true(Security::adminCanAssumeRole('owner'), 'R15 mutants: an owner may assume the owner role');
    $_SESSION['admin_role'] = 'r15m-clerk';
    assert_equals([false, true, false, true], array_map([Security::class, 'adminCanAssumeRole'], ['owner', 'r15m-viewer', 'r15m-wide', 'r15m-clerk']),
        'R15 mutants: a clerk may not assume owner nor a role with a permission it lacks, but a role within its own');
    assert_equals(['orders.status', 'orders.edit'], [Security::orderStatusPermission('shipped'), Security::orderStatusPermission('cancelled')],
        'R15 mutants: with orders.status a workflow move needs orders.status, cancelling still orders.edit');
    $_SESSION['admin_role'] = 'r15m-products';
    assert_equals([true, true, false, false], array_map([Security::class, 'adminCan'], ['products.edit', 'products', 'orders.view', 'productsx.edit']),
        'R15 mutants: a products.* wildcard grants products and products.<x>, nothing else');
    $_SESSION['admin_identity_id'] = 881501;
    unset($_SESSION['admin_store_permissions']);
    assert_true(!Security::adminCan('products.edit'), 'R15 mutants: an identity session without store grants may do nothing (and raises no warning)');
} catch (Throwable $e) {
    $r15mFail('admin roles', $e);
} finally {
    Security::setRolesForTesting(null);
    unset($_SESSION['admin_role'], $_SESSION['admin_identity_id'], $_SESSION['admin_store_permissions']);
    foreach ($r15mAdmin as $r15mKey => $r15mValue) $_SESSION[$r15mKey] = $r15mValue;
}

// Warehouse: a statement class lets a test act as a concurrent request right before a given query runs.
final class R15MutantsStatement extends PDOStatement {
    public static ?Closure $hook = null;
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        if (self::$hook !== null) (self::$hook)($this->queryString);
        return parent::execute($params);
    }
}
$r15mWithHook = static function (Closure $hook, Closure $run): mixed {
    $pdo = Db::get();
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [R15MutantsStatement::class]);
    R15MutantsStatement::$hook = $hook;
    try {
        return $run();
    } finally {
        R15MutantsStatement::$hook = null;
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
    }
};
// Earlier scenarios drop `feeds` in the shared suite DB; Warehouse::delete() cascades into it.
try { Db::get()->query('SELECT 1 FROM feeds LIMIT 1'); } catch (PDOException) { Db::get()->exec('CREATE TABLE feeds (id INTEGER PRIMARY KEY, store_id VARCHAR(40), warehouse_id INTEGER)'); }
$r15mDefault = (int)(Warehouse::defaultWarehouse()['id'] ?? 0);
$r15mOwnDefault = $r15mDefault === 0;
if ($r15mOwnDefault) $r15mDefault = Warehouse::save(['code' => 'r15m-main', 'name' => 'R15M main', 'priority' => 10, 'is_default' => 1]);
$r15mReservedRow = static function (int $warehouseId, string $sku): int {
    $stmt = Db::get()->prepare('SELECT reserved FROM warehouse_stock WHERE warehouse_id = :w AND sku = :sku');
    $stmt->execute([':w' => $warehouseId, ':sku' => $sku]);
    return (int)$stmt->fetchColumn();
};
$r15mSteal = static function (int $warehouseId, string $sku, int $units): void {
    Db::get()->exec('UPDATE warehouse_stock SET reserved = reserved + ' . $units . ' WHERE warehouse_id = ' . $warehouseId . ' AND sku = ' . Db::get()->quote($sku));
};
$r15mSplit = static function (array $allocations): array {
    $reserved = $short = $zero = 0;
    foreach ($allocations as $allocation) {
        if ((int)$allocation['qty'] === 0) $zero++;
        if ($allocation['warehouse_id'] === null) $short += (int)$allocation['qty']; else $reserved += (int)$allocation['qty'];
    }
    return ['reserved' => $reserved, 'short' => $short, 'zero' => $zero];
};
$r15mReserveSql = 'SET reserved = reserved + :take';

// Warehouse::reserve: a concurrent checkout reserves 4 of 5 between the candidate read and the guarded UPDATE.
// The guard fails, the retry takes the 1 unit left, the rest is a backorder — never 3 "reserved" units the row lacks.
try {
    Warehouse::setStock($r15mDefault, 'R15M-RACE1', 5, 'count');
    $r15mStolen = false;
    $r15mAlloc = $r15mWithHook(static function (string $sql) use (&$r15mStolen, $r15mSteal, $r15mDefault, $r15mReserveSql): void {
        if (!$r15mStolen && str_contains($sql, $r15mReserveSql)) { $r15mStolen = true; $r15mSteal($r15mDefault, 'R15M-RACE1', 4); }
    }, static fn(): array => Warehouse::reserve('R15M-RACE1', 3, Inventory::BACKORDER));
    assert_equals(['reserved' => 1, 'short' => 2, 'zero' => 0, 'row' => 5], $r15mSplit($r15mAlloc) + ['row' => $r15mReservedRow($r15mDefault, 'R15M-RACE1')],
        'R15 mutants: a reservation that lost the race is not booked; the retry takes only what is left');
    Warehouse::release('R15M-RACE1', $r15mAlloc);
    $r15mSteal($r15mDefault, 'R15M-RACE1', -4);
} catch (Throwable $e) {
    $r15mFail('reserve race', $e);
}

// ... and when the concurrent checkout took everything, no 0-unit allocation is booked: the order is all backorder.
try {
    Warehouse::setStock($r15mDefault, 'R15M-RACE2', 5, 'count');
    $r15mStolen = false;
    $r15mAlloc = $r15mWithHook(static function (string $sql) use (&$r15mStolen, $r15mSteal, $r15mDefault, $r15mReserveSql): void {
        if (!$r15mStolen && str_contains($sql, $r15mReserveSql)) { $r15mStolen = true; $r15mSteal($r15mDefault, 'R15M-RACE2', 5); }
    }, static fn(): array => Warehouse::reserve('R15M-RACE2', 3, Inventory::BACKORDER));
    assert_equals(['reserved' => 0, 'short' => 3, 'zero' => 0, 'count' => 1], $r15mSplit($r15mAlloc) + ['count' => count($r15mAlloc)],
        'R15 mutants: a row emptied by a concurrent checkout yields no 0-unit allocation');
    $r15mSteal($r15mDefault, 'R15M-RACE2', -5);
} catch (Throwable $e) {
    $r15mFail('reserve race (emptied)', $e);
}

// ... and a row whose availability flips back after every lost race is given up after one retry read — no spinning.
try {
    Warehouse::setStock($r15mDefault, 'R15M-RACE3', 5, 'count');
    $r15mUpdates = 0;
    $r15mHeld = false;
    $r15mAlloc = $r15mWithHook(static function (string $sql) use (&$r15mUpdates, &$r15mHeld, $r15mSteal, $r15mDefault, $r15mReserveSql): void {
        if (str_contains($sql, $r15mReserveSql) && ++$r15mUpdates <= 50) { $r15mSteal($r15mDefault, 'R15M-RACE3', 5); $r15mHeld = true; }
        if ($r15mHeld && str_contains($sql, 'SELECT qty - reserved FROM warehouse_stock')) { $r15mSteal($r15mDefault, 'R15M-RACE3', -5); $r15mHeld = false; }
    }, static fn(): array => Warehouse::reserve('R15M-RACE3', 3, Inventory::BACKORDER));
    assert_equals(['updates' => 1, 'reserved' => 0, 'short' => 3, 'row' => 0], ['updates' => $r15mUpdates] + array_intersect_key($r15mSplit($r15mAlloc), ['reserved' => 1, 'short' => 1]) + ['row' => $r15mReservedRow($r15mDefault, 'R15M-RACE3')],
        'R15 mutants: a lost race whose re-read shows no less stock does not retry the same reservation');
} catch (Throwable $e) {
    $r15mFail('reserve race (flip-flop)', $e);
}

// Warehouse::reservedUnits / delete, setAvailable on a fully reserved default warehouse, and setAvailable inside the
// caller's transaction: a failed edit does not roll back the caller's work.
try {
    $r15mAux = Warehouse::save(['code' => 'r15m-aux', 'name' => 'R15M aux', 'priority' => -50, 'is_default' => 0]);
    Warehouse::setStock($r15mAux, 'R15M-RU', 4, 'count');
    $r15mRu = Warehouse::reserve('R15M-RU', 3);
    assert_equals([3, 3], [Warehouse::reservedUnits($r15mAux), Warehouse::reservedUnits($r15mAux, true)], 'R15 mutants: reservedUnits sums the reservations of a warehouse (locked read too)');
    Warehouse::release('R15M-RU', $r15mRu);

    Warehouse::setStock($r15mDefault, 'R15M-SA', 2, 'count');
    Warehouse::setStock($r15mAux, 'R15M-SA', 5, 'count');
    $r15mSa = Warehouse::reserve('R15M-SA', 2);
    assert_equals([2, 0], [$r15mReservedRow($r15mDefault, 'R15M-SA'), $r15mReservedRow($r15mAux, 'R15M-SA')], 'R15 mutants: fixture — the default warehouse is fully reserved');
    assert_true(Warehouse::setAvailable('R15M-SA', 3), 'R15 mutants: a decrease is booked');
    $r15mZero = Db::get()->prepare("SELECT COUNT(*) FROM stock_movements WHERE sku = 'R15M-SA' AND movement_type = 'manual_adjustment' AND quantity_delta = 0");
    $r15mZero->execute();
    $r15mAuxQty = Db::get()->prepare("SELECT qty FROM warehouse_stock WHERE sku = 'R15M-SA' AND warehouse_id = :w");
    $r15mAuxQty->execute([':w' => $r15mAux]);
    assert_equals([0, 3], [(int)$r15mZero->fetchColumn(), (int)$r15mAuxQty->fetchColumn()],
        'R15 mutants: a decrease skips the fully reserved default warehouse (no 0-unit ledger movement) and takes the free stock elsewhere');

    $r15mPdo = Db::get();
    $r15mPdo->beginTransaction();
    $r15mRefused = false;
    try {
        $r15mStolen = false;
        $r15mWithHook(static function (string $sql) use (&$r15mStolen, $r15mAux): void {
            if (!$r15mStolen && str_contains($sql, 'SET qty = qty + :delta')) {
                $r15mStolen = true;
                Db::get()->exec("UPDATE warehouse_stock SET reserved = qty WHERE sku = 'R15M-SA' AND warehouse_id = " . $r15mAux);
            }
        }, static fn(): bool => Warehouse::setAvailable('R15M-SA', 1));
    } catch (RuntimeException) {
        $r15mRefused = true;
    }
    $r15mStillOpen = $r15mPdo->inTransaction();
    if ($r15mStillOpen) $r15mPdo->rollBack();
    assert_equals([true, true], [$r15mRefused, $r15mStillOpen], 'R15 mutants: an edit refused inside the caller\'s transaction leaves that transaction to the caller');

    Warehouse::release('R15M-SA', $r15mSa);
    Warehouse::delete($r15mAux);
} catch (Throwable $e) {
    $r15mFail('warehouse totals', $e);
} finally {
    if (Db::get()->inTransaction()) Db::get()->rollBack();
}

$r15mRow = static function (string $sku) use ($r15mDefault): array {
    $stmt = Db::get()->prepare('SELECT qty, reserved FROM warehouse_stock WHERE warehouse_id = :w AND sku = :sku');
    $stmt->execute([':w' => $r15mDefault, ':sku' => $sku]);
    return array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
};
$r15mMovement = static function (string $sku, string $type): array {
    $stmt = Db::get()->prepare('SELECT quantity_delta, reserved_delta FROM stock_movements WHERE sku = :sku AND movement_type = :type ORDER BY id DESC LIMIT 1');
    $stmt->execute([':sku' => $sku, ':type' => $type]);
    return array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
};
$r15mThrows = static function (Closure $run): string {
    try {
        $run();
        return 'none';
    } catch (Throwable $e) {
        return get_class($e);
    }
};

// Warehouse::release / restock / ship: only a reserved allocation of a real warehouse is touched (one without a status
// counts as reserved), an invalid shipment allocation is refused up front, and the ledger books both as decreases.
try {
    Warehouse::setStock($r15mDefault, 'R15M-RL', 6, 'count');
    $r15mSteal($r15mDefault, 'R15M-RL', 3);
    $r15mIgnored = [['warehouse_id' => null, 'qty' => 2], ['warehouse_id' => $r15mDefault, 'qty' => 1, 'status' => 'released']];
    Warehouse::release('R15M-RL', $r15mIgnored);
    Warehouse::restock('R15M-RL', $r15mIgnored);
    assert_equals(['qty' => 6, 'reserved' => 3], $r15mRow('R15M-RL'), 'R15 mutants: release and restock skip a backorder allocation and one no longer reserved');
    Warehouse::release('R15M-RL', [['warehouse_id' => $r15mDefault, 'qty' => 1]]);
    assert_equals([['qty' => 6, 'reserved' => 2], ['quantity_delta' => 0, 'reserved_delta' => -1]], [$r15mRow('R15M-RL'), $r15mMovement('R15M-RL', 'release')],
        'R15 mutants: an allocation without a status is released; the ledger books -1 reserved');
    Warehouse::restock('R15M-RL', [['warehouse_id' => $r15mDefault, 'qty' => 1]]);
    assert_equals(['qty' => 7, 'reserved' => 2], $r15mRow('R15M-RL'), 'R15 mutants: an allocation without a status is restocked');
    assert_equals(array_fill(0, 4, InvalidArgumentException::class), [
        $r15mThrows(static fn() => Warehouse::ship('R15M-RL', ['warehouse_id' => $r15mDefault], 'r15m-s1')),
        $r15mThrows(static fn() => Warehouse::ship('R15M-RL', ['warehouse_id' => 0, 'qty' => 1], 'r15m-s2')),
        $r15mThrows(static fn() => Warehouse::ship('R15M-RL', ['warehouse_id' => $r15mDefault, 'qty' => 0], 'r15m-s3')),
        $r15mThrows(static fn() => Warehouse::ship('R15M-RL', ['qty' => 1], 'r15m-s4')),
    ], 'R15 mutants: a shipment allocation without quantity, with warehouse 0, with quantity 0 or without warehouse is invalid');
    Warehouse::ship('R15M-RL', ['warehouse_id' => $r15mDefault, 'qty' => 1], 'r15m-ship');
    assert_equals([['qty' => 6, 'reserved' => 1], ['quantity_delta' => -1, 'reserved_delta' => -1]], [$r15mRow('R15M-RL'), $r15mMovement('R15M-RL', 'shipment')],
        'R15 mutants: a shipment consumes the reservation; the ledger books -1 on hand and -1 reserved');
    $r15mSteal($r15mDefault, 'R15M-RL', -1);
} catch (Throwable $e) {
    $r15mFail('release/restock/ship', $e);
}

// Warehouse::bulkSetStock: an empty SKU, a count that is no integer and a negative count are skipped; a count equal to
// the reservations is no oversell; a failure inside the caller's transaction leaves that transaction to the caller.
try {
    Warehouse::setStock($r15mDefault, 'R15M-FEED-EQ', 4, 'count');
    $r15mSteal($r15mDefault, 'R15M-FEED-EQ', 2);
    $r15mSkipped = null;
    $r15mCount = Warehouse::bulkSetStock($r15mDefault, ['' => 3, 'R15M-FEED-ABC' => 'abc', 'R15M-FEED-NEG' => -1, 'R15M-FEED-EQ' => 2, 'R15M-FEED-OK' => 7], $r15mSkipped);
    assert_equals([2, ['', 'R15M-FEED-ABC', 'R15M-FEED-NEG'], [], [], ['qty' => 2, 'reserved' => 2], ['qty' => 7, 'reserved' => 0]],
        [$r15mCount, $r15mSkipped, $r15mRow(''), $r15mRow('R15M-FEED-ABC') + $r15mRow('R15M-FEED-NEG'), $r15mRow('R15M-FEED-EQ'), $r15mRow('R15M-FEED-OK')],
        'R15 mutants: a feed skips an empty SKU, a non-integer count and a negative count and applies the rest');
    $r15mSteal($r15mDefault, 'R15M-FEED-EQ', -2);

    $r15mPdo = Db::get();
    $r15mPdo->beginTransaction();
    $r15mFailed = $r15mThrows(static fn() => Warehouse::bulkSetStock(987654321, ['R15M-FEED-TX' => 1]));
    $r15mStillOpen = $r15mPdo->inTransaction();
    if ($r15mStillOpen) $r15mPdo->rollBack();
    assert_equals([RuntimeException::class, true], [$r15mFailed, $r15mStillOpen], 'R15 mutants: a feed failing inside the caller\'s transaction leaves that transaction to the caller');
} catch (Throwable $e) {
    $r15mFail('bulk stock', $e);
} finally {
    if (Db::get()->inTransaction()) Db::get()->rollBack();
}

// ... a feed count equal to the reservations is not an oversell (own migrated DB: the suite DB may lack the incidents).
[$r15mSavedDb, $r15mSavedDriver] = [Db::get(), Db::driver()];
$r15mLock = sys_get_temp_dir() . '/bbe-r15m-' . getmypid() . '.lock';
try {
    $r15mMemDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    (new Migrator($r15mMemDb, TEST_CLI_DIR . '/../migrations', $r15mLock))->apply(static fn($m) => null);
    Db::init($r15mMemDb, 'sqlite');
    $r15mMemWh = Warehouse::save(['code' => 'r15m-eq', 'name' => 'R15M eq', 'is_default' => 1]);
    Warehouse::setStock($r15mMemWh, 'R15M-EQ', 4);
    Warehouse::reserve('R15M-EQ', 2);
    assert_equals(1, Warehouse::bulkSetStock($r15mMemWh, ['R15M-EQ' => 2]), 'R15 mutants: fixture — the feed count equal to the reservations is applied');
    assert_equals(0, (int)$r15mMemDb->query("SELECT COUNT(*) FROM operational_incidents WHERE code IN ('inventory.feed_below_reserved', 'inventory.feed_invalid_rows')")->fetchColumn(),
        'R15 mutants: a feed count equal to the reservations is not reported as an oversell, and a clean feed reports no invalid rows');
} catch (Throwable $e) {
    $r15mFail('feed oversell', $e);
} finally {
    Db::init($r15mSavedDb, $r15mSavedDriver);
    @unlink($r15mLock);
}

// Warehouse::setAvailable: without a default warehouse nothing is booked and the caller is told; on MySQL the read of
// the current stock is locked.
try {
    Db::get()->exec('UPDATE warehouses SET is_default = 0 WHERE id = ' . $r15mDefault);
    try {
        $r15mNoDefault = Warehouse::setAvailable('R15M-NODEF', 3);
    } finally {
        Db::get()->exec('UPDATE warehouses SET is_default = 1 WHERE id = ' . $r15mDefault);
    }
    assert_equals([false, 0], [$r15mNoDefault, Warehouse::totalAvailable('R15M-NODEF')], 'R15 mutants: setAvailable without a default warehouse returns false and books nothing');
    $r15mSql = [];
    $r15mWithHook(static function (string $sql) use (&$r15mSql): void { $r15mSql[] = $sql; }, static fn(): bool => Warehouse::setAvailable('R15M-LOCK', 2));
    $r15mLocked = array_filter($r15mSql, static fn(string $sql): bool => str_contains($sql, 'SUM(qty - reserved)') && str_contains($sql, 'FOR UPDATE'));
    assert_true(!Db::isMysql() || $r15mLocked !== [], 'R15 mutants: on MySQL setAvailable locks the stock it reads');
} catch (Throwable $e) {
    $r15mFail('setAvailable', $e);
}

// Warehouse::reserve: 0 units is no reservation; a row with units already reserved is taken in one guarded UPDATE
// (its free units, not qty + reserved — that would always take the lost-race path).
try {
    assert_equals(InvalidArgumentException::class, $r15mThrows(static fn() => Warehouse::reserve('R15M-RL', 0)), 'R15 mutants: a reservation of 0 units is refused');
    Warehouse::setStock($r15mDefault, 'R15M-ONE', 5, 'count');
    $r15mSteal($r15mDefault, 'R15M-ONE', 2);
    $r15mUpdates = 0;
    $r15mAlloc = $r15mWithHook(static function (string $sql) use (&$r15mUpdates, $r15mReserveSql): void {
        if (str_contains($sql, $r15mReserveSql)) $r15mUpdates++;
    }, static fn(): array => Warehouse::reserve('R15M-ONE', 4, Inventory::BACKORDER));
    assert_equals(['updates' => 1, 'reserved' => 3, 'short' => 1], ['updates' => $r15mUpdates] + array_intersect_key($r15mSplit($r15mAlloc), ['reserved' => 1, 'short' => 1]),
        'R15 mutants: the free units of a partly reserved row are reserved in one UPDATE');
    Warehouse::release('R15M-ONE', $r15mAlloc);
    $r15mSteal($r15mDefault, 'R15M-ONE', -2);
} catch (Throwable $e) {
    $r15mFail('reserve one update', $e);
}

// Warehouse::setAvailable takes a decrease from the default warehouse first; a stock change flips a product's
// availability and so marks the catalog changed; stock.adjusted is recorded only for a SKU the catalog sells.
try {
    $r15mAux2 = Warehouse::save(['code' => 'r15m-aux2', 'name' => 'R15M aux 2', 'priority' => -40, 'is_default' => 0]);
    Warehouse::setStock($r15mDefault, 'R15M-ORD', 5, 'count');
    Warehouse::setStock($r15mAux2, 'R15M-ORD', 5, 'count');
    Warehouse::setAvailable('R15M-ORD', 7);
    $r15mQty = Db::get()->prepare("SELECT warehouse_id, qty FROM warehouse_stock WHERE sku = 'R15M-ORD' ORDER BY warehouse_id");
    $r15mQty->execute();
    assert_equals([$r15mDefault => 2, $r15mAux2 => 5], array_map('intval', $r15mQty->fetchAll(PDO::FETCH_KEY_PAIR)), 'R15 mutants: a decrease takes the default warehouse\'s free stock first');

    $r15mEvents = static fn(): int => (int)Db::get()->query("SELECT COUNT(*) FROM event_outbox WHERE event_type = " . Db::get()->quote(DomainEvents::STOCK_ADJUSTED))->fetchColumn();
    $r15mEventsBefore = $r15mEvents();
    Warehouse::adjustStock($r15mDefault, 'R15M-ORD', 1);
    assert_equals($r15mEventsBefore, $r15mEvents(), 'R15 mutants: a stock change of a SKU without a product records no stock.adjusted');

    Db::get()->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES (?, (SELECT MIN(id) FROM categories), 'R15M-CAT', 'r15m-cat', 'R15M cat', 1000, 0, 'active')")
        ->execute([StoreContext::current()->catalogId()]);
    $r15mMarker = (new ReflectionMethod(Cache::class, 'catalogMarker'))->invoke(null, StoreContext::current()->catalogId());
    $r15mSynced = static function (string $sku) use ($r15mMarker): bool {
        @unlink($r15mMarker);
        Warehouse::syncProductStock([$sku]);
        clearstatcache(true, $r15mMarker);
        return is_file($r15mMarker);
    };
    Warehouse::setStock($r15mDefault, 'R15M-CAT', 3, 'count');
    $r15mCatStock = static fn(): int => (int)Db::get()->query("SELECT stock FROM products WHERE sku = 'R15M-CAT'")->fetchColumn();
    assert_equals([0, true, 3, false], [$r15mCatStock(), $r15mSynced('R15M-CAT'), $r15mCatStock(), $r15mSynced('R15M-CAT')],
        'R15 mutants: syncing a product into stock marks the catalog changed; a sync that flips nothing does not');
    Warehouse::adjustStock($r15mDefault, 'R15M-CAT', 1);
    assert_equals($r15mEventsBefore + 1, $r15mEvents(), 'R15 mutants: a stock change of a catalog SKU records stock.adjusted');
    Warehouse::delete($r15mAux2);
} catch (Throwable $e) {
    $r15mFail('setAvailable order / catalog', $e);
}
if ($r15mOwnDefault) Warehouse::delete($r15mDefault);

// PaymentService::chargedFee: under reverse charge / export only the fixed part loses the domestic VAT (a quote
// without a currency is EUR); without it, or without a fixed part, the fee is charged as quoted.
try {
    $r15mNet = Money::round(Cart::netOfDomesticVat(200, 'SK', 'standard', null), 'eur');
    assert_true($r15mNet > 0 && $r15mNet < 200, 'R15 mutants: fixture — SK domestic VAT makes 2.00 net smaller (' . $r15mNet . ')');
    assert_equals([300, 100 + $r15mNet, 300], [
        PaymentService::chargedFee(['fee' => 300, 'fixed_fee' => 200], false, 'SK'),
        PaymentService::chargedFee(['fee' => 300, 'fixed_fee' => 200], true, 'SK'),
        PaymentService::chargedFee(['fee' => 300, 'fixed_fee' => 0], true, 'SK'),
    ], 'R15 mutants: a payment fee loses the domestic VAT of its fixed part only when charged net of it');
    assert_true(!PaymentService::canPaymentTransition('r15m-unknown', 'paid'), 'R15 mutants: an unknown payment status allows no transition (and raises no warning)');
} catch (Throwable $e) {
    $r15mFail('payment fee', $e);
}

// PaymentService::availableMethods: a cart in a currency the store has no own prices for gets only methods without
// amounts — a minimum, a maximum or a free-above limit each counts like a fixed fee.
[$r15mSavedDb, $r15mSavedDriver] = [Db::get(), Db::driver()];
try {
    $r15mMemDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    (new Migrator($r15mMemDb, TEST_CLI_DIR . '/../migrations', $r15mLock))->apply(static fn($m) => null);
    Db::init($r15mMemDb, 'sqlite');
    $r15mScope = StoreContext::current()->providerId();
    $r15mMethod = $r15mMemDb->prepare("INSERT INTO payment_methods (store_id, code, provider, name, service_type, min_amount, max_amount, free_above, fixed_fee, sort_order)
        VALUES (?, ?, '', ?, 'offline', ?, ?, ?, 0, 1)");
    $r15mCountry = $r15mMemDb->prepare("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES (?, ?, 'SK')");
    foreach (['r15m-min' => [100, null, null], 'r15m-max' => [0, 100000, null], 'r15m-free' => [0, null, 5000], 'r15m-none' => [0, null, null]] as $r15mCode => [$r15mMinAmount, $r15mMaxAmount, $r15mFreeAbove]) {
        $r15mMethod->execute([$r15mScope, $r15mCode, $r15mCode, $r15mMinAmount, $r15mMaxAmount, $r15mFreeAbove]);
        $r15mCountry->execute([$r15mScope, $r15mCode]);
    }
    $r15mOffered = static fn(string $currency): array => array_values(array_filter(array_column(PaymentService::availableMethods('SK', $currency, 1000), 'code'),
        static fn(string $code): bool => str_starts_with($code, 'r15m-')));
    assert_true(!StoreContext::current()->usesProviderPrices('czk'), 'R15 mutants: fixture — the store has no own CZK prices');
    assert_equals([['r15m-free', 'r15m-max', 'r15m-min', 'r15m-none'], ['r15m-none']], [$r15mOffered('eur'), $r15mOffered('czk')],
        'R15 mutants: a CZK cart is not offered a method with an EUR minimum, maximum or free-above amount');
} catch (Throwable $e) {
    $r15mFail('payment methods by currency', $e);
} finally {
    Db::init($r15mSavedDb, $r15mSavedDriver);
    @unlink($r15mLock);
}

// PaymentService::requestRefund: 0 is no refund; a request is booked (provider from the method when the snapshot names
// none) and says so; a refused request inside the caller's transaction leaves it to the caller. refundRequestSettled:
// a missing order settles nothing, and a booked refund that settles no request is not one.
try {
    $r15mMemDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    (new Migrator($r15mMemDb, TEST_CLI_DIR . '/../migrations', $r15mLock))->apply(static fn($m) => null);
    Db::init($r15mMemDb, 'sqlite');
    $r15mMemDb->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency,
            status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
        VALUES ('R15M-RF', 'R15M', 'r15m@example.test', '', '{}', 1000, 0, 0, 0, 1000, 'eur', 'processing', 'paid', 'bank', '', '{}', ?, " . Db::now() . ")")
        ->execute([StoreContext::current()->id()]);
    $r15mRf = (int)$r15mMemDb->lastInsertId();
    assert_equals(InvalidArgumentException::class, $r15mThrows(static fn() => PaymentService::requestRefund(987654, 0, 'r15m-rf-0')), 'R15 mutants: a refund request of 0 is refused');
    assert_true(PaymentService::requestRefund($r15mRf, 300, 'r15m-rf-1') === true, 'R15 mutants: a refund request reports that it was booked');
    assert_equals(['bank', '300'], array_values(array_map('strval', $r15mMemDb->query("SELECT provider, amount FROM payment_transactions WHERE idempotency_key = 'r15m-rf-1'")->fetch() ?: [])),
        'R15 mutants: a request on an order whose method snapshot names no provider books the payment method (and raises no warning)');
    $r15mMemDb->exec("UPDATE orders SET payment_status = 'paid' WHERE id = {$r15mRf}");
    $r15mMemDb->beginTransaction();
    $r15mRefused = $r15mThrows(static fn() => PaymentService::requestRefund($r15mRf, 5000, 'r15m-rf-2'));
    $r15mStillOpen = $r15mMemDb->inTransaction();
    if ($r15mStillOpen) $r15mMemDb->rollBack();
    assert_equals([LogicException::class, true], [$r15mRefused, $r15mStillOpen], 'R15 mutants: a refund refused inside the caller\'s transaction leaves that transaction to the caller');

    $r15mSettled = new ReflectionMethod(PaymentService::class, 'refundRequestSettled');
    PaymentService::recordEvent($r15mRf, 'refund.succeeded', 'refunded', 100, 'eur', 'r15m-rf-booked', ['note' => 'r15m']);
    assert_equals([false, false], [$r15mSettled->invoke(null, 987654, 'order_refund_987654', 1), $r15mSettled->invoke(null, $r15mRf, 'order_refund_' . $r15mRf, 100)],
        'R15 mutants: no order, or a booked refund that settles no request, does not settle the refund request (and raises no warning)');
} catch (Throwable $e) {
    $r15mFail('refund request', $e);
} finally {
    if (Db::get()->inTransaction()) Db::get()->rollBack();
    Db::init($r15mSavedDb, $r15mSavedDriver);
    @unlink($r15mLock);
}
