<?php
// Browser projects (Chromium, WebKit, Firefox) all check out from 127.0.0.1; the per-IP checkout
// limit is real behaviour, so each checkout test starts from an empty limiter in disposable E2E storage.
require_once __DIR__ . '/../../cli/cli-only.php';
require_once __DIR__ . '/../../src/autoload.php';

$config = require dirname(__DIR__, 2) . '/config.php';
$driver = (string)($config['db_driver'] ?? '');
$safe = $driver === 'sqlite'
    ? str_contains(strtolower(str_replace('\\', '/', (string)($config['db_sqlite_path'] ?? ''))), 'barebones-e2e')
    : preg_match('/^[A-Za-z0-9_]+_e2e_test$/', (string)($config['db_name'] ?? '')) === 1;
if (!$safe) {
    throw new RuntimeException('Refusing to reset rate limits outside disposable E2E storage');
}

$dir = (new ReflectionMethod(Security::class, 'privateCacheDir'))->invoke(null, $config);
$file = $dir . '/rate_limit.sqlite';
if (is_file($file)) {
    $db = new PDO('sqlite:' . $file);
    $db->exec('DELETE FROM hits');
}
echo "ok\n";
