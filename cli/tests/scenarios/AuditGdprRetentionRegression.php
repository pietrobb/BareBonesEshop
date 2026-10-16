<?php

// Code review 2026-10-15 H7: every retention policy in config/data-retention.json names columns that exist,
// so gdpr-gc --apply works once rows pass their TTL, and audit_log IP addresses are erased after 730 days.
echo "\n--- Code review H7: GDPR retention policies ---\n";
[$h7SavedDb, $h7SavedDriver] = [Db::get(), Db::driver()];
$h7Lock = sys_get_temp_dir() . '/bbe-h7-' . getmypid() . '.lock';
$h7Db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
try {
    (new Migrator($h7Db, TEST_CLI_DIR . '/../migrations', $h7Lock))->apply(static fn($m) => null);
    Db::init($h7Db, 'sqlite');
    $h7Policies = json_decode((string)file_get_contents(TEST_CLI_DIR . '/../config/data-retention.json'), true)['policies'];
    $h7Log = $h7Db->prepare("INSERT INTO audit_log (entity, entity_id, action, old_value, new_value, note, actor, ip_address, created_at) VALUES ('customer', 7, 'update', ?, ?, ?, 'admin', ?, ?)");
    $h7Log->execute(['{"email":"old@example.test"}', '{"email":"new@example.test"}', 'Jana Novakova asked', '203.0.113.7', '2000-01-01 00:00:00']);
    $h7Old = (int)$h7Db->lastInsertId();
    $h7Log->execute(['{"email":"a@example.test"}', '{"email":"b@example.test"}', 'recent', '198.51.100.9', gmdate('Y-m-d H:i:s')]);
    $h7New = (int)$h7Db->lastInsertId();

    $h7Errors = [];
    foreach (['dry-run', 'apply'] as $h7Mode) {
        foreach ($h7Policies as $h7Key => $h7Policy) {
            try {
                GdprService::applyRetention((string)$h7Key, (array)$h7Policy, $h7Mode);
            } catch (Throwable $e) {
                $h7Errors[] = "{$h7Mode} {$h7Key}: " . $e->getMessage();
            }
        }
    }
    assert_equals([], $h7Errors, 'H7: every retention policy runs in dry-run and apply on the migrated schema');
    $h7Row = static fn(int $id): array => $h7Db->query("SELECT action, actor, ip_address, old_value, new_value, note FROM audit_log WHERE id = {$id}")->fetch();
    $h7Scrubbed = $h7Row($h7Old);
    assert_true(str_starts_with((string)$h7Scrubbed['ip_address'], '[erased') && str_starts_with((string)$h7Scrubbed['note'], '[erased')
        && !str_contains(json_encode($h7Scrubbed), '@example.test'), 'H7: audit_log IP address and change payload older than 730 days are erased');
    assert_equals(['update', 'admin'], [$h7Scrubbed['action'], $h7Scrubbed['actor']], 'H7: scrubbed audit entry keeps action and actor');
    assert_equals('198.51.100.9', $h7Row($h7New)['ip_address'], 'H7: recent audit entries are untouched');

    $h7Bad = '';
    try {
        GdprService::applyRetention('bad', ['table' => 'audit_log', 'ttl_days' => 730, 'action' => 'scrub_pii', 'scrub_fields' => ['payload_json']], 'dry-run');
    } catch (InvalidArgumentException $e) {
        $h7Bad = $e->getMessage();
    }
    assert_true(str_contains($h7Bad, 'payload_json'), 'H7: a policy naming a missing column fails already in dry-run');
} catch (Throwable $e) {
    assert_true(false, 'GDPR retention regression raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    Db::init($h7SavedDb, $h7SavedDriver);
    @unlink($h7Lock);
}
