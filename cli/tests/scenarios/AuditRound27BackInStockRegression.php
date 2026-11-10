<?php

// Review round 27 (2026-11-10, "kolo 11"), finding 4 and its low (goal #396): back-in-stock notices that did not reach
// the shopper were lost or multiplied.
// - The recipient's limit (10 an hour) counted the copies only written to the log, and a rate_limited notice was taken
//   as delivered: the request was deleted. Probe: six requests with a misconfigured provider, none delivered, all deleted.
// - Every restock started its own series of retries for the same request (48 each).
// - With MAIL_ADAPTER=log on purpose the notice was retried 48 times; order mail is not.
echo "\n--- R27: back-in-stock — rate_limited is not delivered, log copies do not count, one series per request ---\n";
$r27bDb = Db::get();
$r27bEnv = getenv('MAIL_ADAPTER');
$r27bJobs = test_fixture_path('r27b-jobs');
JobQueue::setDir($r27bJobs);
if (!class_exists('R27bCaptureMail', false)) {
    class R27bCaptureMail implements MailAdapter {
        /** @var list<array> */
        public array $sent = [];
        public function send(array $msg): array { $this->sent[] = $msg; return ['status' => 'sent']; }
        public function adapterId(): string { return 'smtp'; }
    }
}
$r27bCfgProp = new ReflectionProperty(Security::class, 'cfg');
$r27bCfgBefore = $r27bCfgProp->getValue();
try {
    $r27bCfgProp->setValue(null, ['app_url' => 'https://r27b.example.test'] + (is_array($r27bCfgBefore) ? $r27bCfgBefore : require AppConfig::path(dirname(TEST_CLI_DIR))));
    foreach (['20261025120000_back_in_stock_requests', '20261104120000_back_in_stock_double_opt_in', '20261110120000_back_in_stock_retry'] as $r27bMigration) {
        (require TEST_CLI_DIR . '/../migrations/' . $r27bMigration . '.php')->up($r27bDb, Db::isSqlite());
    }
    $r27bDb->exec('DELETE FROM back_in_stock_requests WHERE product_id = 92788');
    $r27bDb->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (92788, 'default', 1, 'R27B item', 'r27b-item', 'R27B-S', 'simple', 900, 'eur', 'active', 0, 'deny')")->execute();
    $r27bWh = (int)$r27bDb->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $r27bDb->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', ?, 'R27B-S', 0, 0)")->execute([$r27bWh]);
    Warehouse::setStock($r27bWh, 'R27B-S', 3, 'count');
    Warehouse::syncProductStock(['R27B-S']);
    BackInStock::flush();
    $r27bRequest = static function (string $email) use ($r27bDb): void {
        $r27bDb->prepare('INSERT INTO back_in_stock_requests (store_id, product_id, email, locale, created_at, confirmed_at) VALUES (?, 92788, ?, ?, ' . Db::now() . ', ' . Db::now() . ')')
            ->execute(['default', $email, 'en']);
    };
    $r27bRows = static fn(): array => $r27bDb->query('SELECT email, mail_tries, next_try_at IS NOT NULL AS waits FROM back_in_stock_requests WHERE product_id = 92788 ORDER BY id')->fetchAll(PDO::FETCH_NUM);
    $r27bLogCopy = static function (string $email, string $adapter, int $count) use ($r27bDb): void {
        for ($i = 0; $i < $count; $i++) {
            $r27bDb->prepare("INSERT INTO email_log (recipient, template, locale, subject, adapter, status, created_at) VALUES (?, 'back_in_stock', 'en', 'R27B', ?, 'sent', " . Db::now() . ')')
                ->execute([$email, $adapter]);
        }
    };
    $r27bLogged = static fn(string $email): int => (int)$r27bDb->query("SELECT COUNT(*) FROM email_log WHERE template = 'back_in_stock' AND recipient = " . $r27bDb->quote($email))->fetchColumn();
    // The back_in_stock jobs queued for this product (taken off the queue).
    $r27bQueued = static function () use ($r27bJobs): array {
        $found = [];
        if (!is_dir($r27bJobs)) return [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r27bJobs, FilesystemIterator::SKIP_DOTS)) as $file) {
            $job = json_decode((string)file_get_contents($file->getPathname()), true);
            if (is_array($job) && ($job['type'] ?? '') === 'back_in_stock' && in_array('R27B-S', (array)($job['payload']['skus'] ?? []), true)) {
                $found[] = $job;
                @unlink($file->getPathname());
            }
        }
        return $found;
    };
    $r27bQueued();
    $r27bCapture = new R27bCaptureMail();

    // 1. Ten copies of the notice written to the log in the last hour: they reached nobody and do not use up the limit.
    $r27bRequest('r27b-logcopies@r27b.example.test');
    $r27bLogCopy('r27b-logcopies@r27b.example.test', 'log', 10);
    MailService::useAdapter($r27bCapture);
    $r27bSent = BackInStock::process(['R27B-S']);
    MailService::useAdapter(null);
    assert_equals([1, ['r27b-logcopies@r27b.example.test'], []], [$r27bSent, array_column($r27bCapture->sent, 'to'), $r27bRows()],
        'R27 back in stock: copies only written to the log do not count toward the recipient limit; the notice goes out (was: rate_limited)');

    // 2. A recipient whose limit real mail used up: rate_limited is not delivered — the request stays, tried after the window.
    $r27bRequest('r27b-limited@r27b.example.test');
    $r27bLogCopy('r27b-limited@r27b.example.test', 'smtp', 10);
    $r27bCapture->sent = [];
    MailService::useAdapter($r27bCapture);
    $r27bSent = BackInStock::process(['R27B-S']);
    MailService::useAdapter(null);
    $r27bJob = $r27bQueued();
    assert_equals([0, [], [['r27b-limited@r27b.example.test', 0, 1]], 1, true],
        [$r27bSent, $r27bCapture->sent, array_map(static fn(array $r): array => [$r[0], (int)$r[1], (int)$r[2]], $r27bRows()), count($r27bJob),
            strtotime((string)($r27bJob[0]['retry_after'] ?? '') . 'Z') > time() + 3000],
        'R27 back in stock: a rate_limited notice keeps the request and is queued again after the limit window (was: deleted as notified)');
    $r27bDb->exec('DELETE FROM back_in_stock_requests WHERE product_id = 92788');

    // 3. The reviewer's probe: six requests, misconfigured provider, two restocks before the retry is due. Nothing is
    //    deleted, each request is written to the log once (one series), and one job waits to try again.
    for ($i = 1; $i <= 6; $i++) $r27bRequest("r27b-probe{$i}@r27b.example.test");
    putenv('MAIL_ADAPTER=smtp.');
    $r27bSent = BackInStock::process(['R27B-S']) + BackInStock::process(['R27B-S']);
    $r27bJob = $r27bQueued();
    assert_equals([0, 6, [1, 1, 1, 1, 1, 1], 1, 1],
        [$r27bSent, count($r27bRows()), array_map(static fn(array $r): int => (int)$r[1], $r27bRows()), $r27bLogged('r27b-probe1@r27b.example.test'), count($r27bJob)],
        'R27 back in stock: notices only logged keep all six requests, and a restock meanwhile starts no second series (was: deleted; one series per restock)');
    // Once the provider is fixed and the retry is due, every shopper is told once.
    putenv('MAIL_ADAPTER=smtp');
    $r27bDb->exec("UPDATE back_in_stock_requests SET next_try_at = '2000-01-01 00:00:00' WHERE product_id = 92788");
    $r27bCapture->sent = [];
    MailService::useAdapter($r27bCapture);
    foreach ($r27bJob as $job) BackInStock::handleJob((array)$job['payload']);
    MailService::useAdapter(null);
    assert_equals([6, [], []], [count($r27bCapture->sent), $r27bRows(), $r27bQueued()], 'R27 back in stock: the due retry tells all six shoppers once the provider is fixed');

    // 4. MAIL_ADAPTER=log on purpose: the request stays for a real provider, but nothing is tried again (as order mail).
    $r27bRequest('r27b-intended@r27b.example.test');
    putenv('MAIL_ADAPTER=log');
    $r27bSent = BackInStock::process(['R27B-S']);
    assert_equals([0, [['r27b-intended@r27b.example.test', 0, 0]], []],
        [$r27bSent, array_map(static fn(array $r): array => [$r[0], (int)$r[1], (int)$r[2]], $r27bRows()), $r27bQueued()],
        'R27 back in stock: with MAIL_ADAPTER=log on purpose the notice is not retried (was: 48 tries)');
} catch (Throwable $e) {
    assert_true(false, 'R27 back in stock (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    MailService::useAdapter(null);
    putenv($r27bEnv === false ? 'MAIL_ADAPTER' : 'MAIL_ADAPTER=' . $r27bEnv);
    $r27bCfgProp->setValue(null, $r27bCfgBefore);
    $r27bDb->exec('DELETE FROM back_in_stock_requests WHERE product_id = 92788');
    $r27bDb->exec("DELETE FROM email_log WHERE recipient LIKE '%@r27b.example.test'");
    $r27bDb->exec("DELETE FROM warehouse_stock WHERE sku = 'R27B-S'");
    $r27bDb->exec('DELETE FROM products WHERE id = 92788');
}
