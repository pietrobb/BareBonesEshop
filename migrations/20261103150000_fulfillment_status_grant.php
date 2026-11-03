<?php
/**
 * R13 moved order status changes of the fulfillment role from orders.edit to the new orders.status. A fulfillment
 * account whose store grant names permissions explicitly (["orders.view", "orders.edit", …] instead of ["*"]) was
 * granted orders.edit for exactly that work, and after the upgrade it could no longer move orders along: such a grant
 * gets orders.status. orders.edit stays in the grant but does nothing for the role, which no longer holds it.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $exists = static function (string $table) use ($db, $isSqlite): bool {
            $stmt = $isSqlite
                ? $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?")
                : $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
            return $stmt->fetchColumn() !== false;
        };
        if (!$exists('admin_accounts') || !$exists('admin_store_permissions')) return;
        $grants = $db->query("SELECT p.id, p.permissions FROM admin_store_permissions p
            JOIN admin_accounts a ON a.id = p.account_id WHERE a.role = 'fulfillment'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $update = $db->prepare('UPDATE admin_store_permissions SET permissions = :permissions WHERE id = :id');
        foreach ($grants as $id => $json) {
            $permissions = json_decode((string)$json, true);
            if (!is_array($permissions) || !in_array('orders.edit', $permissions, true)) continue;
            if (array_intersect(['*', 'orders.*', 'orders.status'], $permissions) !== []) continue;
            $permissions[] = 'orders.status';
            $update->execute([':permissions' => json_encode(array_values($permissions)), ':id' => $id]);
        }
    }
};
