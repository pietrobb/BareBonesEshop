<?php
/** A15.8: per store + locale overrides of the file e-mail templates (templates/emails), edited in the admin. */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        // MySQL: no explicit charset/collation, the table inherits the database default (joins stay collation-compatible).
        $db->exec($isSqlite
            ? "CREATE TABLE IF NOT EXISTS email_template_overrides (
                store_id TEXT NOT NULL,
                template TEXT NOT NULL,
                locale TEXT NOT NULL,
                subject TEXT NOT NULL,
                html TEXT NOT NULL,
                body_text TEXT NOT NULL DEFAULT '',
                updated_by TEXT NOT NULL DEFAULT '',
                updated_at TEXT NOT NULL,
                PRIMARY KEY (store_id, template, locale)
            )"
            : "CREATE TABLE IF NOT EXISTS email_template_overrides (
                store_id VARCHAR(64) NOT NULL,
                template VARCHAR(80) NOT NULL,
                locale VARCHAR(16) NOT NULL,
                subject VARCHAR(500) NOT NULL,
                html MEDIUMTEXT NOT NULL,
                body_text MEDIUMTEXT NOT NULL,
                updated_by VARCHAR(191) NOT NULL DEFAULT '',
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (store_id, template, locale)
            ) ENGINE=InnoDB");
    }
};
