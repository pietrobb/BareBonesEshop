<?php

// R50 (review round 20, medium): a complaint is rejected only on an expert assessment of the goods; the assessment
// (who assessed them and the finding) is kept with the decision and named in the rejection e-mail.
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(complaints)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints'")->fetchAll(PDO::FETCH_COLUMN);
        if ($columns !== [] && !in_array('assessment', $columns, true)) $db->exec('ALTER TABLE complaints ADD COLUMN assessment ' . MigrationSql::text($isSqlite));
        if (method_exists('Db', 'forgetSchema')) Db::forgetSchema($db);
    }
};
