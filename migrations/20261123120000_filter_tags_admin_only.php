<?php

// R57 (review round 22, S1): R56 left admin-only attributes (catalog.json "admin_only", e.g. a paid file's
// download_url) out of the filter tags it writes, but the tags written before stayed: the search's full-text index read
// them and a category filter matched them, so a hidden value could be read character by character. Every product's
// filter tags are written again from its attributes (Import::buildFilterTagsFromAttrs()); the full-text index follows
// (the SQLite triggers, MySQL's FULLTEXT index).
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        if (!method_exists('Import', 'buildFilterTagsFromAttrs')) return;
        $update = $db->prepare('UPDATE products SET filter_tags = :tags WHERE id = :id');
        $select = $db->prepare("SELECT id, attrs, filter_tags FROM products WHERE id > :after AND attrs IS NOT NULL AND attrs <> '' AND attrs <> '{}' ORDER BY id LIMIT 1000");
        $after = 0;
        do {
            $select->execute([':after' => $after]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $after = (int)$row['id'];
                $attrs = json_decode((string)$row['attrs'], true);
                $tags = Import::buildFilterTagsFromAttrs(is_array($attrs) ? $attrs : []);
                if ($tags !== (string)($row['filter_tags'] ?? '')) $update->execute([':tags' => $tags, ':id' => $after]);
            }
        } while (count($rows) === 1000);
    }
};
