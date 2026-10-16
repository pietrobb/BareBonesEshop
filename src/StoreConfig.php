<?php
/**
 * Store-owned configuration rows (shipping methods/zones/rates, payment methods and their links).
 * Every read and mutation is bound to the active store's provider_scope (the scope checkout reads); table and column names are code literals.
 */
final class StoreConfig {
    public static function rows(string $sql): array {
        $stmt = Db::get()->prepare($sql);
        $stmt->execute([':store' => StoreContext::current()->providerId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** One column of the active store's row matching $column = $value, or null when it does not exist there. */
    public static function value(string $table, string $column, string $value, string $select = 'id'): ?string {
        $stmt = Db::get()->prepare("SELECT {$select} FROM {$table} WHERE {$column} = :value AND store_id = :store");
        $stmt->execute([':value' => $value, ':store' => StoreContext::current()->providerId()]);
        $found = $stmt->fetchColumn();
        return $found === false ? null : (string)$found;
    }

    /** The active store's row $id of $table, or null. */
    public static function row(string $table, int $id): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM {$table} WHERE id = :id AND store_id = :store");
        $stmt->execute([':id' => $id, ':store' => StoreContext::current()->providerId()]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Delete the active store's row $id of $table; the deleted row is written to the audit log. */
    public static function delete(string $table, int $id): bool {
        $old = self::row($table, $id);
        $stmt = Db::get()->prepare("DELETE FROM {$table} WHERE id = :id AND store_id = :store");
        $stmt->execute([':id' => $id, ':store' => StoreContext::current()->providerId()]);
        if ($stmt->rowCount() === 0) return false;
        AuditLog::log($table, $id, $old, null, 'Admin config delete');
        return true;
    }

    /** Audit a saved row: $id 0 means it was just inserted (lastInsertId); $old is the row before the change. */
    public static function audit(string $table, int $id, ?array $old): void {
        $id = $id ?: (int)Db::get()->lastInsertId();
        if ($id <= 0) return;
        AuditLog::log($table, $id, $old, self::row($table, $id), $old ? 'Admin config edit' : 'Admin config create');
    }
}
