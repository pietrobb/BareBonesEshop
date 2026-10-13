<?php
/**
 * Atomic coupon limits: one counter row per store/coupon/subject ('' = all uses, 'customer:<sha256>' = one customer).
 * A conditional UPDATE ... WHERE uses < limit claims a use; rows are seeded lazily from coupon_uses.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $db->exec($isSqlite
            ? "CREATE TABLE IF NOT EXISTS coupon_usage_counters (
                store_id TEXT NOT NULL DEFAULT 'default',
                coupon_code TEXT NOT NULL,
                subject TEXT NOT NULL DEFAULT '',
                uses INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (store_id, coupon_code, subject)
            )"
            : "CREATE TABLE IF NOT EXISTS coupon_usage_counters (
                store_id VARCHAR(64) NOT NULL DEFAULT 'default',
                coupon_code VARCHAR(100) NOT NULL,
                subject VARCHAR(80) NOT NULL DEFAULT '',
                uses INT NOT NULL DEFAULT 0,
                PRIMARY KEY (store_id, coupon_code, subject)
            ) ENGINE=InnoDB");
    }
};
