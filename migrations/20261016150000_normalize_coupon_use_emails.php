<?php
/**
 * Coupon per-customer limits compare coupon_uses.customer_email as stored, normalised exactly like
 * Str::normalizeEmail() (trim + UTF-8 mb_strtolower), instead of LOWER(TRIM(...)) in SQL, which is
 * ASCII-only on SQLite but Unicode-aware on MySQL. Older rows may still carry the e-mail as typed.
 * Normalised in PHP so both drivers end with the same bytes.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(coupon_uses)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coupon_uses'")->fetchAll(PDO::FETCH_COLUMN);
        if ($columns === [] || !in_array('customer_email', $columns, true)) return;
        $update = $db->prepare('UPDATE coupon_uses SET customer_email = :email WHERE id = :id');
        $lastId = 0;
        do {
            $rows = $db->query('SELECT id, customer_email FROM coupon_uses WHERE id > ' . $lastId . ' ORDER BY id LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $email = (string)$row['customer_email'];
                $normalized = mb_strtolower(trim($email), 'UTF-8');
                if ($normalized !== $email) $update->execute([':email' => $normalized, ':id' => $lastId]);
            }
        } while (count($rows) === 1000);
    }
};
