<?php

// Review round 54 (kolo 19), e-book downloads:
//  medium 2: each retry of the delivery mail revoked the entitlement and granted a new one with download_count 0, and the
//            account downloads page offered the entitlement before its mail was delivered: with a limit of 3, nine copies;
//  low: after a killed transfer (its lease lapsed) a resume nearly always counted as a new download: the offset was only
//       recorded every 8 MiB and a resume had to start at or below it;
//  low: WorkerJobs::fulfilDigitalOrder() had lost its docblock.
echo "\n--- Audit round 54: download limit across delivery retries, resume after a killed transfer ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$pdo = Db::get();
$r54dTag = 'R54D-' . getmypid() . '-' . bin2hex(random_bytes(3));
$r54dProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare("INSERT INTO customers (store_id, email, password_hash, name, status, locale, email_verified_at) VALUES ('default', ?, ?, 'R54D', 'active', 'sk', '2026-01-01 00:00:00')")
    ->execute([strtolower($r54dTag) . '@example.test', password_hash('r54d-password', PASSWORD_DEFAULT)]);
$r54dCustomer = (int)$pdo->lastInsertId();
$r54dEnt = static fn(int $id): array => $pdo->query("SELECT * FROM digital_entitlements WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC) ?: [];
$r54dActive = static fn(int $orderId): array => $pdo->query("SELECT * FROM digital_entitlements WHERE order_id = {$orderId} AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
try {
    assert_true($r54dProduct > 0 && $r54dCustomer > 0, 'R54 digital: a product and a customer to order with (precondition)');
    $pdo->prepare("INSERT INTO orders (order_number, customer_id, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount,
            discount_amount, total, currency, status, payment_status, payment_method, shipping_method, store_id)
        VALUES (?, ?, 'R54D', 'r54d@example.test', '', '{\"country\":\"SK\"}', 900, 0, 0, 0, 900, 'eur', 'completed', 'paid', 'card', 'digital', 'default')")
        ->execute([$r54dTag, $r54dCustomer]);
    $r54dOrder = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, 'R54D e-book', 1, 900, 900)")
        ->execute([$r54dOrder, $r54dProduct, $r54dTag]);
    $pdo->prepare("INSERT INTO digital_assets (sku, asset_key, storage_path, download_limit) VALUES (?, 'default', 'r54d/book.epub', 3)")->execute([$r54dTag]);
    $r54dOnAccount = static fn(): array => array_values(array_filter(DigitalDelivery::forCustomer($r54dCustomer),
        static fn(array $row): bool => (int)$row['order_id'] === $r54dOrder));

    // 1. First attempt: granted, two downloads counted, then the delivery mail fails (WorkerJobs revokes the links).
    $r54dFirst = DigitalDelivery::grant($r54dOrder);
    $r54dA = (int)($r54dFirst[0]['id'] ?? 0);
    assert_equals([], $r54dOnAccount(), 'R54 digital: an entitlement whose delivery mail is not sent yet is not offered on the account page');
    assert_true(DigitalDelivery::consume($r54dA) && DigitalDelivery::consume($r54dA), 'R54 digital: two downloads on the first attempt');
    DigitalDelivery::revokeIssued([$r54dA], 'delivery_email_failed');

    // 2. The retry grants again: the two downloads stay counted, so only one more is possible.
    $r54dSecond = DigitalDelivery::grant($r54dOrder);
    $r54dB = (int)($r54dSecond[0]['id'] ?? 0);
    assert_equals(2, (int)($r54dEnt($r54dB)['download_count'] ?? -1), 'R54 digital: the retry keeps the downloads already counted (was: 0 again)');
    // 3. Retried while still undelivered (grant() rotates it, delivery_retry): still counted.
    assert_true(DigitalDelivery::consume($r54dB), 'R54 digital: the third download');
    $r54dThird = DigitalDelivery::grant($r54dOrder);
    $r54dC = (int)($r54dThird[0]['id'] ?? 0);
    assert_true($r54dC !== $r54dB && ($r54dEnt($r54dB)['revoked_reason'] ?? '') === 'delivery_retry' && (int)($r54dEnt($r54dC)['download_count'] ?? -1) === 3,
        'R54 digital: a rotation of an undelivered entitlement keeps its count too');
    assert_true(!DigitalDelivery::consume($r54dC), 'R54 digital: the limit of 3 holds across delivery attempts (was: 9 copies)');
    DigitalDelivery::markDelivered([$r54dC]);
    $r54dRows = $r54dOnAccount();
    assert_true(count($r54dRows) === 1 && (int)$r54dRows[0]['id'] === $r54dC && !DigitalDelivery::downloadable($r54dRows[0]),
        'R54 digital: once delivered it is on the account page, its limit spent');
    assert_equals(1, count($r54dActive($r54dOrder)), 'R54 digital: one active entitlement per line and asset');

    // 4. A resume after a killed transfer (lease set, lapsed): its offset was recorded at most PROGRESS_BYTES ago.
    $pdo->prepare("INSERT INTO digital_entitlements (order_id, order_item_id, asset_id, token_hash, status, download_limit) VALUES (?, ?, ?, ?, 'active', 1)")
        ->execute([$r54dOrder, (int)$r54dEnt($r54dC)['order_item_id'], (int)$r54dEnt($r54dC)['asset_id'], hash('sha256', $r54dTag . 'resume')]);
    $r54dR = (int)$pdo->lastInsertId();
    $r54dSize = 64 * 1048576;
    $r54dNow = time();
    $r54dTransfer = DigitalDelivery::openTransfer($r54dEnt($r54dR), 0, $r54dSize, $r54dNow);
    DigitalDelivery::recordProgress($r54dR, (int)($r54dTransfer['counted_at'] ?? 0), 10 * 1048576);
    // The request is killed: no endTransfer(); the client got up to PROGRESS_BYTES more than recorded.
    $r54dLater = $r54dNow + DigitalDelivery::LEASE_SECONDS + 5;
    assert_true(!DigitalDelivery::authorizeTransfer($r54dEnt($r54dR), 10 * 1048576 + DigitalDelivery::PROGRESS_BYTES + 1, $r54dSize, $r54dLater),
        'R54 resume: a range further past the recorded offset than one progress step is no free resume');
    $r54dResume = DigitalDelivery::openTransfer($r54dEnt($r54dR), 10 * 1048576 + 700 * 1024, $r54dSize, $r54dLater);
    assert_true($r54dResume !== null && (int)$r54dEnt($r54dR)['download_count'] === 1,
        'R54 resume: after a killed transfer the resume from the bytes the client holds is free (was: a new download, refused at limit 1)');
    // A transfer that stopped cleanly recorded its exact offset: a resume past it is no free resume.
    DigitalDelivery::endTransfer($r54dR, (array)$r54dResume, 20 * 1048576);
    assert_true(!DigitalDelivery::authorizeTransfer($r54dEnt($r54dR), 20 * 1048576 + 1, $r54dSize, $r54dLater + 10),
        'R54 resume: after a clean stop a range past the recorded bytes still counts as a new download');
    $r54dDownload = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/download.php');
    assert_true(str_contains($r54dDownload, 'DigitalDelivery::PROGRESS_BYTES') && !str_contains($r54dDownload, '8388608'),
        'R54 resume: download.php records progress every PROGRESS_BYTES (was: 8 MiB)');

    $r54dWorker = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/WorkerJobs.php');
    assert_true((bool)preg_match('#\*/\s*public static function fulfilDigitalOrder\(#', $r54dWorker)
        && !preg_match('#\*/\s*/\*\*\s*\n\s*\* R49 \(review round 19, low\): how long#', $r54dWorker),
        'R54 digital: fulfilDigitalOrder() has its docblock again');
} catch (Throwable $e) {
    assert_true(false, 'R54 digital (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
