<?php
/**
 * Customer e-mail unique per store, not across the whole installation.
 *
 * Login, registration and password reset all work per store, but customers.email
 * was UNIQUE globally: an account in one store made the address unusable in every
 * other store, and anyone could register a foreign address in one store to lock its
 * owner out of all the others. The new key (store_id, email) is strictly weaker, so
 * existing data always satisfies it.
 */
return new class {
    private const INDEX = 'uq_customers_store_email';

    public function up(PDO $db, bool $isSqlite): void {
        if (!$this->tableExists($db, $isSqlite) || $this->indexExists($db, $isSqlite)) return;
        $isSqlite ? $this->rebuildSqlite($db) : $this->swapMysql($db);
    }

    /**
     * SQLite cannot drop an inline UNIQUE constraint, so the table is rebuilt. Addresses, reset tokens
     * and verifications reference customers(id) ON DELETE CASCADE: with foreign keys on, DROP TABLE
     * would delete them, and the pragma only changes outside a transaction.
     */
    private function rebuildSqlite(PDO $db): void {
        $outer = $db->inTransaction();
        if ($outer) $db->commit();
        $foreignKeys = (int)$db->query('PRAGMA foreign_keys')->fetchColumn();
        $db->exec('PRAGMA foreign_keys = OFF');
        try {
            $db->beginTransaction();
            try {
                $createSql = (string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='customers'")->fetchColumn();
                $autoincrement = stripos($createSql, 'AUTOINCREMENT') !== false ? ' AUTOINCREMENT' : '';
                $columns = $db->query('PRAGMA table_info(customers)')->fetchAll(PDO::FETCH_ASSOC);
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
                $secondary = $db->query("SELECT sql FROM sqlite_master WHERE type IN ('index', 'trigger') AND tbl_name='customers' AND sql IS NOT NULL ORDER BY type")->fetchAll(PDO::FETCH_COLUMN);
                $names = implode(', ', array_column($columns, 'name'));
                $db->exec('CREATE TABLE customers_r5 (' . implode(', ', $definitions) . ')');
                $db->exec("INSERT INTO customers_r5 ({$names}) SELECT {$names} FROM customers");
                $db->exec('DROP TABLE customers');
                $db->exec('ALTER TABLE customers_r5 RENAME TO customers');
                foreach ($secondary as $sql) $db->exec($sql);
                $db->exec('CREATE UNIQUE INDEX ' . self::INDEX . ' ON customers(store_id, email)');
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        } finally {
            if ($foreignKeys === 1) $db->exec('PRAGMA foreign_keys = ON');
            if ($outer) $db->beginTransaction();
        }
    }

    private function swapMysql(PDO $db): void {
        $stmt = $db->query("SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY'
            GROUP BY INDEX_NAME");
        $changes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if ($index['cols'] === 'email') $changes[] = 'DROP INDEX `' . str_replace('`', '``', $index['INDEX_NAME']) . '`';
        }
        $changes[] = 'ADD UNIQUE INDEX ' . self::INDEX . ' (store_id, email)';
        $db->exec('ALTER TABLE customers ' . implode(', ', $changes));
    }

    private function tableExists(PDO $db, bool $isSqlite): bool {
        $sql = $isSqlite
            ? "SELECT 1 FROM sqlite_master WHERE type='table' AND name='customers'"
            : "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customers'";
        return (bool)$db->query($sql)->fetchColumn();
    }

    private function indexExists(PDO $db, bool $isSqlite): bool {
        $sql = $isSqlite
            ? "SELECT 1 FROM sqlite_master WHERE type='index' AND name='" . self::INDEX . "'"
            : "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customers' AND INDEX_NAME='" . self::INDEX . "'";
        return (bool)$db->query($sql)->fetchColumn();
    }
};
