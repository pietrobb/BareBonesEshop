<?php

// Review round 20 (2026-11-07), low "accounts": every further verification link of an account already confirmed
// "verified" it again and sent another welcome e-mail. Only the link that confirms the address sends it.
echo "\n--- R20: a further verification link of a confirmed account sends no second welcome ---\n";

$r20vDb = Db::get();
foreach ($GLOBALS as $r20vValue) if ($r20vValue instanceof PDOStatement) $r20vValue->closeCursor();
(require TEST_CLI_DIR . '/../migrations/20261012210000_customer_email_verification.php')->up($r20vDb, true);
$r20vSession = $_SESSION ?? [];
$r20vEmail = 'r20-verify-' . bin2hex(random_bytes(3)) . '@example.test';
try {
    [, $r20vId] = Customers::register($r20vEmail, 'R20v-chosen-pass', 'R20 Verify');
    $r20vId = (int)$r20vId;
    $r20vFirst = (string)Customers::startEmailVerification($r20vId);
    $r20vSecond = (string)Customers::startEmailVerification($r20vId);
    $r20vWelcomes = static fn(): int => count(array_filter(LogMail::captured(), static fn(array $m): bool => ($m['to'] ?? '') === $r20vEmail));
    $r20vVerifiedAt = static function () use ($r20vDb, $r20vId): ?string {
        $stmt = $r20vDb->prepare('SELECT email_verified_at FROM customers WHERE id = ?');
        $stmt->execute([$r20vId]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (string)$value;
    };
    LogMail::clearCaptured();
    $r20vDone = AccountViewModel::verifyEmailFlash($r20vFirst, $r20vId);
    $r20vAt = $r20vVerifiedAt();
    assert_equals(['ok', 1, true], [$r20vDone['type'], $r20vWelcomes(), $r20vAt !== null],
        'R20 verify: the link that confirms the address sends the welcome once');
    $r20vAgain = AccountViewModel::verifyEmailFlash($r20vSecond, $r20vId);
    assert_equals(['ok', 1, $r20vAt], [$r20vAgain['type'], $r20vWelcomes(), $r20vVerifiedAt()],
        'R20 verify: a further link of the confirmed account sends no second welcome (was: welcome again)');
    $r20vFlag = true;
    assert_true(Customers::verifyEmail(str_repeat('a', 64), $r20vId, $r20vFlag) === null && $r20vFlag === false,
        'R20 verify: an unknown link confirms nothing');
} catch (Throwable $e) {
    assert_true(false, 'R20 verify (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r20vDb->prepare('DELETE FROM customer_email_verifications WHERE customer_id IN (SELECT id FROM customers WHERE email = ?)')->execute([$r20vEmail]);
    $r20vDb->prepare('DELETE FROM customers WHERE email = ?')->execute([$r20vEmail]);
    $_SESSION = $r20vSession;
    LogMail::clearCaptured();
}
