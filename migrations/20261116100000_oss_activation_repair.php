<?php
/**
 * R39 (medium 1): the R38 migration judged each carried OSS activation by the table after its own deletions, so it kept
 * (2024 over, 2025 and 2026 at 3 000 EUR: [2024, 2026]) or even added (a lone carried 2025: a new 2026) a year the seller's
 * own sales never crossed. Repaired from the documents and orders alone (OssThreshold::rebuildActivations()), whether
 * the database was upgraded from c1fe7a9 directly or through R38 already.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        // Run by an older release's code, the activations stay as that release recorded them.
        if (!class_exists('OssThreshold') || !method_exists('OssThreshold', 'lastReason')) return;
        OssThreshold::rebuildActivations($db, true);
    }
};
