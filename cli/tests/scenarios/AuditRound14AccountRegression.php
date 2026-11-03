<?php
/**
 * Round 14 (review "kolo 6", 2026-11-03), PR "accounts and admin": a fulfillment account whose store grant listed
 * orders.edit explicitly lost status changes when R13 moved them to orders.status (no migration). The guest-order
 * takeover through the verification link (#1), the per-session re-auth limit, store removal leftovers and the bulk
 * status list are covered next to their earlier tests (AuditEmailVerificationRegression, AuditRound13Ux/AdminRegression).
 */

echo "\n--- Audit R14: fulfillment grants keep status changes after the orders.status split ---\n";

$r14aDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$r14aDb->exec("CREATE TABLE admin_accounts (id INTEGER PRIMARY KEY, username TEXT, role TEXT)");
$r14aDb->exec("CREATE TABLE admin_store_permissions (id INTEGER PRIMARY KEY, account_id INTEGER, store_id TEXT, permissions TEXT)");
$r14aDb->exec("INSERT INTO admin_accounts (id, username, role) VALUES (1, 'packer', 'fulfillment'), (2, 'boss', 'manager'), (3, 'all', 'fulfillment'), (4, 'viewer', 'fulfillment')");
$r14aDb->exec("INSERT INTO admin_store_permissions (id, account_id, store_id, permissions) VALUES
    (1, 1, 'default', '[\"orders.view\",\"orders.edit\",\"orders.ship\"]'),
    (2, 1, 'cz', '[\"orders.view\",\"orders.edit\"]'),
    (3, 2, 'default', '[\"orders.view\",\"orders.edit\"]'),
    (4, 3, 'default', '[\"*\"]'),
    (5, 4, 'default', '[\"orders.view\"]')");
$r14aMigration = require TEST_CLI_DIR . '/../migrations/20261103150000_fulfillment_status_grant.php';
$r14aMigration->up($r14aDb, true);
$r14aMigration->up($r14aDb, true);
$r14aGrant = static fn(int $id): array => json_decode((string)$r14aDb->query('SELECT permissions FROM admin_store_permissions WHERE id = ' . $id)->fetchColumn(), true);
assert_equals(['orders.view', 'orders.edit', 'orders.ship', 'orders.status'], $r14aGrant(1), 'R14: a fulfillment grant naming orders.edit gets orders.status (idempotent)');
assert_equals(['orders.view', 'orders.edit', 'orders.status'], $r14aGrant(2), 'R14: in every store it was granted for');
assert_equals([['orders.view', 'orders.edit'], ['*'], ['orders.view']], [$r14aGrant(3), $r14aGrant(4), $r14aGrant(5)],
    'R14: other roles, wildcard grants and grants without orders.edit stay as they were');
$r14aGranted = AdminIdentity::storeAllows(['default' => $r14aGrant(1)], 'default', 'orders.status');
assert_true($r14aGranted, 'R14: the migrated grant allows orders.status');
