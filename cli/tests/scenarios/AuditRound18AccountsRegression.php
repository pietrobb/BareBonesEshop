<?php

// Review round 18 (2026-11-06), medium "account enumeration": a sign-up could be signed in at once with the password
// the attacker had just chosen, so a successful sign-in after "registering" an address told that it had had no
// account; the taken path also answered faster (no password hash, other mails). Low: per-IP rate limits counted each
// IPv6 address, while one client gets a whole /64.
echo "\n--- R18: registration does not reveal accounts, rate limits per IPv6 /64 ---\n";

$r18aDb = Db::get();
foreach ($GLOBALS as $r18aValue) if ($r18aValue instanceof PDOStatement) $r18aValue->closeCursor();
(require TEST_CLI_DIR . '/../migrations/20261012210000_customer_email_verification.php')->up($r18aDb, true);
$r18aSession = $_SESSION ?? [];
$r18aEmail = 'r18-accounts-' . bin2hex(random_bytes(3)) . '@example.test';
$r18aPassword = 'R18a-chosen-pass';
try {
    [$r18aOk, $r18aId] = Customers::register($r18aEmail, $r18aPassword, 'R18 Accounts');
    $r18aId = (int)$r18aId;
    $_SESSION = [];
    $r18aUnverified = Customers::login($r18aEmail, $r18aPassword, true);
    assert_equals([true, false, 'email_unverified', $r18aId, false], [$r18aOk, $r18aUnverified[0], $r18aUnverified[2], (int)($r18aUnverified[1]['id'] ?? 0), isset($_SESSION['customer_id'])],
        'R18 accounts: the sign-in form does not sign in an account whose e-mail is not confirmed (no session is set)');
    $r18aWrong = Customers::login($r18aEmail, 'R18a-wrong-pass', true);
    assert_equals([false, null, 'invalid_credentials'], $r18aWrong, 'R18 accounts: a wrong password still fails as before (the form shows both the same way)');
    assert_true(Customers::login($r18aEmail, $r18aPassword)[0] === true, 'R18 accounts: callers that proved the address another way (claim link) still sign in');
    Customers::logout();

    $r18aToken = Customers::startEmailVerification($r18aId);
    assert_true(is_string($r18aToken) && Customers::login($r18aEmail, $r18aPassword, true, str_repeat('0', 64))[2] === 'email_unverified'
        && Customers::login($r18aEmail, $r18aPassword, true, (string)$r18aToken)[0] === true && Customers::verifyEmail((string)$r18aToken, $r18aId) === $r18aId,
        'R18 accounts: with its own verification link the account signs in, and the link still verifies it afterwards');
    Customers::logout();
    assert_true(Customers::login($r18aEmail, $r18aPassword, true)[0] === true, 'R18 accounts: a confirmed account signs in with the password alone');
    Customers::logout();

    // A password reset came by e-mail: it confirms the address (an account never confirmed could not sign in otherwise).
    $r18aDb->prepare('UPDATE customers SET email_verified_at = NULL WHERE id = ?')->execute([$r18aId]);
    $r18aReset = bin2hex(random_bytes(32));
    $r18aDb->prepare('INSERT INTO password_reset_tokens (customer_id, token, expires_at) VALUES (?, ?, ?)')
        ->execute([$r18aId, hash('sha256', $r18aReset), gmdate('Y-m-d H:i:s', time() + 3600)]);
    [$r18aResetOk] = Customers::completePasswordReset($r18aReset, 'R18a-reset-pass');
    assert_true($r18aResetOk && Customers::login($r18aEmail, 'R18a-reset-pass', true)[0] === true, 'R18 accounts: a completed password reset confirms the e-mail address');
    Customers::logout();

    // Timing: the taken address hashes the password like a new account (it returned before password_hash()).
    $r18aTime = static function (string $email): float { $start = hrtime(true); Customers::register($email, 'R18a-timing-pass', 'T'); return (hrtime(true) - $start) / 1e9; };
    $r18aTaken = min($r18aTime($r18aEmail), $r18aTime($r18aEmail));
    $r18aFresh = $r18aTime('r18-accounts-fresh-' . bin2hex(random_bytes(3)) . '@example.test');
    assert_true($r18aTaken >= 0.5 * $r18aFresh, sprintf('R18 accounts: registering a taken address takes as long as a new account (%.3f s vs %.3f s)', $r18aTaken, $r18aFresh));

    $r18aDeferred = [];
    WebWorker::defer(static function () use (&$r18aDeferred): void { $r18aDeferred[] = 'ran'; });
    $r18aAccount = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Storefront/Account.php');
    assert_true($r18aDeferred === ['ran'] && str_contains($r18aAccount, 'Customers::login($email, $password, true, $verifyToken)')
        && substr_count($r18aAccount, 'WebWorker::defer(') === 3 && !str_contains($r18aAccount, "\n                MailService::send('account_welcome'"),
        'R18 accounts: the sign-in form requires a confirmed e-mail, and the account mails go out after the response (deferred; at once on the CLI)');
} finally {
    $_SESSION = $r18aSession;
    $r18aDb->prepare("DELETE FROM customers WHERE email LIKE 'r18-accounts-%@example.test'")->execute();
}

// IPv6: one subject per /64 (an address inside it is free to the client), IPv4 and IPv4-mapped addresses as they are.
assert_equals(['2001:db8:1:2::/64', '2001:db8:1:2::/64', '2001:db8:1:3::/64', '203.0.113.9', '203.0.113.9', ''],
    [Security::rateLimitSubject('2001:db8:1:2:aaaa::1'), Security::rateLimitSubject('2001:DB8:1:2:ffff:ffff:ffff:fffe'), Security::rateLimitSubject('2001:db8:1:3::1'),
     Security::rateLimitSubject('203.0.113.9'), Security::rateLimitSubject('::ffff:203.0.113.9'), Security::rateLimitSubject('')],
    'R18 rate limits: IPv6 addresses count per /64');
$r18aServer = $_SERVER['REMOTE_ADDR'] ?? null;
try {
    $r18aLimited = [];
    $r18aKey = 'r18a_v6_' . bin2hex(random_bytes(4));
    foreach (['2001:db8:77:1::1', '2001:db8:77:1::2', '2001:db8:77:1:abcd::3', '2001:db8:77:2::1'] as $r18aIp) {
        $_SERVER['REMOTE_ADDR'] = $r18aIp;
        $r18aLimited[] = Security::rateLimit($r18aKey, 2, 60);
    }
    assert_equals([true, true, false, true], $r18aLimited, 'R18 rate limits: rotating addresses inside one /64 shares its limit, another /64 has its own');
    $r18aSources = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Storefront/Account.php') . file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Storefront/GuestPages.php');
    assert_true(!str_contains($r18aSources, 'md5(Security::clientIp())') && substr_count($r18aSources, 'md5(Security::rateLimitSubject())') === 3,
        'R18 rate limits: the per-IP keys of registration, data export and the account offer use the /64 subject');
} finally {
    if ($r18aServer === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $r18aServer;
}
