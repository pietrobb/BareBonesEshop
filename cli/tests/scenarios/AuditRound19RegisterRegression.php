<?php

// Review round 19 (2026-11-06), medium "registration loop": the page after sign-up and the welcome mail said "you can sign
// in now", the sign-in was refused until the address was confirmed, and every attempt mailed a new link that cancelled
// the earlier one (the first mail's link then failed). Low: a throttled mail sent after the response logged
// "headers already sent"; the forgot-password answer took longer for an address with an account.
echo "\n--- R19: registration confirms first, earlier links stay valid, no late headers, forgot answers alike ---\n";

$r19rRoot = dirname(TEST_CLI_DIR);
$r19rDb = Db::get();
foreach ($GLOBALS as $r19rValue) if ($r19rValue instanceof PDOStatement) $r19rValue->closeCursor();
(require $r19rRoot . '/migrations/20261012210000_customer_email_verification.php')->up($r19rDb, true);

// 1. Up to five links stay valid together; the oldest beyond that go.
$r19rEmail = 'r19-register-' . bin2hex(random_bytes(3)) . '@example.test';
try {
    [, $r19rId] = Customers::register($r19rEmail, 'R19r-register-pass', 'R19 Register');
    $r19rId = (int)$r19rId;
    $r19rTokens = [];
    for ($r19rI = 0; $r19rI < 7; $r19rI++) $r19rTokens[] = (string)Customers::startEmailVerification($r19rId);
    $r19rPending = static function (string $token) use ($r19rDb): bool {
        $find = $r19rDb->prepare('SELECT COUNT(*) FROM customer_email_verifications WHERE token_hash = ? AND used_at IS NULL');
        $find->execute([hash('sha256', $token)]);
        return (int)$find->fetchColumn() === 1;
    };
    assert_equals([false, false, true, true, true, true, true], array_map($r19rPending, $r19rTokens),
        'R19 verification: the five newest links stay valid together, older ones are dropped');
    assert_true(Customers::verifyEmail($r19rTokens[2], $r19rId) === $r19rId, 'R19 verification: an earlier link still confirms the address');
} finally {
    $r19rDb->prepare("DELETE FROM customer_email_verifications WHERE customer_id IN (SELECT id FROM customers WHERE email LIKE 'r19-register-%@example.test')")->execute();
    $r19rDb->prepare("DELETE FROM customers WHERE email LIKE 'r19-register-%@example.test'")->execute();
}

// 2. The page after sign-up and the welcome mail do not promise a sign-in before the address is confirmed.
$r19rOldFlash = ['hneď prihlásiť', 'hned přihlásit', 'sign in now', 'jetzt mit Ihrem Passwort anmelden', 'ya puede iniciar sesión',
    'máris bejelentkezhet', 'od razu zalogować'];
$r19rOldWelcome = ['bol vytvorený', 'byl vytvořen', 'has been created', 'wurde erstellt', 'Se ha creado', 'létrejött', 'zostało utworzone'];
foreach (['sk', 'cs', 'en', 'de', 'es', 'hu', 'pl'] as $r19rLang) {
    $r19rStrings = require $r19rRoot . "/lang/{$r19rLang}.php";
    $r19rFlash = (string)($r19rStrings['account.register_check_email'] ?? '');
    $r19rWelcome = (string)file_get_contents($r19rRoot . "/templates/emails/{$r19rLang}/account_welcome.html");
    assert_true(str_contains($r19rFlash, '{email}') && array_filter($r19rOldFlash, static fn(string $s): bool => str_contains($r19rFlash, $s)) === []
        && array_filter($r19rOldWelcome, static fn(string $s): bool => str_contains($r19rWelcome, $s)) === [],
        "R19 registration ({$r19rLang}): the page and the welcome mail ask to confirm the address first");
}

// 3. A limit hit after the response (a deferred, throttled mail) sets no headers: they are long sent.
$r19rLate = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-r', '
    require $argv[1] . "/src/autoload.php";
    Security::setRateDbForTesting(new PDO("sqlite::memory:"));
    echo "response sent\n";
    flush();
    echo Security::rateLimit("r19r_late", 0, 60, false) ? "allowed" : "limited";
', $r19rRoot], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r19rPipes);
fclose($r19rPipes[0]);
$r19rOut = stream_get_contents($r19rPipes[1]) . stream_get_contents($r19rPipes[2]);
fclose($r19rPipes[1]);
fclose($r19rPipes[2]);
proc_close($r19rLate);
assert_true(str_contains($r19rOut, 'limited') && !str_contains($r19rOut, 'headers already sent'),
    'R19 rate limit: over the limit after the response, no "headers already sent" warning (' . trim($r19rOut) . ')');

// 4. Forgot password: the reset is sent after the response, so the answer does not depend on the account.
$r19rAccount = (string)file_get_contents($r19rRoot . '/src/Entrypoints/Storefront/Account.php');
assert_true(str_contains($r19rAccount, 'WebWorker::defer(static fn() => Customers::forgotPassword($email, $locale, $mailConfig))')
    && !preg_match('/\n\s+Customers::forgotPassword\(/', $r19rAccount),
    'R19 forgot password: the reset mail goes out after the response (deferred), never inline');
