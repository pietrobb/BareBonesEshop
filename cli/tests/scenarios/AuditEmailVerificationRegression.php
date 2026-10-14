<?php

// A07.4 / suggestion: customer e-mail verification — hashed single-use token, expiry, bound to the address, throttled re-send.
echo "\n--- Audit A07.4: customer e-mail verification ---\n";
foreach ($GLOBALS as $a074vValue) if ($a074vValue instanceof PDOStatement) $a074vValue->closeCursor();
$a074vMigration = require TEST_CLI_DIR . '/../migrations/20261012210000_customer_email_verification.php';
$a074vMigration->up($pdo, true);
$a074vMigration->up($pdo, true);

$a074vRoot = test_fixture_path('email-verification');
mkdir($a074vRoot);
mkdir($a074vRoot . '/sessions');
file_put_contents($a074vRoot . '/account.php', <<<'PHP'
<?php
[, $root, $dbPath, $dir, $mode, $email, $password, $token] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate.sqlite'));
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + (require $root . '/config.php'));
MailService::useAdapter(new LogMail(['log_file' => $dir . '/emails.log']));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = ['csrf_token' => 'a074v', 'form_trap_tokens_' . md5('account_register') => ['a074vtrap' => time() - 10]];
$_SERVER['REQUEST_URI'] = '/account.php';
$_SERVER['REMOTE_ADDR'] = '127.0.7.44';
$_SERVER['REQUEST_METHOD'] = 'POST';
if ($mode === 'register') {
    $_GET = ['p' => 'register'];
    $_POST = ['action' => 'register', 'email' => $email, 'password' => $password, 'name' => 'A074 Verify', 'register_token' => 'a074vtrap', 'csrf_token' => 'a074v'];
} elseif ($mode === 'resend') {
    Customers::login($email, $password);
    $_GET = [];
    $_POST = ['action' => 'resend_verification', 'csrf_token' => $_SESSION['csrf_token']];
} elseif ($mode === 'dashboard') {
    Customers::login($email, $password);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $_POST = [];
} else {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['p' => 'verify_email', 'token' => $token];
    $_POST = [];
}
http_response_code(200);
ob_start();
register_shutdown_function(static function(): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    $mails = array_map(static fn(array $m): array => ['to' => $m['to'], 'subject' => $m['subject'] ?? '', 'text' => $m['text']], LogMail::captured());
    echo "\nA074VRESULT" . json_encode(['code' => http_response_code(), 'flash' => $GLOBALS['flash'] ?? null, 'flash_type' => $GLOBALS['flashType'] ?? null,
        'mails' => $mails, 'notice' => str_contains($out, 'name="action" value="resend_verification"'), 'fatal' => str_contains($out, 'Fatal error') || str_contains($out, 'Uncaught')]);
});
require $root . '/account.php';
PHP);
$a074vRun = static function(string $mode, string $email = '', string $password = '', string $token = '') use ($a074vRoot, $testDbPath): array {
    $process = proc_open([PHP_BINARY, $a074vRoot . '/account.php', dirname(TEST_CLI_DIR), $testDbPath, $a074vRoot, $mode, $email, $password, $token],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $at = strrpos($raw, 'A074VRESULT');
    $result = $at === false ? null : json_decode(substr($raw, $at + 11), true);
    return is_array($result) ? $result + ['raw' => ''] : ['code' => null, 'flash' => null, 'flash_type' => null, 'mails' => [], 'notice' => false, 'fatal' => true, 'raw' => substr($raw, 0, 600)];
};
$a074vRead = static function(string $sql, array $args = []) use ($testDbPath) {
    $read = new PDO('sqlite:' . $testDbPath);
    $stmt = $read->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchColumn();
};
$a074vToken = static function(array $r): ?string {
    foreach ($r['mails'] as $mail) {
        if (preg_match('~https://shop\.example/account\.php\?p=verify_email&token=([a-f0-9]{64})(?![A-Za-z0-9])~', (string)$mail['text'], $m)) return $m[1];
    }
    return null;
};
$a074vLabel = static fn(string $label, array $r): string => $label . ' (code=' . var_export($r['code'], true) . ' flash=' . json_encode($r['flash'])
    . ' mails=' . count($r['mails']) . ($r['raw'] !== '' ? ' raw=' . $r['raw'] : '') . ')';
$a074vEmail = 'a074-verify@example.com';
$a074vPassword = 'A074-verify-pass';
$a074vVerifiedAt = static fn() => $a074vRead('SELECT email_verified_at FROM customers WHERE email = ? AND store_id = ?', [$a074vEmail, 'default']);

try {
    $reg = $a074vRun('register', $a074vEmail, $a074vPassword);
    $first = $a074vToken($reg);
    $cid = (int)$a074vRead('SELECT id FROM customers WHERE email = ?', [$a074vEmail]);
    assert_true($cid > 0 && $first !== null && $a074vVerifiedAt() === null, $a074vLabel('A07.4 verification: registration e-mails a verification link and the address starts unverified', $reg));
    assert_true($first !== null && (int)$a074vRead('SELECT COUNT(*) FROM customer_email_verifications WHERE customer_id = ? AND token_hash = ?', [$cid, hash('sha256', $first)]) === 1
        && (int)$a074vRead('SELECT COUNT(*) FROM customer_email_verifications WHERE token_hash = ?', [$first]) === 0,
        'A07.4 verification: only the SHA-256 of the token is stored');
    $logged = (string)$a074vRead("SELECT GROUP_CONCAT(payload) FROM email_log WHERE template = 'account_email_verification' AND recipient = ?", [$a074vEmail]);
    assert_true($logged !== '' && $first !== null && !str_contains($logged, $first) && !str_contains($logged, 'token='), 'A07.4 verification: email_log keeps no usable verification link (' . $logged . ')');

    $dash = $a074vRun('dashboard', $a074vEmail, $a074vPassword);
    assert_true($dash['notice'] && !$dash['fatal'], $a074vLabel('A07.4 verification: the account dashboard offers a re-send while unverified', $dash));

    $resend = $a074vRun('resend', $a074vEmail, $a074vPassword);
    $second = $a074vToken($resend);
    assert_true($second !== null && $second !== $first && $resend['flash_type'] === 'ok', $a074vLabel('A07.4 re-send: a new link is mailed', $resend));
    $current = (string)($a074vToken($a074vRun('resend', $a074vEmail, $a074vPassword)) ?? '');
    $throttled = $a074vRun('resend', $a074vEmail, $a074vPassword);
    assert_true($throttled['mails'] === [] && $throttled['flash_type'] === 'error', $a074vLabel('A07.4 re-send: more than 3 links per hour are throttled', $throttled));
    $latest = (string)$a074vRead('SELECT token_hash FROM customer_email_verifications WHERE customer_id = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1', [$cid]);

    $old = $a074vRun('verify', '', '', (string)$first);
    assert_true($old['flash_type'] === 'error' && $a074vVerifiedAt() === null, $a074vLabel('A07.4 verify: a superseded link no longer verifies', $old));

    // Expired link.
    $expired = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO customer_email_verifications (customer_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([$cid, $a074vEmail, hash('sha256', $expired), gmdate('Y-m-d H:i:s', time() - 60)]);
    $r = $a074vRun('verify', '', '', $expired);
    assert_true($r['flash_type'] === 'error' && $a074vVerifiedAt() === null, $a074vLabel('A07.4 verify: an expired link is refused', $r));

    // Bound to the address it was sent to.
    $moved = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO customer_email_verifications (customer_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([$cid, 'a074-old-address@example.com', hash('sha256', $moved), gmdate('Y-m-d H:i:s', time() + 3600)]);
    $r = $a074vRun('verify', '', '', $moved);
    assert_true($r['flash_type'] === 'error' && $a074vVerifiedAt() === null, $a074vLabel('A07.4 verify: a link for another address does not verify the current one', $r));

    // Store-scoped: the same token is not accepted by another store.
    $live = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO customer_email_verifications (customer_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([$cid, $a074vEmail, hash('sha256', $live), gmdate('Y-m-d H:i:s', time() + 3600)]);
    $a074vPreviousStore = StoreContext::current();
    StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), null, null, 'cz'));
    $foreign = Customers::verifyEmail($live);
    StoreContext::activate($a074vPreviousStore);
    assert_true($foreign === null && $a074vVerifiedAt() === null, 'A07.4 verify: another store cannot consume the link');

    $ok = $a074vRun('verify', '', '', $live);
    assert_true($ok['flash_type'] === 'ok' && $a074vVerifiedAt() !== null, $a074vLabel('A07.4 verify: a valid link verifies the address', $ok));
    $again = $a074vRun('verify', '', '', $live);
    assert_true($again['flash_type'] === 'error', $a074vLabel('A07.4 verify: the link is single-use', $again));
    assert_true($current !== '' && $latest === hash('sha256', $current), 'A07.4 re-send: the latest mailed link is the only pending one');

    $done = $a074vRun('dashboard', $a074vEmail, $a074vPassword);
    $after = $a074vRun('resend', $a074vEmail, $a074vPassword);
    assert_true(!$done['notice'] && $after['mails'] === [] && $after['flash_type'] === 'ok', $a074vLabel('A07.4 verification: a verified account gets no notice and no further mail', $after));
} finally {
    $a074vTree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a074vRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a074vTree as $a074vPath) $a074vPath->isDir() ? rmdir($a074vPath->getPathname()) : unlink($a074vPath->getPathname());
    rmdir($a074vRoot);
}
