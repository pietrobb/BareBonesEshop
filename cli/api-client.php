<?php
require_once __DIR__ . '/cli-only.php';
require_once __DIR__ . '/../src/autoload.php';

$command = strtolower((string)($argv[1] ?? ''));
$options = getopt('', ['name:', 'store:', 'scopes:', 'expires::', 'key:']);

try {
    if ($command === 'create') {
        $name = trim((string)($options['name'] ?? ''));
        $store = strtolower(trim((string)($options['store'] ?? 'default')));
        $scopes = array_values(array_filter(array_map('trim', explode(',', (string)($options['scopes'] ?? '')))));
        $expires = isset($options['expires']) && trim((string)$options['expires']) !== ''
            ? trim((string)$options['expires'])
            : null;
        $stores = Bootstrap::stores()['stores'] ?? [];
        if (!isset($stores[$store])) throw new InvalidArgumentException('Unknown store');
        $client = ApiAuth::issueClient($name, $store, $scopes, $expires);
        fwrite(STDOUT, json_encode([
            'id' => $client['id'],
            'key_id' => $client['key_id'],
            'store_id' => $store,
            'scopes' => $client['scopes'],
            'token' => $client['token'],
            'warning' => 'Store this token now; it cannot be recovered.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    }

    if ($command === 'list') {
        $rows = Db::get()->query("SELECT id, key_id, store_id, name, scopes, active, expires_at, last_used_at, created_at
            FROM api_clients ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['active'] = (bool)$row['active'];
            $row['scopes'] = json_decode((string)$row['scopes'], true) ?: [];
        }
        unset($row);
        fwrite(STDOUT, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    }

    if ($command === 'revoke') {
        $keyId = strtolower(trim((string)($options['key'] ?? '')));
        if (!preg_match('/^[a-f0-9]{12}$/D', $keyId)) throw new InvalidArgumentException('Invalid --key');
        $stmt = Db::get()->prepare('UPDATE api_clients SET active=0 WHERE key_id=:key AND active=1');
        $stmt->execute([':key' => $keyId]);
        fwrite(STDOUT, json_encode(['key_id' => $keyId, 'revoked' => $stmt->rowCount() === 1], JSON_THROW_ON_ERROR) . PHP_EOL);
        exit($stmt->rowCount() === 1 ? 0 : 2);
    }

    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php cli/api-client.php create --name=ERP --store=default --scopes=products:read,orders:read\n");
    fwrite(STDERR, "  php cli/api-client.php list\n");
    fwrite(STDERR, "  php cli/api-client.php revoke --key=<12-hex-key-id>\n");
    exit(64);
} catch (Throwable $e) {
    fwrite(STDERR, 'API client command failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
