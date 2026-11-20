<?php

// R50 (review round 20, medium): migration 20261113190000 stored orders.oss_net only when this release's Db ran it; a
// signed update from an older release skipped it and every checkout counted those open orders from their lines.
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        if (!method_exists('OssThreshold', 'backfillOrderNets')) return;
        OssThreshold::backfillOrderNets($db, sprintf('%04d-01-01', (int)gmdate('Y') - 1));
    }
};
