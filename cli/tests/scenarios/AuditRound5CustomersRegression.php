<?php

// Audit round 5 — customers, e-mails, GDPR: an e-mail is unique per store (an account in one store no longer locks the
// address in every other store, so nobody can squat a foreign address there); reset and verification links of a store on
// its own domain use that domain; a new reset request does not cancel the owner's open link; an erasure is sent on to
// webhook subscribers as customer.erased; a guest (orders without an account) can be exported and erased.
echo "\n--- Audit round 5: customers, e-mails, GDPR ---\n";
Bootstrap::initStoreContext('/', '', 'default');
$r5cDb = Db::get();
$r5cDir = test_fixture_path('r5-customers');
mkdir($r5cDir);

// --- Migration: (store_id, email) instead of a global UNIQUE(email) -----------------------------------------------
$r5cMigration = require TEST_CLI_DIR . '/../migrations/20261019120000_customer_email_per_store.php';
$r5cOld = new PDO('sqlite:' . $r5cDir . '/old.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r5cOld->exec('PRAGMA foreign_keys = ON');
$r5cOld->exec("CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'active', store_id TEXT NOT NULL DEFAULT 'default')");
$r5cOld->exec('CREATE INDEX idx_cust_email ON customers(email)');
$r5cOld->exec('CREATE TABLE customer_addresses (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL, city TEXT,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE)');
$r5cOld->exec('CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL, token TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, used_at TEXT,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE)');
$r5cOld->exec("INSERT INTO customers (email, password_hash, store_id) VALUES ('r5c-owner@example.test', 'x', 'default')");
$r5cOld->exec("INSERT INTO customer_addresses (customer_id, city) VALUES (1, 'Bratislava')");
$r5cOld->exec("INSERT INTO password_reset_tokens (customer_id, token, expires_at) VALUES (1, 'r5c-token', '2999-01-01 00:00:00')");
$r5cOld->beginTransaction(); // the Migrator wraps each SQLite migration in a transaction
$r5cMigration->up($r5cOld, true);
$r5cOld->commit();
$r5cMigration->up($r5cOld, true);
$r5cOldCount = static fn(string $sql): int => (int)$r5cOld->query($sql)->fetchColumn();
assert_true($r5cOldCount('SELECT COUNT(*) FROM customer_addresses') === 1 && $r5cOldCount('SELECT COUNT(*) FROM password_reset_tokens') === 1,
    'R5 customers migration: rebuilding customers keeps the addresses and reset tokens (no ON DELETE CASCADE)');
assert_equals(1, (int)$r5cOld->query('PRAGMA foreign_keys')->fetchColumn(), 'R5 customers migration: foreign keys are switched back on');
assert_true($r5cOld->query('PRAGMA foreign_key_check')->fetchAll() === [] && $r5cOldCount("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='idx_cust_email'") === 1,
    'R5 customers migration: references and secondary indexes survive');
$r5cOld->exec("INSERT INTO customers (email, password_hash, store_id) VALUES ('r5c-owner@example.test', 'y', 'cz')");
$r5cDuplicate = false;
try { $r5cOld->exec("INSERT INTO customers (email, password_hash, store_id) VALUES ('r5c-owner@example.test', 'z', 'cz')"); } catch (PDOException $e) { $r5cDuplicate = true; }
assert_true($r5cDuplicate && $r5cOldCount("SELECT COUNT(*) FROM customers WHERE email = 'r5c-owner@example.test'") === 2,
    'R5 customers migration: one address per store, the same address in another store is allowed (idempotent rerun)');
$r5cOld = $r5cOldCount = null;

// --- Registration and login per store ----------------------------------------------------------------------------
$r5cStore = static function (string $id): void { StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), null, null, $id)); };
$r5cStore('cz');
[$r5cSquatOk, $r5cSquatId] = Customers::register('r5c-victim@example.test', 'Squatter-pass-1', 'Squatter');
$r5cStore('default');
[$r5cVictimOk, $r5cVictimId, $r5cVictimErr] = Customers::register('r5c-victim@example.test', 'Victim-pass-1', 'Victim');
assert_true($r5cSquatOk && $r5cVictimOk && $r5cVictimId !== $r5cSquatId,
    'R5 customers: an address registered in another store does not block it here (' . var_export($r5cVictimErr, true) . ')');
assert_equals('email_taken', Customers::register('r5c-victim@example.test', 'Another-pass-1')[2], 'R5 customers: the same store still rejects a duplicate');
[$r5cLoginOk, $r5cLogged] = Customers::login('r5c-victim@example.test', 'Victim-pass-1');
assert_true($r5cLoginOk && (int)$r5cLogged['id'] === $r5cVictimId, 'R5 customers: login opens the account of the current store');
Customers::logout();
$r5cStore('cz');
assert_true(Customers::login('r5c-victim@example.test', 'Victim-pass-1')[0] === false && Customers::login('r5c-victim@example.test', 'Squatter-pass-1')[0] === true,
    "R5 customers: the other store's account keeps its own password");
Customers::logout();
$r5cStore('default');

// --- A reset request does not cancel the owner's open link -------------------------------------------------------
$r5cOwnerLink = Customers::startPasswordReset('r5c-victim@example.test');
$r5cAttacker = [];
for ($i = 0; $i < 6; $i++) $r5cAttacker[] = Customers::startPasswordReset('r5c-victim@example.test');
assert_true($r5cOwnerLink !== null && Customers::verifyResetToken($r5cOwnerLink) === $r5cVictimId,
    'R5 reset: requests by someone else keep the link the owner already has');
assert_true(count(array_filter($r5cAttacker)) === 4 && end($r5cAttacker) === null && Customers::verifyResetToken($r5cOwnerLink) === $r5cVictimId,
    'R5 reset: at most 5 open links; further requests are dropped, the oldest link is never evicted');
[$r5cResetOk] = Customers::completePasswordReset((string)$r5cOwnerLink, 'Owner-new-pass-1');
assert_true($r5cResetOk && Customers::verifyResetToken((string)$r5cAttacker[0]) === null
    && (int)$r5cDb->query("SELECT COUNT(*) FROM password_reset_tokens WHERE customer_id = {$r5cVictimId} AND used_at IS NULL")->fetchColumn() === 0,
    'R5 reset: setting the password closes every other open link of the account');

// --- Links of a store on its own domain --------------------------------------------------------------------------
$r5cStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r5cSavedStores = $r5cStoresProp->getValue();
$r5cDomainStores = Bootstrap::stores();
$r5cDomainStores['stores']['pl']['domains'] = ['r5c-pl.example'];
$r5cDomainStores['stores']['pl']['url_prefix'] = '';
MailService::useAdapter(new LogMail(['log_file' => $r5cDir . '/emails.log']));
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
try {
    $r5cStoresProp->setValue(null, $r5cDomainStores);
    $r5cStore('pl');
    [, $r5cPlId] = Customers::register('r5c-domain@example.test', 'Domain-pass-1', 'Domain');
    $r5cCfg = ['app_url' => 'https://r5c-main.example/base'];
    $r5cLink = static function (string $outcome, string $param): ?string {
        $text = implode("\n", array_map(static fn(array $m): string => (string)$m['text'], LogMail::captured()));
        LogMail::clearCaptured();
        return $outcome === 'sent' && preg_match('~(https?://[^\s"<>]+\?p=' . $param . '&token=[a-f0-9]{64})~', $text, $m) ? $m[1] : null;
    };
    LogMail::clearCaptured();
    $r5cResetLink = $r5cLink(Customers::requestPasswordReset('r5c-domain@example.test', 'en', $r5cCfg), 'reset');
    $r5cVerifyOutcome = Customers::requestEmailVerification((int)$r5cPlId, 'en', $r5cCfg);
    $r5cVerifyLink = $r5cLink($r5cVerifyOutcome, 'verify_email') ?? $r5cVerifyOutcome;
    foreach (['reset' => $r5cResetLink, 'verification' => $r5cVerifyLink] as $r5cKind => $r5cUrl) {
        $r5cHost = (string)parse_url((string)$r5cUrl, PHP_URL_HOST);
        $r5cResolved = StoreContext::resolve($r5cDomainStores, (string)parse_url((string)$r5cUrl, PHP_URL_PATH), $r5cHost)->id();
        assert_true($r5cHost === 'r5c-pl.example' && str_starts_with((string)$r5cUrl, 'https://r5c-pl.example/base/') && $r5cResolved === 'pl',
            "R5 links: the {$r5cKind} link of a store on its own domain opens that store ({$r5cUrl} → {$r5cResolved})");
    }
    parse_str((string)parse_url((string)$r5cResetLink, PHP_URL_QUERY), $r5cQuery);
    assert_equals((int)$r5cPlId, Customers::verifyResetToken((string)($r5cQuery['token'] ?? '')), 'R5 links: the mailed reset token works in that store');
    $r5cStore('cz');
    LogMail::clearCaptured();
    $r5cCzLink = $r5cLink(Customers::requestPasswordReset('r5c-victim@example.test', 'en', $r5cCfg), 'reset');
    assert_true(str_starts_with((string)$r5cCzLink, 'https://r5c-main.example/base/cs/account.php?p=reset&token='), "R5 links: a store without a domain keeps the app_url and its prefix ({$r5cCzLink})");
} finally {
    $r5cStoresProp->setValue(null, $r5cSavedStores);
    MailService::useAdapter(null);
    LogMail::clearCaptured();
    $r5cStore('default');
}

// --- GDPR: erasure reaches webhook subscribers; guests can be exported and erased ------------------------------------
$r5cGdpr = static function (PDO $db, array $orderTemplate, string $label): void {
    $suffix = bin2hex(random_bytes(4));
    $order = static function (?int $customerId, string $email) use ($db, $orderTemplate, $suffix): int {
        $row = array_replace($orderTemplate, ['order_number' => 'R5C-' . bin2hex(random_bytes(4)), 'customer_id' => $customerId, 'customer_email' => $email, 'store_id' => 'default']);
        $db->prepare('INSERT INTO orders (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        $id = (int)$db->lastInsertId();
        $db->beginTransaction();
        Outbox::record(DomainEvents::ORDER_CREATED, 'order', $id, ['order_id' => $id, 'customer_email' => $email]);
        $db->commit();
        return $id;
    };
    $count = static function (string $sql, array $params = []) use ($db): int {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    };
    $erasedEvent = static function (string $aggregateId) use ($db): ?array {
        $stmt = $db->prepare("SELECT payload FROM event_outbox WHERE event_type = 'customer.erased' AND aggregate_type = 'customer' AND aggregate_id = ? ORDER BY occurred_at DESC LIMIT 1");
        $stmt->execute([$aggregateId]);
        $payload = $stmt->fetchColumn();
        return $payload === false ? null : json_decode((string)$payload, true);
    };

    $email = "r5c-member-{$suffix}@example.test";
    [$ok, $memberId] = Customers::register($email, 'Member-pass-1', 'Member');
    $memberOrder = $order((int)$memberId, $email);
    GdprService::anonymizeCustomer((int)$memberId, 'r5c-test');
    $event = $erasedEvent((string)$memberId);
    assert_true($ok && is_array($event) && (int)$event['customer_id'] === (int)$memberId && in_array($memberOrder, $event['order_ids'], true) && $event['guest'] === false,
        "R5 GDPR {$label}: an erasure records customer.erased for webhook subscribers (" . json_encode($event) . ')');
    assert_true(!str_contains(json_encode($event), $email) && Webhook::endpointAccepts(['events' => ['customer.*']], DomainEvents::CUSTOMER_ERASED)
        && in_array(DomainEvents::CUSTOMER_ERASED, DomainEvents::all(), true), "R5 GDPR {$label}: customer.erased is a public event without the e-mail");

    $guest = "r5c-guest-{$suffix}@example.test";
    $otherGuest = "r5c-other-{$suffix}@example.test";
    $guestOrder = $order(null, $guest);
    $otherOrder = $order(null, $otherGuest);
    foreach ([$guest, $otherGuest] as $mail) {
        $db->prepare("INSERT INTO abandoned_carts (session_id, customer_email, cart_data, store_id) VALUES (?, ?, '{\"items\":[]}', 'default')")->execute(['r5c-' . bin2hex(random_bytes(6)), $mail]);
        $db->prepare("INSERT INTO email_log (store_id, order_id, recipient, template, subject, payload) VALUES ('default', ?, ?, 'order_created', 'Your order', '{}')")
            ->execute([$mail === $guest ? $guestOrder : $otherOrder, $mail]);
    }
    $unknown = false;
    try { GdprService::exportGuest("r5c-nobody-{$suffix}@example.test"); } catch (RuntimeException $e) { $unknown = true; }
    assert_true($unknown, "R5 GDPR {$label}: an address without guest data is reported as not found");
    $export = GdprService::exportGuest(strtoupper($guest));
    $exportedOrders = array_map(static fn(array $o): int => (int)$o['order']['id'], $export['orders']);
    assert_true($exportedOrders === [$guestOrder] && $export['customer'] === null && count($export['abandoned_carts']) === 1 && count($export['email_log']) === 1
        && ($export['meta']['guest_email'] ?? '') === $guest, "R5 GDPR {$label}: a guest export holds exactly the guest's orders, cart and mail (" . json_encode($exportedOrders) . ')');

    $result = GdprService::anonymizeGuest($guest, 'r5c-test');
    assert_true(($result['status'] ?? '') === 'anonymized'
        && $count('SELECT COUNT(*) FROM abandoned_carts WHERE LOWER(customer_email) = LOWER(?)', [$guest]) === 0
        && $count('SELECT COUNT(*) FROM email_log WHERE LOWER(recipient) = LOWER(?)', [$guest]) === 0
        && $count('SELECT COUNT(*) FROM event_outbox WHERE INSTR(payload, ?) > 0', [$guest]) === 0,
        "R5 GDPR {$label}: a guest erasure removes the cart and scrubs the mail log and event payloads");
    assert_true($count('SELECT COUNT(*) FROM orders WHERE id = ?', [$guestOrder]) === 1
        && $count('SELECT COUNT(*) FROM abandoned_carts WHERE customer_email = ?', [$otherGuest]) === 1
        && $count('SELECT COUNT(*) FROM email_log WHERE recipient = ?', [$otherGuest]) === 1
        && $count('SELECT COUNT(*) FROM event_outbox WHERE INSTR(payload, ?) > 0', [$otherGuest]) === 1,
        "R5 GDPR {$label}: the order stays (retention) and another guest's data is untouched");
    $guestEvent = $erasedEvent('guest');
    assert_true(is_array($guestEvent) && $guestEvent['guest'] === true && $guestEvent['customer_id'] === null && $guestEvent['order_ids'] === [$guestOrder],
        "R5 GDPR {$label}: a guest erasure is sent on as customer.erased with its orders (" . json_encode($guestEvent) . ')');
};
$r5cTemplate = $r5cDb->query("SELECT * FROM orders WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
unset($r5cTemplate['id']);
$r5cGdpr($r5cDb, $r5cTemplate, 'SQLite');

if (getenv('TEST_MYSQL_DSN')) {
    $r5cNative = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $r5cNativeDatabase = 'bbe_r5c_' . bin2hex(random_bytes(6)) . '_test';
    $r5cNativeLock = test_fixture_path('r5-customers-native') . '.lock';
    $r5cNative->exec("CREATE DATABASE {$r5cNativeDatabase} CHARACTER SET utf8mb4");
    try {
        $r5cNative->exec("USE {$r5cNativeDatabase}");
        Db::init($r5cNative, 'mysql');
        (new Migrator($r5cNative, dirname(TEST_CLI_DIR) . '/migrations', $r5cNativeLock))->apply();
        $r5cMigration->up($r5cNative, false);
        $r5cNative->exec("INSERT INTO customers (store_id, email, password_hash) VALUES ('default', 'r5c-native@example.test', 'x'), ('cz', 'r5c-native@example.test', 'x')");
        $r5cNativeDuplicate = false;
        try { $r5cNative->exec("INSERT INTO customers (store_id, email, password_hash) VALUES ('cz', 'r5c-native@example.test', 'x')"); } catch (PDOException $e) { $r5cNativeDuplicate = true; }
        assert_true($r5cNativeDuplicate, 'R5 customers native: the migrated schema keeps one address per store and allows it in another store');
        $r5cGdpr($r5cNative, ['customer_name' => 'R5C', 'payment_method' => 'card', 'subtotal' => 1000, 'total' => 1000], 'native');
    } finally {
        Db::init($r5cDb, 'sqlite');
        $r5cNative->exec("DROP DATABASE IF EXISTS {$r5cNativeDatabase}");
        $r5cNative = null;
        @unlink($r5cNativeLock);
    }
} else {
    echo "  [SKIP] R5 customers native MySQL/MariaDB (set TEST_MYSQL_DSN)\n";
}

$r5cTree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r5cDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($r5cTree as $r5cPath) $r5cPath->isDir() ? rmdir($r5cPath->getPathname()) : unlink($r5cPath->getPathname());
rmdir($r5cDir);
