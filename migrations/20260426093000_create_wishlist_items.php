<?php
/**
 * Migration: create_wishlist_items
 * Generated: 2026-04-26T09:30:00+00:00
 *
 * Per-owner saved products (wishlist / favourites). The owner is either a
 * logged-in customer ("c:<id>") or an anonymous session ("s:<session_id>"),
 * encoded in owner_key so a single UNIQUE(owner_key, product_id) prevents
 * duplicate entries for both cases (SQLite treats NULLs as distinct, which
 * would defeat a nullable-column UNIQUE — owner_key sidesteps that).
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $autoinc = $isSqlite ? '' : ' AUTO_INCREMENT';
        $now     = $isSqlite ? "datetime('now')" : "NOW()";
        $short   = MigrationSql::short($isSqlite);
        $date    = MigrationSql::datetime($isSqlite);

        $db->exec("CREATE TABLE IF NOT EXISTS wishlist_items (
            id          INTEGER PRIMARY KEY{$autoinc},
            owner_key   {$short} NOT NULL,
            customer_id INTEGER DEFAULT NULL,
            product_id  INTEGER NOT NULL,
            created_at  {$date} NOT NULL DEFAULT ({$now}),
            UNIQUE(owner_key, product_id)
        )");
        MigrationSql::index($db, $isSqlite, 'wishlist_items', 'idx_wishlist_owner', 'owner_key');
        MigrationSql::index($db, $isSqlite, 'wishlist_items', 'idx_wishlist_product', 'product_id');
        MigrationSql::index($db, $isSqlite, 'wishlist_items', 'idx_wishlist_customer', 'customer_id');
    }
};
