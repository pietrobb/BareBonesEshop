<?php

final class MigrationSql
{
    public static function short(bool $isSqlite): string
    {
        return $isSqlite ? 'TEXT' : 'VARCHAR(191)';
    }

    public static function text(bool $isSqlite): string
    {
        return $isSqlite ? 'TEXT' : 'LONGTEXT';
    }

    public static function datetime(bool $isSqlite): string
    {
        return $isSqlite ? 'TEXT' : 'DATETIME';
    }

    public static function defaultClause(bool $isSqlite, string $value): string
    {
        $literal = "'" . str_replace("'", "''", $value) . "'";
        return 'DEFAULT ' . ($isSqlite ? $literal : "({$literal})");
    }

    public static function index(
        PDO $db,
        bool $isSqlite,
        string $table,
        string $name,
        string $columns,
        bool $unique = false
    ): void {
        if ($isSqlite) {
            $db->exec('CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX IF NOT EXISTS {$name} ON {$table}({$columns})");
            return;
        }

        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $statement->execute([$table, $name]);
        if ((int)$statement->fetchColumn() === 0) {
            $db->exec('CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX {$name} ON {$table}({$columns})");
        }
    }
}
