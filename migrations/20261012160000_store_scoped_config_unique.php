<?php
/**
 * Store-scoped uniqueness for store configuration.
 *
 * CMS page slugs, shipping/payment method and zone codes and their link rows
 * were globally UNIQUE, so a second store could not reuse a code or slug that
 * another store already owned (and INSERT IGNORE link rows were silently
 * dropped). The new keys are (store_id, ...) — strictly weaker than the old
 * ones, so existing data always satisfies them.
 */
return new class {
    /** table => [old unique columns, new unique columns (MySQL prefix lengths where needed)] */
    private const KEYS = [
        'pages' => [['slug'], ['store_id', 'slug']],
        'shipping_methods' => [['code'], ['store_id', 'code']],
        'shipping_zones' => [['code'], ['store_id', 'code']],
        // 5 x VARCHAR(191) utf8mb4 exceeds InnoDB's 3072-byte key limit; ISO
        // country and postal codes are far shorter than their prefixes.
        'shipping_zone_countries' => [
            ['zone_code', 'country', 'postal_min', 'postal_max'],
            ['store_id', 'zone_code', 'country(16)', 'postal_min(32)', 'postal_max(32)'],
        ],
        'payment_methods' => [['code'], ['store_id', 'code']],
        'payment_method_countries' => [['method_code', 'country'], ['store_id', 'method_code', 'country']],
        'payment_method_shipping' => [['method_code', 'shipping_method_code'], ['store_id', 'method_code', 'shipping_method_code']],
    ];

    public function up(PDO $db, bool $isSqlite): void {
        foreach (self::KEYS as $table => [$old, $new]) {
            if (!$this->tableExists($db, $isSqlite, $table) || $this->indexExists($db, $isSqlite, $table, "uq_{$table}_store")) continue;
            $isSqlite ? $this->rebuildSqlite($db, $table, $new) : $this->swapMysql($db, $table, $old, $new);
        }
    }

    /** SQLite cannot drop inline UNIQUE constraints: rebuild the table without them. */
    private function rebuildSqlite(PDO $db, string $table, array $new): void {
        $createSql = (string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name=" . $db->quote($table))->fetchColumn();
        $autoincrement = stripos($createSql, 'AUTOINCREMENT') !== false ? ' AUTOINCREMENT' : '';
        $columns = $db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
        $definitions = [];
        foreach ($columns as $column) {
            $definition = $column['name'] . ' ' . ($column['type'] !== '' ? $column['type'] : 'TEXT');
            if ((int)$column['pk'] === 1) {
                $definitions[] = $definition . ' PRIMARY KEY' . $autoincrement;
                continue;
            }
            if ((int)$column['notnull'] === 1) $definition .= ' NOT NULL';
            if ($column['dflt_value'] !== null) $definition .= ' DEFAULT (' . $column['dflt_value'] . ')';
            $definitions[] = $definition;
        }
        $secondary = $db->query("SELECT sql FROM sqlite_master WHERE type IN ('index', 'trigger') AND tbl_name=" . $db->quote($table) . " AND sql IS NOT NULL ORDER BY type")->fetchAll(PDO::FETCH_COLUMN);
        $names = implode(', ', array_column($columns, 'name'));
        $db->exec("CREATE TABLE {$table}_a055 (" . implode(', ', $definitions) . ')');
        $db->exec("INSERT INTO {$table}_a055 ({$names}) SELECT {$names} FROM {$table}");
        $db->exec("DROP TABLE {$table}");
        $db->exec("ALTER TABLE {$table}_a055 RENAME TO {$table}");
        foreach ($secondary as $sql) $db->exec($sql);
        $plain = array_map(static fn(string $column): string => preg_replace('/\(\d+\)$/', '', $column), $new);
        $db->exec("CREATE UNIQUE INDEX uq_{$table}_store ON {$table}(" . implode(', ', $plain) . ')');
    }

    private function swapMysql(PDO $db, string $table, array $old, array $new): void {
        $stmt = $db->prepare("SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY'
            GROUP BY INDEX_NAME");
        $stmt->execute([$table]);
        $changes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if ($index['cols'] === implode(',', $old)) $changes[] = 'DROP INDEX `' . str_replace('`', '``', $index['INDEX_NAME']) . '`';
        }
        $changes[] = "ADD UNIQUE INDEX uq_{$table}_store (" . implode(', ', $new) . ')';
        $db->exec("ALTER TABLE {$table} " . implode(', ', $changes));
    }

    private function tableExists(PDO $db, bool $isSqlite, string $table): bool {
        $stmt = $isSqlite
            ? $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?")
            : $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    private function indexExists(PDO $db, bool $isSqlite, string $table, string $index): bool {
        $stmt = $isSqlite
            ? $db->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND name=?")
            : $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $stmt->execute($isSqlite ? [$index] : [$table, $index]);
        return (bool)$stmt->fetchColumn();
    }
};
