<?php

// A07.2 / findings 19, 20: forgot-password is generic and really delivers a working link; mail logs keep no live secrets.
echo "\n--- Audit A07.2: password reset delivery and mail-log secrets ---\n";
// Same WAL-snapshot guard as A07.1: the child processes below commit through their own connections.
foreach ($GLOBALS as $a072Value) if ($a072Value instanceof PDOStatement) $a072Value->closeCursor();
$a072Hash = password_hash('a072-password', PASSWORD_BCRYPT, ['cost' => 4]);
$a072Customer = $pdo->prepare("INSERT INTO customers (email, password_hash, name, locale, status, store_id) VALUES (?, ?, 'A072', ?, 'active', ?)");
foreach ([['a072-blank@example.com', 'en', 'default'], ['a072-sent@example.com', 'en', 'default'], ['a072-cz@example.com', 'cs', 'cz']] as [$a072Email, $a072Locale, $a072Store]) {
    $a072Customer->execute([$a072Email, $a072Hash, $a072Locale, $a072Store]);
}

$a072Root = test_fixture_path('password-reset');
mkdir($a072Root);
mkdir($a072Root . '/sessions');
file_put_contents($a072Root . '/account.php', <<<'PHP'
<?php
[, $root, $dbPath, $dir, $appUrl, $uri, $email] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => $appUrl] + (require $root . '/config.php'));
MailService::useAdapter(new LogMail(['log_file' => $dir . '/emails.log']));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = ['csrf_token' => 'a072'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = $uri;
$_SERVER['REMOTE_ADDR'] = '127.0.7.3';
$_GET = ['p' => 'forgot'];
$_POST = ['action' => 'forgot', 'email' => $email, 'csrf_token' => 'a072'];
http_response_code(200);
ob_start();
register_shutdown_function(static function(): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    $mails = array_map(static fn(array $m): array => ['to' => $m['to'], 'html' => $m['html'], 'text' => $m['text']], LogMail::captured());
    echo "\nA072RESULT" . json_encode(['code' => http_response_code(), 'flash' => $GLOBALS['flash'] ?? null,
        'flash_type' => $GLOBALS['flashType'] ?? null, 'mails' => $mails, 'fatal' => str_contains($out, 'Fatal error') || str_contains($out, 'Uncaught')]);
});
require $root . '/account.php';
PHP);
$a072Forgot = static function(string $appUrl, string $uri, string $email) use ($a072Root, $testDbPath): array {
    $process = proc_open([PHP_BINARY, $a072Root . '/account.php', dirname(TEST_CLI_DIR), $testDbPath, $a072Root, $appUrl, $uri, $email],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $at = strrpos($raw, 'A072RESULT');
    $result = $at === false ? null : json_decode(substr($raw, $at + 10), true);
    return is_array($result) ? $result + ['raw' => ''] : ['code' => null, 'flash' => null, 'flash_type' => null, 'mails' => [], 'fatal' => true, 'raw' => substr($raw, 0, 400)];
};
$a072Read = static function(string $sql, array $args = []) use ($testDbPath) {
    $read = new PDO('sqlite:' . $testDbPath);
    $stmt = $read->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchColumn();
};
$a072Label = static fn(string $label, array $r): string => $label . ' (code=' . var_export($r['code'], true) . ' flash=' . json_encode($r['flash'])
    . ' mails=' . count($r['mails']) . ($r['raw'] !== '' ? ' raw=' . $r['raw'] : '') . ')';
$a072Generic = static fn(array $r): bool => $r['code'] === 200 && !$r['fatal'] && $r['flash_type'] === 'ok' && is_string($r['flash']) && $r['flash'] !== '';
$a072Token = static function(array $r, string $prefix): ?string {
    $text = (string)($r['mails'][0]['text'] ?? '');
    return preg_match('~' . preg_quote($prefix, '~') . '([a-f0-9]{64})(?![A-Za-z0-9])~', $text, $m) ? $m[1] : null;
};

try {
    // Finding 19: with the shipped default app_url => '' a registered address must look exactly like an unknown one.
    $known = $a072Forgot('', '/account.php', 'a072-blank@example.com');
    $unknown = $a072Forgot('', '/account.php', 'a072-nobody@example.com');
    assert_true($a072Generic($known), $a072Label('A07.2 finding 19: empty app_url + registered email returns the generic result, no 500', $known));
    assert_true($a072Generic($unknown) && $unknown['flash'] === $known['flash'] && $unknown['code'] === $known['code'],
        $a072Label('A07.2 finding 19: registered and unknown email are indistinguishable', $unknown));
    assert_true($known['mails'] === [] && (int)$a072Read("SELECT COUNT(*) FROM password_reset_tokens t JOIN customers c ON c.id = t.customer_id WHERE c.email = 'a072-blank@example.com'") === 0,
        'A07.2 finding 19: without a trusted app_url no reset token is issued and nothing is mailed');

    // Configured app_url: the reset e-mail really leaves through the adapter and its link works (plain text too).
    $sent = $a072Forgot('https://shop.example/base', '/account.php', 'a072-sent@example.com');
    $sentToken = $a072Token($sent, 'https://shop.example/base/account.php?p=reset&token=');
    assert_true($a072Generic($sent) && count($sent['mails']) === 1 && $sent['mails'][0]['to'] === 'a072-sent@example.com' && $sentToken !== null,
        $a072Label('A07.2 finding 19: reset e-mail is sent with an exact plain-text link', $sent));
    $sentCustomer = (int)$a072Read("SELECT id FROM customers WHERE email = 'a072-sent@example.com' AND store_id = 'default'");
    assert_true($sentToken !== null && Customers::verifyResetToken($sentToken) === $sentCustomer, 'A07.2 finding 19: the mailed token resets that account');
    $ghost = $a072Forgot('https://shop.example/base', '/account.php', 'a072-ghost@example.com');
    assert_true($a072Generic($ghost) && $ghost['mails'] === [] && $ghost['flash'] === $sent['flash'], $a072Label('A07.2 finding 19: unknown email with app_url stays generic and silent', $ghost));

    // Prefixed store: the link must reopen the same store, otherwise the store-scoped token is rejected.
    $cz = $a072Forgot('https://shop.example', '/cs/account.php', 'a072-cz@example.com');
    $czToken = $a072Token($cz, 'https://shop.example/cs/account.php?p=reset&token=');
    $a072PreviousStore = StoreContext::current();
    StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), null, null, 'cz'));
    $czValid = $czToken !== null && Customers::verifyResetToken($czToken) === (int)$a072Read("SELECT id FROM customers WHERE email = 'a072-cz@example.com'");
    StoreContext::activate($a072PreviousStore);
    assert_true($a072Generic($cz) && $czValid, $a072Label('A07.2 finding 19: prefixed store reset link keeps the /cs store and works', $cz));

    // Finding 20: email_log must not hold a usable reset URL or token.
    $payloads = [];
    $logRows = (new PDO('sqlite:' . $testDbPath))->query("SELECT id, payload FROM email_log WHERE template = 'account_password_reset' AND recipient IN ('a072-sent@example.com', 'a072-cz@example.com')")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($logRows as $payload) $payloads[] = (string)$payload;
    $leaks = array_filter($payloads, static fn(string $p): bool => str_contains($p, 'token=') || ($sentToken !== null && str_contains($p, $sentToken)) || ($czToken !== null && str_contains($p, $czToken)));
    assert_true(count($payloads) === 2 && $leaks === [], 'A07.2 finding 20: email_log.payload keeps no reset URL/token (' . json_encode($payloads) . ')');
    $resend = MailService::resend((int)array_key_first($logRows));
    assert_true(($resend['status'] ?? '') === 'skipped', 'A07.2 finding 20: a redacted secret-link mail cannot be resent from the log (' . json_encode($resend) . ')');

    // Same root cause for the other bearer links: digital downloads (hashed in DB) and cart recovery links.
    LogMail::clearCaptured();
    MailService::useAdapter(new LogMail(['log_file' => $a072Root . '/emails.log']));
    $download = MailService::send('digital_delivery', 'a072-digital@example.com', 'en', ['order_number' => 'A072', 'customer_name' => 'A072',
        'download_links' => [['label' => 'Manual', 'url' => 'https://shop.example/download.php?token=a072secretdownload', 'validity' => 'no expiry']]]);
    $cart = MailService::send('cart_reminder', 'a072-cart@example.com', 'en', ['cart_url' => 'https://shop.example/?cart_recover=a072secretcart',
        'unsubscribe_url' => 'https://shop.example/?cart_unsubscribe=a072secretcart', 'cart_total' => '1 €', 'items' => []]);
    $delivered = implode("\n", array_map(static fn(array $m): string => $m['html'] . $m['text'], LogMail::captured()));
    $stored = (string)$a072Read('SELECT GROUP_CONCAT(payload) FROM email_log WHERE id IN (?, ?)', [(int)$download['log_id'], (int)$cart['log_id']]);
    assert_true(str_contains($delivered, 'a072secretdownload') && str_contains($delivered, 'a072secretcart'), 'A07.2: download and cart links are still delivered');
    assert_true($stored !== '' && !str_contains($stored, 'a072secret'), 'A07.2: download and cart-recovery tokens are not kept in email_log (' . $stored . ')');
} finally {
    MailService::useAdapter(null);
    LogMail::clearCaptured();
    $a072Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a072Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a072Tree as $a072Path) $a072Path->isDir() ? rmdir($a072Path->getPathname()) : unlink($a072Path->getPathname());
    rmdir($a072Root);
}
