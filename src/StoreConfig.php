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

    public static function delete(string $table, int $id): bool {
        $stmt = Db::get()->prepare("DELETE FROM {$table} WHERE id = :id AND store_id = :store");
        $stmt->execute([':id' => $id, ':store' => StoreContext::current()->providerId()]);
        return $stmt->rowCount() > 0;
    }
}
