<?php
/**
 * BareBones Shop — Audit Log
 * Who changed what and when.
 */
class AuditLog {
    public static function log(string $entity, int $entityId, $old, $new, ?string $note = null): void {
        $db = Db::get();
        $stmt = $db->prepare("
            INSERT INTO audit_log (entity, entity_id, action, old_value, new_value, note, actor, ip_address, store_id, correlation_id)
            VALUES (:entity, :eid, :action, :old, :new, :note, :actor, :ip, :store, :correlation)
        ");
        $stmt->execute([
            ':entity' => $entity,
            ':eid'    => $entityId,
            ':action' => self::detectAction($old, $new),
            ':old'    => is_array($old) ? json_encode($old) : (string)$old,
            ':new'    => is_array($new) ? json_encode($new) : (string)$new,
            ':note'   => $note,
            ':actor'  => self::currentActor(),
            ':ip'     => php_sapi_name() === 'cli' ? '127.0.0.1' : (Security::clientIp() ?? ''),
            ':store'  => StoreContext::current()->id(),
            ':correlation' => Logger::correlationId(),
        ]);
    }

    public static function forEntity(string $entity, int $entityId, int $limit = 50): array {
        $stmt = Db::get()->prepare("
            SELECT * FROM audit_log
            WHERE store_id = :store AND entity = :entity AND entity_id = :eid
            ORDER BY created_at DESC, id DESC
            LIMIT :limit
        ");
        $stmt->execute([':store' => StoreContext::current()->id(), ':entity' => $entity, ':eid' => $entityId, ':limit' => $limit]);
        return $stmt->fetchAll();
    }

    private static function detectAction($old, $new): string {
        if ($old === null && $new !== null) return 'create';
        if ($old !== null && $new === null) return 'delete';
        return 'update';
    }

    private static function currentActor(): string {
        if (php_sapi_name() === 'cli') return 'cli:' . basename($_SERVER['SCRIPT_NAME'] ?? 'unknown');
        if (!empty($_SESSION['admin_user'])) return 'admin:' . Security::adminUser();
        if (!empty($_SERVER['PHP_AUTH_USER'])) return 'admin:' . $_SERVER['PHP_AUTH_USER'];
        if (!empty($_SERVER['HTTP_X_WEBHOOK_SOURCE'])) return 'webhook:' . $_SERVER['HTTP_X_WEBHOOK_SOURCE'];
        return 'system';
    }
}
