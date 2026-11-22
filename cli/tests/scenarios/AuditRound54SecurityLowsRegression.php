<?php

// Review round 54 (kolo 19), security lows:
//  - Unicode bidi controls (U+202E …) were still accepted in reviews, API v1 product names, RMA, complaints and the CSV
//    import: Security::plainText() strips them as the checkout fields did.
//  - an invoice number whose PDF was set aside before (only *.superseded-*.pdf left) or is a link was issued again.

// --- Bidi controls -------------------------------------------------------------------------------------------------------
assert_equals('Ján gnp.exe', Security::plainText("Ján\u{202E} gnp.exe\u{2066}\u{200F}\u{0085}"),
    'R54 plain text: bidi overrides, isolates, marks and C1 controls are removed');
assert_equals("Riadok 1\nRiadok 2 – ťažký", Security::plainText("Riadok 1\nRiadok 2 – ťažký"), 'R54 plain text: new lines and accented text stay');
$pdo ??= test_commerce_fixture();
$r54lProduct = (int)$pdo->query("SELECT id FROM products WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
[$r54lOk, $r54lReview] = Reviews::submit($r54lProduct, ['rating' => 5, 'author_name' => "Ján\u{202E}kávoN", 'title' => "Super\u{2067}",
    'body' => "Výborný produkt\u{202E}, odporúčam každému."]);
$r54lStored = $r54lOk ? $pdo->query('SELECT author_name, title, body FROM product_reviews WHERE id = ' . (int)$r54lReview)->fetch(PDO::FETCH_ASSOC) : [];
assert_equals(['author_name' => 'JánkávoN', 'title' => 'Super', 'body' => 'Výborný produkt, odporúčam každému.'], $r54lStored,
    'R54 plain text: a review is stored without bidi controls (was: U+202E reversed the name in the admin)');
assert_equals('author_required', Reviews::validationError($r54lProduct, ['rating' => 5, 'author_name' => "\u{202E}\u{2066}", 'body' => 'Výborný produkt, odporúčam.']),
    'R54 plain text: a name of bidi controls only is no name');

// --- Structured log: free text under a key that names no personal data --------------------------------------------------
$r54lRedacted = Logger::redact(['detail' => 'Buyer jan.novak@example.sk, IBAN SK31 1200 0000 1987 4263 7541, tel. +421 905 123 456', 'order_id' => 77]);
assert_true(!str_contains((string)$r54lRedacted['detail'], 'jan.novak') && !str_contains((string)$r54lRedacted['detail'], '1987 4263')
    && !str_contains((string)$r54lRedacted['detail'], '905 123') && $r54lRedacted['order_id'] === 77,
    'R54 log: e-mail, IBAN and phone in free text are masked (' . (string)$r54lRedacted['detail'] . '; was: written as they were)');

// --- An invoice number whose PDF exists is skipped ---------------------------------------------------------------------
Bootstrap::initStoreContext('/', '', 'default');
try {
    $r54lCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r54lWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, vat_class) VALUES
        (98551, 'default', {$r54lCategory}, 'R54L-STD', 'r54l-std', 'simple', 'R54L standard', 1000, 'eur', 5, 'active', 'deny', 'standard')");
    Warehouse::setStock($r54lWarehouse, 'R54L-STD', 5, 'count', 'test', 'r54l-opening');
    $r54lOrder = static function (string $tag) use ($pdo): int {
        $id = Orders::create([['product_id' => 98551, 'variant_id' => null, 'sku' => 'R54L-STD', 'name' => 'R54L ' . $tag, 'qty' => 1, 'price' => 1000, 'attrs' => []]],
            ['name' => 'R54L Buyer', 'email' => 'r54l@example.test', 'phone' => '', 'address' => ['country' => 'SK'], 'shipping_cost' => 0, 'tax_amount' => 0,
                'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
        $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid', shipping_addr = '{\"country\":\"SK\"}' WHERE id = ?")->execute([$id]);
        return $id;
    };
    $r54lFirst = InvoiceService::issue($r54lOrder('A'));
    $r54lPdf = InvoiceService::pdfAbsolutePath($r54lFirst);
    $r54lBefore = (string)@file_get_contents($r54lPdf);
    // An older backup restored: the database no longer knows the invoice, its counter is one lower, the PDF is still there.
    $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([(int)$r54lFirst['id']]);
    $pdo->prepare("UPDATE invoice_number_counters SET last_number = last_number - 1 WHERE type = 'invoice' AND year = ? AND last_number > 0")
        ->execute([(int)substr((string)$r54lFirst['issued_at'], 0, 4)]);
    $r54lSecond = InvoiceService::issue($r54lOrder('B'));
    assert_true((string)$r54lSecond['number'] !== (string)$r54lFirst['number'],
        "R54 invoice numbers: a number whose PDF exists is skipped ({$r54lFirst['number']} → {$r54lSecond['number']}; was: issued again)");
    assert_true($r54lBefore !== '' && (string)@file_get_contents($r54lPdf) === $r54lBefore && (glob(dirname($r54lPdf) . '/*.superseded-*.pdf') ?: []) === [],
        'R54 invoice numbers: the issued PDF stays where it is (was: renamed aside)');
    // The same restore, but the number's PDF was set aside before (only *.superseded-*.pdf is left) or is a link.
    $r54lForget = static function (array $invoice) use ($pdo): void {
        $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([(int)$invoice['id']]);
        $pdo->prepare("UPDATE invoice_number_counters SET last_number = last_number - 1 WHERE type = 'invoice' AND year = ? AND last_number > 0")
            ->execute([(int)substr((string)$invoice['issued_at'], 0, 4)]);
    };
    $r54lSecondPdf = InvoiceService::pdfAbsolutePath($r54lSecond);
    rename($r54lSecondPdf, substr($r54lSecondPdf, 0, -4) . '.superseded-20260101T000000-r54l.pdf');
    $r54lForget($r54lSecond);
    $r54lThird = InvoiceService::issue($r54lOrder('C'));
    assert_true((string)$r54lThird['number'] !== (string)$r54lSecond['number'],
        "R54 invoice numbers: a number whose PDF was set aside is skipped too ({$r54lSecond['number']} → {$r54lThird['number']}; was: issued again)");
    if (PHP_OS_FAMILY !== 'Windows') {
        $r54lThirdPdf = InvoiceService::pdfAbsolutePath($r54lThird);
        unlink($r54lThirdPdf);
        symlink($r54lThirdPdf . '.missing', $r54lThirdPdf);
        $r54lForget($r54lThird);
        $r54lFourth = InvoiceService::issue($r54lOrder('D'));
        assert_true((string)$r54lFourth['number'] !== (string)$r54lThird['number'] && is_link($r54lThirdPdf),
            "R54 invoice numbers: a link in a number's place makes it issued ({$r54lThird['number']} → {$r54lFourth['number']}; was: issued again, the link renamed)");
        @unlink($r54lThirdPdf);
    }
} catch (Throwable $e) {
    assert_true(false, 'R54 invoice numbers (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

// --- VIES: a sole trader's title goes on both sides, with the trade after the name ---------------------------------------
$pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'SK5454%'");
try {
    $r54lCache = $pdo->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES (?, 'SK', 'valid', ?, ?, 86400)");
    $r54lCache->execute(['SK5454545401', 'Ing. Ján Novák - STAVBY', gmdate('Y-m-d H:i:s')]);
    $r54lCache->execute(['SK5454545402', 'Ján Novák', gmdate('Y-m-d H:i:s')]);
    $r54lCache->execute(['SK5454545403', 'Dr. Max Holding', gmdate('Y-m-d H:i:s')]);
    assert_equals([null, null, null, 'name', 'name'], [
        VatService::vatIdHolderMismatch('SK5454545401', 'Ján Novák'),
        VatService::vatIdHolderMismatch('SK5454545402', 'Ing. Ján Novák - STAVBY'),
        VatService::vatIdHolderMismatch('SK5454545401', 'Ing. Ján Novák'),
        VatService::vatIdHolderMismatch('SK5454545403', 'Max Holding'),
        VatService::vatIdHolderMismatch('SK5454545401', 'Peter Novák'),
    ], 'R54 VIES: "Ing. Ján Novák - STAVBY" is the holder Ján Novák either way (was: refused, VAT charged); "Dr. Max Holding" stays the company');
} finally {
    $pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'SK5454%'");
}

// --- Admin re-authentication: concurrent wrong passwords of one session take one slot each, atomically -----------------
$r54lRate = sys_get_temp_dir() . '/r54l-rate-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.sqlite';
try {
    Security::setRateDbForTesting(new PDO('sqlite:' . $r54lRate));
    $r54lAccount = AdminIdentity::create('r54l-reauth-' . bin2hex(random_bytes(3)), 'R54l-correct-password-1', 'viewer', false);
    $r54lSession = 'r54l-session-' . bin2hex(random_bytes(4));
    $r54lSeq = [];
    for ($i = 0; $i < 4; $i++) $r54lSeq[] = AdminIdentity::reauthenticate($r54lAccount, 'wrong-' . $i, $r54lSession);
    $r54lSeq[] = AdminIdentity::reauthenticate($r54lAccount, 'R54l-correct-password-1', $r54lSession);
    assert_equals(['invalid', 'invalid', 'invalid', 'invalid', 'ok'], $r54lSeq, 'R54 re-authentication: four wrong passwords, then the right one still confirms');
    $r54lHits = static fn(): int => (int)(new PDO('sqlite:' . $r54lRate))->query('SELECT COUNT(*) FROM hits')->fetchColumn();
    assert_equals(4, $r54lHits(), 'R54 re-authentication: the right password takes no slot');
    $r54lFile = (string)($pdo->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'] ?? '');
    if (Db::isSqlite() && $r54lFile !== '' && function_exists('pcntl_fork')) {
        // Eight requests of the session with the fifth slot left, all at once (the file-session lock aside).
        $r54lStart = microtime(true) + 0.5;
        $r54lPids = [];
        for ($i = 0; $i < 8; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                Db::reset();
                $child = new PDO('sqlite:' . $r54lFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
                Db::init($child, 'sqlite');
                $rate = new PDO('sqlite:' . $r54lRate, null, null, [PDO::ATTR_TIMEOUT => 10]);
                Security::setRateDbForTesting($rate);
                time_nanosleep(0, max(0, (int)(($r54lStart - microtime(true)) * 1e9)));
                AdminIdentity::reauthenticate($r54lAccount, 'race-' . $i, $r54lSession);
                posix_kill(getmypid(), SIGKILL); // no shutdown handlers or destructors of the parent's state
            }
            $r54lPids[] = $pid;
        }
        foreach ($r54lPids as $pid) pcntl_waitpid($pid, $status);
        assert_equals(5, $r54lHits(),
            'R54 re-authentication: of eight concurrent wrong passwords only one is checked (was: all passed a count of four and guessed)');
    }
} catch (Throwable $e) {
    assert_true(false, 'R54 re-authentication (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    @unlink($r54lRate);
}
