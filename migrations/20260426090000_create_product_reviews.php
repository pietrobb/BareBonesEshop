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
        $short   = MigrationSql::short($isSqlite);
        $text    = MigrationSql::text($isSqlite);
        $date    = MigrationSql::datetime($isSqlite);

        $db->exec("CREATE TABLE IF NOT EXISTS product_reviews (
            id           INTEGER PRIMARY KEY{$autoinc},
            product_id   INTEGER NOT NULL,
            customer_id  INTEGER DEFAULT NULL,
            author_name  {$short} NOT NULL DEFAULT '',
            rating       INTEGER NOT NULL,
            title        {$short} NOT NULL DEFAULT '',
            body         {$text} NOT NULL DEFAULT '',
            status       {$short} NOT NULL DEFAULT 'pending',
            created_at   {$date} NOT NULL DEFAULT ({$now}),
            updated_at   {$date} NOT NULL DEFAULT ({$now})
        )");
        MigrationSql::index($db, $isSqlite, 'product_reviews', 'idx_product_reviews_product', 'product_id, status');
        MigrationSql::index($db, $isSqlite, 'product_reviews', 'idx_product_reviews_status', 'status');
        MigrationSql::index($db, $isSqlite, 'product_reviews', 'idx_product_reviews_customer', 'customer_id');
        MigrationSql::index($db, $isSqlite, 'product_reviews', 'idx_product_reviews_created', 'created_at');
    }
};
