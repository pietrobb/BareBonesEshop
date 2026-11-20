<?php

// R50 (review round 50, security low): digital_entitlements.resume_claimed_offset is the served offset the last free
// resume started from. A free resume needs progress past it, so concurrent "Range: bytes=1-" requests from one
// recorded offset are one resume, not one free near-complete copy each (DigitalDelivery::claimResume()).
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query("PRAGMA table_info(digital_entitlements)")->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'digital_entitlements'")->fetchAll(PDO::FETCH_COLUMN);
        if ($columns !== [] && !in_array('resume_claimed_offset', $columns, true)) {
            $db->exec('ALTER TABLE digital_entitlements ADD COLUMN resume_claimed_offset ' . ($isSqlite ? 'INTEGER' : 'BIGINT NULL'));
        }
        if (method_exists('Db', 'forgetSchema')) Db::forgetSchema($db);
    }
};
