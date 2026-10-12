<?php

echo "\n--- Audit A04: UTC machine timestamps with local PHP timezone ---\n";
$a04OriginalDb = Db::get();
$a04OriginalDriver = Db::driver();
$a04OriginalZone = date_default_timezone_get();
$a04OriginalStore = StoreContext::current();
$a04Root = test_fixture_path('utc-contract');
mkdir($a04Root, 0700, true);
$a04Checks = static function(PDO $db, string $driver): void {
    Db::init($db, $driver);
    if ($driver === 'mysql') {
        $db->exec("SET SESSION time_zone='+02:00'");
        Db::init($db, $driver);
        assert_equals('+00:00', $db->query('SELECT @@session.time_zone')->fetchColumn(), 'A04 native: injected connections enforce UTC session');
        assert_true(abs(time() - (int)$db->query('SELECT UNIX_TIMESTAMP(NOW())')->fetchColumn()) < 5, 'A04 native: SQL clock matches epoch');
        assert_equals(gmdate('Y-m-d H:i'), substr((string)$db->query('SELECT NOW()')->fetchColumn(), 0, 16), 'A04 native: SQL NOW uses UTC wall clock');
    }
    Bootstrap::initStoreContext('/', '', 'default');
    $accountId = AdminIdentity::create('a04-owner', 'a04-test-password-only', 'owner', false);
    $db->exec("INSERT INTO customers (store_id, email, password_hash, name, status) VALUES ('default', 'a04-reset@example.test', '!test!', 'UTC Reset', 'active')");
    $customerId = (int)$db->lastInsertId();
    foreach (['UTC', 'Europe/Bratislava', 'Pacific/Honolulu'] as $zone) {
        date_default_timezone_set($zone);
        $label = "A04 {$driver}/{$zone}";
        $db->prepare('UPDATE admin_accounts SET locked_until=NULL WHERE id=?')->execute([$accountId]);
        $session = AdminIdentity::authenticate('a04-owner', 'a04-test-password-only', '', '127.0.0.1', $zone);
        assert_true(is_array($session), "{$label}: actual login creates session");
        assert_true(AdminIdentity::session($session['token']) !== null, "{$label}: fresh session survives idle validation");
        $db->prepare('UPDATE admin_sessions SET expires_at=?, last_seen_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() + 60), gmdate('Y-m-d H:i:s'), $session['session_id']]);
        assert_true(AdminIdentity::session($session['token']) !== null, "{$label}: session expiring in one minute is valid");
        $db->prepare('UPDATE admin_sessions SET expires_at=?, revoked_at=NULL WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() - 1), $session['session_id']]);
        assert_equals(null, AdminIdentity::session($session['token']), "{$label}: expired session rejected");
        $db->prepare('UPDATE admin_sessions SET expires_at=?, last_seen_at=?, revoked_at=NULL WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() + 3600), gmdate('Y-m-d H:i:s', time() - 86401), $session['session_id']]);
        assert_equals(null, AdminIdentity::session($session['token']), "{$label}: idle session rejected");
        $db->prepare('UPDATE admin_accounts SET locked_until=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() + 60), $accountId]);
        assert_equals(null, AdminIdentity::authenticate('a04-owner', 'a04-test-password-only', '', '127.0.0.1', $zone), "{$label}: UTC MFA lock is enforced even with correct password");
        $db->prepare('UPDATE admin_accounts SET locked_until=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() - 1), $accountId]);
        assert_true(AdminIdentity::authenticate('a04-owner', 'a04-test-password-only', '', '127.0.0.1', $zone) !== null, "{$label}: expired MFA lock releases login");

        $token = Customers::startPasswordReset('a04-reset@example.test');
        assert_true(is_string($token), "{$label}: actual reset issues token");
        assert_equals($customerId, Customers::verifyResetToken($token), "{$label}: fresh reset token is valid");
        foreach ([60 => $customerId, -1 => null, 0 => null] as $seconds => $expected) {
            $db->prepare('UPDATE password_reset_tokens SET expires_at=? WHERE token=?')->execute([gmdate('Y-m-d H:i:s', time() + $seconds), hash('sha256', $token)]);
            assert_equals($expected, Customers::verifyResetToken($token), "{$label}: reset expiry {$seconds} seconds");
        }
        $db->prepare('UPDATE password_reset_tokens SET expires_at=? WHERE token=?')->execute([gmdate('Y-m-d H:i:s', time() - 1), hash('sha256', $token)]);
        assert_equals([false, 'invalid_or_expired_token'], Customers::completePasswordReset($token, 'a04-new-password'), "{$label}: expired reset cannot change password");
        $db->prepare('UPDATE password_reset_tokens SET expires_at=? WHERE token=?')->execute([gmdate('Y-m-d H:i:s', time() + 60), hash('sha256', $token)]);
        assert_equals([true, null], Customers::completePasswordReset($token, 'a04-new-password'), "{$label}: near-expiry reset succeeds");
        assert_equals(null, Customers::verifyResetToken($token), "{$label}: consumed reset rejected");

        $client = ApiAuth::issueClient('A04 ' . $zone, 'default', ['catalog:read'], gmdate('Y-m-d H:i:s', time() + 60));
        try { $authenticated = ApiAuth::authenticate('Bearer ' . $client['token']); } catch (ApiAuthException $e) { $authenticated = null; }
        assert_equals($client['id'], $authenticated['id'] ?? null, "{$label}: near-expiry bearer is valid");
        foreach ([-1, 0] as $seconds) {
            $db->prepare('UPDATE api_clients SET expires_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() + $seconds), $client['id']]);
            $rejected = false;
            try { ApiAuth::authenticate('Bearer ' . $client['token']); } catch (ApiAuthException $e) { $rejected = true; }
            assert_true($rejected, "{$label}: bearer expiry {$seconds} seconds rejected");
        }

        $db->beginTransaction();
        $eventId = Outbox::record('a04.test', 'customer', $customerId, []);
        $db->commit();
        $event = Outbox::claim($eventId);
        assert_true(is_array($event), "{$label}: UTC outbox immediately claimable");
        foreach (['2026-03-29 01:30:00', '2026-10-25 01:30:00'] as $date) {
            $event['occurred_at'] = $date;
            assert_equals(str_replace(' ', 'T', $date) . '+00:00', Outbox::envelope($event)['time'], "{$label}: DST envelope preserves UTC {$date}");
        }
        Outbox::markRetry($eventId, 3, 'fixture retry');
        assert_equals(null, Outbox::claim($eventId), "{$label}: future UTC retry is not claimed early");
        $db->prepare('UPDATE event_outbox SET available_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() - 1), $eventId]);
        assert_true(Outbox::claim($eventId) !== null, "{$label}: due UTC retry is claimable");
        Outbox::markDelivered($eventId);
        assert_true(!JobQueue::isReady(['retry_after' => gmdate('c', time() + 60)]), "{$label}: file queue ISO retry is not early");
        assert_true(JobQueue::isReady(['retry_after' => gmdate('c', time() - 1)]), "{$label}: file queue ISO retry becomes due");
        $queue = new DatabaseQueue($db);
        $jobId = $queue->push('a04_test', []);
        $job = $queue->pull(1)[0];
        assert_true(JobQueue::isReady($job), "{$label}: fresh hydrated database job is ready");
        $claim = $queue->markProcessing($job);
        $queue->fail($claim, $job, 'a04 retry');
        $retryAt = $db->query('SELECT available_at FROM queue_jobs WHERE id=' . $db->quote($jobId))->fetchColumn();
        assert_true(!JobQueue::isReady(['retry_after' => $retryAt]), "{$label}: persisted database retry is not early");
        assert_equals([], $queue->pull(1), "{$label}: future retry excluded by SQL queue");
        $db->prepare('UPDATE queue_jobs SET available_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() - 1), $jobId]);
        $job = $queue->pull(1)[0];
        assert_true(JobQueue::isReady($job), "{$label}: due hydrated database retry is ready");
        $queue->complete($queue->markProcessing($job));

        $validator = new class implements VatIdValidator {
            public function check(string $vatId): array { return ['status' => 'unverified', 'country' => 'SK', 'trader_name' => null, 'trader_addr' => null, 'raw' => null]; }
        };
        $db->exec("DELETE FROM vat_id_cache WHERE vat_id='SK1234567890'");
        VatService::validateVatId('SK1234567890', $validator);
        $checkedAt = $db->query("SELECT checked_at FROM vat_id_cache WHERE vat_id='SK1234567890'")->fetchColumn();
        assert_true(abs(time() - strtotime($checkedAt . ' UTC')) < 5, "{$label}: VAT validation writes UTC");
        date_default_timezone_set('UTC');
        $cachedVat = VatService::validateVatId('SK1234567890', $validator);
        assert_true($cachedVat['cached'] && $cachedVat['age_seconds'] >= 0 && $cachedVat['age_seconds'] < 5, "{$label}: VAT cache age survives timezone change");
        date_default_timezone_set($zone);
        $db->prepare("UPDATE vat_id_cache SET checked_at=? WHERE vat_id='SK1234567890'")->execute([gmdate('Y-m-d H:i:s', time() - 3601)]);
        assert_true(!VatService::validateVatId('SK1234567890', $validator)['cached'], "{$label}: expired UTC VAT cache revalidated");
        $isDue = new ReflectionMethod(FeedImport::class, 'isDue');
        $lastSync = $db->query('SELECT ' . Db::now())->fetchColumn();
        assert_true(!$isDue->invoke(null, '* * * * *', $lastSync), "{$label}: feed synced now is not due again");
        assert_true($isDue->invoke(null, '* * * * *', gmdate('Y-m-d H:i:s', time() - 120)), "{$label}: feed synced two minutes ago is due");

        foreach (['2030-06-01 12:00:00' => '2030-06-01 12:00:00', '2030-06-01T12:00:00+02:00' => '2030-06-01 10:00:00'] as $input => $expected) {
            $issued = ApiAuth::issueClient('A04 normalized', 'default', ['catalog:read'], $input);
            assert_equals($expected, $db->query('SELECT expires_at FROM api_clients WHERE id=' . $issued['id'])->fetchColumn(), "{$label}: API expiry normalized to UTC {$input}");
        }
        foreach (['not a timestamp', '', '   '] as $invalidInput) {
            $invalid = false;
            try { ApiAuth::issueClient('A04 invalid', 'default', ['catalog:read'], $invalidInput); } catch (InvalidArgumentException $e) { $invalid = true; }
            assert_true($invalid, "{$label}: invalid API expiry rejected " . var_export($invalidInput, true));
        }
        assert_equals($zone, date_default_timezone_get(), "{$label}: services preserve display timezone");
    }
};
try {
    $a04Db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    Db::init($a04Db, 'sqlite');
    (new Migrator($a04Db, dirname(TEST_CLI_DIR) . '/migrations', $a04Root . '/sqlite.lock'))->apply();
    $a04Checks($a04Db, 'sqlite');
    if (getenv('TEST_MYSQL_DSN')) {
        $a04Native = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $a04Database = 'bbe_utc_' . bin2hex(random_bytes(6)) . '_test';
        $a04Native->exec("CREATE DATABASE {$a04Database}");
        try {
            $a04Native->exec("USE {$a04Database}");
            Db::init($a04Native, 'mysql');
            (new Migrator($a04Native, dirname(TEST_CLI_DIR) . '/migrations', $a04Root . '/native.lock'))->apply();
            $a04Checks($a04Native, 'mysql');
        } finally {
            $a04Native->exec("DROP DATABASE {$a04Database}");
            $a04Native = null;
        }
    } else {
        echo "  [SKIP] Audit A04 native UTC contract (set TEST_MYSQL_DSN with disposable create-database permission)\n";
    }
} finally {
    date_default_timezone_set($a04OriginalZone);
    StoreContext::activate($a04OriginalStore);
    Db::init($a04OriginalDb, $a04OriginalDriver);
    $a04Db = null;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a04Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $a04File) {
        $a04File->isDir() ? rmdir($a04File->getPathname()) : unlink($a04File->getPathname());
    }
    rmdir($a04Root);
}
