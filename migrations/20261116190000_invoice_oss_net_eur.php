<?php
/**
 * R40 (low): invoices.oss_net_eur is a document's distance-sale net (oss_net) in EUR cents at the ECB rate of the day it
 * was issued (art. 91(2) VAT Directive), for a document in another currency; OssThreshold::record() fills it and counts
 * it in the year's EUR total. The sums were converted at today's rate, so a moving rate alone could pass the threshold
 * (an activation that stays). Documents issued before keep NULL: counted in their currency at the current rate, as before.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(invoices)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('oss_net_eur', $columns, true)) $db->exec('ALTER TABLE invoices ADD COLUMN oss_net_eur BIGINT DEFAULT NULL');
        if (method_exists('Db', 'forgetSchema')) Db::forgetSchema($db);
    }
};
