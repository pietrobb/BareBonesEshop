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
    if ($r15mOwnDefault) Warehouse::delete($r15mDefault);
} catch (Throwable $e) {
    $r15mFail('warehouse totals', $e);
} finally {
    if (Db::get()->inTransaction()) Db::get()->rollBack();
}
