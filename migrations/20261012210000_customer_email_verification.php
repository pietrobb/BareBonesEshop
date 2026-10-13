<?php
/**
 * A07.4: customer e-mail verification. Only the SHA-256 of the emailed token is stored;
 * the token is bound to the address it was sent to, expires, and is consumed once.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $db->exec($isSqlite
            ? "CREATE TABLE IF NOT EXISTS customer_email_verifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                expires_at TEXT NOT NULL,
                used_at TEXT NULL,
                created_at TEXT DEFAULT (datetime('now'))
            )"
            : "CREATE TABLE IF NOT EXISTS customer_email_verifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                email VARCHAR(191) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_customer_email_verification_token (token_hash),
                KEY idx_customer_email_verification_customer (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if ($isSqlite) {
            $db->exec('CREATE INDEX IF NOT EXISTS idx_customer_email_verification_customer ON customer_email_verifications (customer_id)');
        }
    }
};
