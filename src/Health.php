<?php
/** Public liveness/readiness probes with deliberately non-sensitive output. */
class Health
{
    public static function liveness(): array
    {
        return ['status' => 'ok', 'service' => 'bareboneseshop', 'time' => gmdate('c')];
    }

    public static function readiness(?array $config = null, ?string $root = null, ?PDO $db = null): array
    {
        $root ??= dirname(__DIR__);
        $config ??= require $root . '/config.php';
        $checks = [];
        try {
            $db ??= Db::get();
            $db->query('SELECT 1')->fetchColumn();
            // A read-only database answers SELECT but cannot take an order.
            $checks['database'] = Db::writable($db) ? 'ok' : 'read_only';
        } catch (Throwable $e) {
            $checks['database'] = 'unavailable';
        }
        $stores = is_file($root . '/stores.json') ? json_decode((string)file_get_contents($root . '/stores.json'), true) : null;
        $default = is_array($stores) ? (string)($stores['default_store'] ?? '') : '';
        $checks['stores'] = is_array($stores) && isset($stores['stores'][$default]) ? 'ok' : 'invalid';
        foreach (['jobs_dir', 'private_cache_dir'] as $key) {
            $path = (string)($config[$key] ?? '');
            $checks[$key] = $path !== '' && is_dir($path) && is_writable($path) ? 'ok' : 'unavailable';
        }
        $ready = array_unique(array_values($checks)) === ['ok'];
        return ['status' => $ready ? 'ready' : 'not_ready', 'service' => 'bareboneseshop', 'checks' => $checks, 'correlation_id' => Logger::correlationId()];
    }
}
