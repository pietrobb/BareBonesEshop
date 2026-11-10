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
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + (require AppConfig::path($root)));
// R26: a provider that delivers (the log provider's mail reads as not sent); captured like LogMail.
MailService::useAdapter(new class(['log_file' => $dir . '/emails.log']) extends LogMail { public function adapterId(): string { return 'capture'; } });
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
} elseif ($mode === 'verify_login') {
    $_GET = ['p' => 'login'];
    $_POST = ['action' => 'login', 'email' => $email, 'password' => $password, 'verify_token' => $token, 'csrf_token' => 'a074v'];
} else {
    if ($email !== '') Customers::login($email, $password);
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
        'mails' => $mails, 'notice' => str_contains($out, 'name="action" value="resend_verification"'),
        'verify_form' => str_contains($out, 'name="action" value="login"') && str_contains($out, 'name="verify_token" value="' . ($GLOBALS['token'] ?? '-') . '"'), 'fatal' => str_contains($out, 'Fatal error') || str_contains($out, 'Uncaught')]);
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
    return is_array($result) ? $result + ['raw' => ''] : ['code' => null, 'flash' => null, 'flash_type' => null, 'mails' => [], 'notice' => false, 'verify_form' => false, 'fatal' => true, 'raw' => substr($raw, 0, 600)];
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

    // R19: a re-sent link does not cancel the earlier ones (the first mail's link kept failing after a sign-in attempt).
    assert_true($first !== null && (int)$a074vRead('SELECT COUNT(*) FROM customer_email_verifications WHERE token_hash = ? AND used_at IS NULL', [hash('sha256', $first)]) === 1,
        'R19 re-send: the link from the first mail stays valid after re-sends');

    // Expired link.
    $expired = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO customer_email_verifications (customer_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([$cid, $a074vEmail, hash('sha256', $expired), gmdate('Y-m-d H:i:s', time() - 60)]);
    $r = $a074vRun('verify', $a074vEmail, $a074vPassword, $expired);
    assert_true($r['flash_type'] === 'error' && $a074vVerifiedAt() === null, $a074vLabel('A07.4 verify: an expired link is refused', $r));

    // Bound to the address it was sent to.
    $moved = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO customer_email_verifications (customer_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([$cid, 'a074-old-address@example.com', hash('sha256', $moved), gmdate('Y-m-d H:i:s', time() + 3600)]);
    $r = $a074vRun('verify', $a074vEmail, $a074vPassword, $moved);
    assert_true($r['flash_type'] === 'error' && $a074vVerifiedAt() === null, $a074vLabel('A07.4 verify: a link for another address does not verify the current one', $r));

    // Store-scoped: the same token is not accepted by another store.
    $live = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO customer_email_verifications (customer_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([$cid, $a074vEmail, hash('sha256', $live), gmdate('Y-m-d H:i:s', time() + 3600)]);
    $a074vPreviousStore = StoreContext::current();
    StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), null, null, 'cz'));
    $foreign = Customers::verifyEmail($live, $cid);
    StoreContext::activate($a074vPreviousStore);
    assert_true($foreign === null && $a074vVerifiedAt() === null, 'A07.4 verify: another store cannot consume the link');

    // R14 #1: the link proves the mailbox only. Someone who signed up with this e-mail is not verified (and gets no guest
    // orders) because its owner clicked: the link works only together with that account's sign-in.
    $a074vPending = static fn(): int => (int)$a074vRead('SELECT COUNT(*) FROM customer_email_verifications WHERE token_hash = ? AND used_at IS NULL', [hash('sha256', $live)]);
    $out = $a074vRun('verify', '', '', $live);
    assert_true($out['verify_form'] && $a074vVerifiedAt() === null && $a074vPending() === 1,
        $a074vLabel('R14 #1 verify: signed out, the link shows the sign-in form carrying it and verifies nothing yet', $out));
    $a074vOtherEmail = 'a074-other-' . bin2hex(random_bytes(3)) . '@example.com';
    Customers::register($a074vOtherEmail, 'A074-other-pass', 'A074 Other');
    $other = $a074vRun('verify', $a074vOtherEmail, 'A074-other-pass', $live);
    assert_true($other['flash_type'] === 'error' && $a074vVerifiedAt() === null && $a074vPending() === 1,
        $a074vLabel('R14 #1 verify: signed in to another account, the link verifies nothing and stays unused', $other));
    $wrong = $a074vRun('verify_login', $a074vEmail, 'not-the-password', $live);
    assert_true($wrong['flash_type'] === 'error' && $a074vVerifiedAt() === null && $a074vPending() === 1,
        $a074vLabel('R14 #1 verify: a wrong password with the link verifies nothing', $wrong));
    assert_true(Customers::verifyEmail($live, $cid + 1000) === null && $a074vPending() === 1, 'R14 #1 verify: the token is not consumed for another customer ID');

    $ok = $a074vRun('verify_login', $a074vEmail, $a074vPassword, $live);
    assert_true($a074vVerifiedAt() !== null && $a074vPending() === 0, $a074vLabel('A07.4 / R14 #1 verify: the link with the account\'s sign-in verifies the address', $ok));
    $again = $a074vRun('verify', $a074vEmail, $a074vPassword, $live);
    assert_true($again['flash_type'] === 'error', $a074vLabel('A07.4 verify: the link is single-use', $again));
    assert_true($current !== '' && $latest === hash('sha256', $current), 'A07.4 re-send: the latest mailed link is the newest pending one');

    $done = $a074vRun('dashboard', $a074vEmail, $a074vPassword);
    $after = $a074vRun('resend', $a074vEmail, $a074vPassword);
    assert_true(!$done['notice'] && $after['mails'] === [] && $after['flash_type'] === 'ok', $a074vLabel('A07.4 verification: a verified account gets no notice and no further mail', $after));

    // R19: the customer signs up, tries to sign in before confirming (refused, the link is mailed again) and then opens
    // the link from the FIRST mail: it signs in and confirms the address. The welcome ("you can sign in") comes after.
    $r19Email = 'a074-r19-' . bin2hex(random_bytes(3)) . '@example.com';
    $r19Reg = $a074vRun('register', $r19Email, $a074vPassword);
    $r19First = $a074vToken($r19Reg);
    assert_true($r19First !== null && count($r19Reg['mails']) === 1,
        $a074vLabel('R19 registration: a new account is mailed the confirmation link only, no welcome yet', $r19Reg));
    $r19Early = $a074vRun('verify_login', $r19Email, $a074vPassword, '');
    $r19Second = $a074vToken($r19Early);
    assert_true($r19Early['flash_type'] === 'error' && $r19Second !== null && $r19Second !== $r19First,
        $a074vLabel('R19 sign-in before confirming: refused, and the link is mailed again', $r19Early));
    $r19Done = $a074vRun('verify_login', $r19Email, $a074vPassword, (string)$r19First);
    $r19Verified = $a074vRead('SELECT email_verified_at FROM customers WHERE email = ? AND store_id = ?', [$r19Email, 'default']);
    assert_true($r19Verified !== null && $r19Verified !== false && $r19Done['code'] === 302,
        $a074vLabel('R19 sign-in with the link from the first mail confirms the address and signs in', $r19Done));
    assert_true(count($r19Done['mails']) === 1 && $a074vToken($r19Done) === null && $r19Done['mails'][0]['to'] === $r19Email,
        $a074vLabel('R19 the welcome mail goes out once the address is confirmed', $r19Done));
} finally {
    $a074vTree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a074vRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a074vTree as $a074vPath) $a074vPath->isDir() ? rmdir($a074vPath->getPathname()) : unlink($a074vPath->getPathname());
    rmdir($a074vRoot);
}
