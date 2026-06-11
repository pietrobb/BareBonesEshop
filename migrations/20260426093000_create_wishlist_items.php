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

        $db->exec("CREATE TABLE IF NOT EXISTS wishlist_items (
            id          INTEGER PRIMARY KEY{$autoinc},
            owner_key   TEXT NOT NULL,
            customer_id INTEGER DEFAULT NULL,
            product_id  INTEGER NOT NULL,
            created_at  TEXT NOT NULL DEFAULT ({$now}),
            UNIQUE(owner_key, product_id)
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_wishlist_owner ON wishlist_items(owner_key)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_wishlist_product ON wishlist_items(product_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_wishlist_customer ON wishlist_items(customer_id)");
    }
};
