<?php
/**
 * Migration: create_product_reviews
 * Generated: 2026-04-26T09:00:00+00:00
 *
 * Adds the product_reviews table — customer/guest product ratings (1-5) with
 * moderation workflow (pending → approved/rejected). customer_id is nullable so
 * guest reviews are supported; author_name is the public display name.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $autoinc = $isSqlite ? '' : ' AUTO_INCREMENT';
        $now     = $isSqlite ? "datetime('now')" : "NOW()";

        $db->exec("CREATE TABLE IF NOT EXISTS product_reviews (
            id           INTEGER PRIMARY KEY{$autoinc},
            product_id   INTEGER NOT NULL,
            customer_id  INTEGER DEFAULT NULL,
            author_name  TEXT NOT NULL DEFAULT '',
            rating       INTEGER NOT NULL,
            title        TEXT NOT NULL DEFAULT '',
            body         TEXT NOT NULL DEFAULT '',
            status       TEXT NOT NULL DEFAULT 'pending',
            created_at   TEXT NOT NULL DEFAULT ({$now}),
            updated_at   TEXT NOT NULL DEFAULT ({$now})
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_product_reviews_product ON product_reviews(product_id, status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_product_reviews_status ON product_reviews(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_product_reviews_customer ON product_reviews(customer_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_product_reviews_created ON product_reviews(created_at)");
    }
};
