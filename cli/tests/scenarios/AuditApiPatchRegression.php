<?php

// Finding 21: API v1 PATCH validation, stock through the warehouse ledger and concurrent Idempotency-Key.
echo "\n--- Audit A08.1: API v1 product PATCH ---\n";
$a081Root = test_fixture_path('api-patch');
mkdir($a081Root);
file_put_contents($a081Root . '/probe.php', <<<'PHP'
<?php
[, $phase, $driver, $root, $target] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');
if ($driver === 'sqlite') {
    // Same connection pragmas as Db::get() in production.
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 5000');
}
if ($driver === 'mysql') {
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}`");
    $db->exec("USE `{$target}`");
}
Db::init($db, $driver);
$store = ['name' => 'default', 'default_locale' => 'en', 'languages' => ['en'], 'currency' => 'eur', 'url_prefix' => '', 'domains' => [], 'permissions' => ['*'], 'channels' => ['storefront', 'api'], 'catalog_scope' => 'default'];
$stores = ['default_store' => 'default', 'stores' => ['default' => $store]];
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
StoreContext::activate(StoreContext::resolve($stores, '/', '', null, 'api'));
$state = __DIR__ . '/state-' . $driver . '.json';
// Same status mapping as api/v1/index.php.
$call = static function(int $productId, array $body, string $key, array $client): array {
    try {
        $result = ApiV1::dispatch('PATCH', "/api/v1/products/{$productId}", [], $body, ['Idempotency-Key' => $key], $client);
        return [$result['status'], $result['body']];
    } catch (JsonException|InvalidArgumentException $e) { return [400, $e->getMessage()]; }
    catch (UnexpectedValueException $e) { return [409, $e->getMessage()]; }
    catch (DomainException $e) { return [403, $e->getMessage()]; }
    catch (Throwable $e) { return [500, get_class($e) . ': ' . $e->getMessage()]; }
};
$product = static function(int $id) use ($db): array {
    $stmt = $db->prepare('SELECT name, status, stock FROM products WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
};

if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
    $client = ApiAuth::issueClient('A08.1', 'default', ['products:read', 'products:write']);
    $db->prepare("INSERT INTO categories (store_id, slug, name, path) VALUES ('default', 'a081', 'A081', 'a081')")->execute();
    $category = (int)$db->lastInsertId();
    $ids = [];
    foreach (['plain', 'ledger', 'race'] as $case) {
        $db->prepare("INSERT INTO products (sku, name, slug, category_id, price, stock, status, store_id) VALUES (?, ?, ?, ?, 1000, 0, 'active', 'default')")
            ->execute(['A081-' . strtoupper($case), 'A081 ' . $case, 'a081-' . $case, $category]);
        $ids[$case] = (int)$db->lastInsertId();
    }
    $warehouse = Warehouse::save(['code' => 'a081-main', 'name' => 'A081 main', 'priority' => 10, 'is_default' => 1]);
    Warehouse::setStock($warehouse, 'A081-LEDGER', 5);
    $db->exec("UPDATE warehouse_stock SET reserved = 2 WHERE sku = 'A081-LEDGER'");
    Warehouse::syncProductStock(['A081-LEDGER']);
    file_put_contents($state, json_encode(['token' => $client['token'], 'ids' => $ids], JSON_THROW_ON_ERROR));
    echo json_encode(['stock' => $product($ids['ledger'])['stock']]);
    exit(0);
}

$fx = json_decode(file_get_contents($state), true, 512, JSON_THROW_ON_ERROR);
$client = ApiAuth::authenticate('Bearer ' . $fx['token']);
$ids = $fx['ids'];

if ($phase === 'single') {
    $out = [];
    foreach (['name_null' => ['name' => null], 'status_null' => ['status' => null], 'name_array' => ['name' => ['x']], 'status_array' => ['status' => ['active']]] as $case => $body) {
        $out[$case] = $call($ids['plain'], $body, 'a081-' . str_replace('_', '-', $case) . '-0001', $client)[0];
    }
    $out['plain_unchanged'] = $product($ids['plain']);
    [$status, $body] = $call($ids['ledger'], ['stock' => 7], 'a081-ledger-stock-0001', $client);
    $out['stock_status'] = $status;
    $out['stock_body'] = is_array($body) ? ($body['data']['stock'] ?? null) : $body;
    Warehouse::syncProductStock(['A081-LEDGER']);
    $out['stock_after_sync'] = (int)$product($ids['ledger'])['stock'];
    $out['warehouse'] = $db->query("SELECT qty, reserved FROM warehouse_stock WHERE sku = 'A081-LEDGER'")->fetch();
    $movements = static fn(): int => (int)$db->query("SELECT COUNT(*) FROM stock_movements WHERE sku = 'A081-LEDGER' AND reference_type = 'api_v1'")->fetchColumn();
    $out['movements'] = $movements();
    [$status, $body] = $call($ids['ledger'], ['stock' => 7], 'a081-ledger-stock-0001', $client);
    $out['replay'] = [$status, is_array($body) ? ($body['meta']['idempotent_replay'] ?? null) : $body, $movements()];
    // A SKU without a warehouse row gets one in the default warehouse (setStock path, native MySQL prepares).
    [$status, $body] = $call($ids['plain'], ['stock' => 4], 'a081-plain-stock-0001', $client);
    Warehouse::syncProductStock(['A081-PLAIN']);
    $out['new_row'] = [$status, is_array($body) ? ($body['data']['stock'] ?? null) : $body, (int)$product($ids['plain'])['stock'],
        (int)$db->query("SELECT qty FROM warehouse_stock WHERE sku = 'A081-PLAIN'")->fetchColumn()];
    // Round 7: values past the signed 32-bit MySQL INTEGER are a 400, not a failed write (500).
    foreach (['price_huge' => ['price' => 3000000000], 'stock_huge' => ['stock' => 2147483648], 'weight_huge' => ['weight_g' => 2147483648],
        'compare_huge' => ['compare_price' => 3000000000]] as $case => $body) {
        $out[$case] = $call($ids['plain'], $body, 'a081-' . str_replace('_', '-', $case) . '-0001', $client)[0];
    }
    $out['price_max'] = $call($ids['plain'], ['price' => 2000000000], 'a081-price-max-0001', $client)[0];
    $out['huge_unchanged'] = $db->query("SELECT price, stock, weight_g, compare_price FROM products WHERE sku = 'A081-PLAIN'")->fetch();
    Warehouse::setStock((int)Warehouse::defaultWarehouse()['id'], 'A081-PLAIN', 1999999999);
    try {
        Warehouse::adjustStock((int)Warehouse::defaultWarehouse()['id'], 'A081-PLAIN', 2);
        $out['adjust_over_max'] = 'applied';
    } catch (Throwable $e) {
        $out['adjust_over_max'] = get_class($e) . ': ' . $e->getMessage();
    }
    $out['adjust_over_max_qty'] = (int)$db->query("SELECT qty FROM warehouse_stock WHERE sku = 'A081-PLAIN'")->fetchColumn();
    echo json_encode($out);
    exit(0);
}

if ($phase === 'patch') {
    // Authenticated and ready; send the request only once the write path is held.
    touch(__DIR__ . '/ready-' . getmypid());
    for ($i = 0; $i < 200 && !is_file(__DIR__ . '/hold'); $i++) usleep(50000);
    echo json_encode($call($ids['race'], ['name' => 'A081 race renamed'], 'a081-race-key-0001', $client));
    exit(0);
}

if ($phase === 'race') {
    foreach (array_merge(glob(__DIR__ . '/ready-*'), glob(__DIR__ . '/hold')) as $flag) unlink($flag);
    $procs = [];
    foreach ([1, 2] as $n) {
        $procs[$n] = proc_open([PHP_BINARY, __FILE__, 'patch', $driver, $root, $target], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $procs[$n] = [$procs[$n], $pipes[1], $pipes[2]];
    }
    for ($i = 0; $i < 200 && count(glob(__DIR__ . '/ready-*')) < 2; $i++) usleep(50000);
    // Hold the write path so both requests are in flight before either can finish.
    if ($driver === 'mysql') {
        $db->beginTransaction();
        $db->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE')->execute([$ids['race']]);
    } else {
        $db->exec('BEGIN IMMEDIATE');
    }
    touch(__DIR__ . '/hold');
    usleep(1500000);
    if ($driver === 'mysql') $db->commit(); else $db->exec('COMMIT');
    $results = [];
    foreach ($procs as [$proc, $stdout, $stderr]) {
        $text = stream_get_contents($stdout);
        $errors = stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);
        proc_close($proc);
        $decoded = json_decode($text, true);
        $results[] = is_array($decoded) ? [$decoded[0], is_array($decoded[1]) ? ($decoded[1]['meta']['idempotent_replay'] ?? null) : $decoded[1]] : [0, $text . $errors];
    }
    usort($results, static fn(array $a, array $b): int => [$a[0], (int)($a[1] === true)] <=> [$b[0], (int)($b[1] === true)]);
    $count = static function(string $sql) use ($db, $ids): int {
        $stmt = $db->prepare($sql);
        $stmt->execute([(string)$ids['race']]);
        return (int)$stmt->fetchColumn();
    };
    echo json_encode([
        'results' => $results,
        'name' => $product($ids['race'])['name'],
        'outbox' => $count("SELECT COUNT(*) FROM event_outbox WHERE event_type = 'product.updated' AND aggregate_id = ?"),
        'audit' => $count("SELECT COUNT(*) FROM audit_log WHERE entity = 'product' AND entity_id = ?"),
        'keys' => (int)$db->query("SELECT COUNT(*) FROM api_idempotency WHERE idempotency_key = 'a081-race-key-0001'")->fetchColumn(),
    ]);
    exit(0);
}
PHP);
$a081Php = static function(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out, $err];
};
$a081Drivers = ['sqlite' => $a081Root . '/api.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a081Drivers['mysql'] = 'bbe_a081_api_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A08.1 native API PATCH race (set TEST_MYSQL_DSN)\n";
try {
    foreach ($a081Drivers as $a081Driver => $a081Target) {
        $a081Tag = "A08.1 {$a081Driver}";
        $a081Run = static fn(string $phase): array => $a081Php([$a081Root . '/probe.php', $phase, $a081Driver, dirname(TEST_CLI_DIR), $a081Target]);
        [$a081Code, $a081Out, $a081Err] = $a081Run('setup');
        assert_equals([0, ['stock' => 3]], [$a081Code, json_decode($a081Out, true) ?? $a081Out . $a081Err], "{$a081Tag}: fixture migrated, ledger SKU has 5 on hand / 2 reserved");

        [$a081Code, $a081Out, $a081Err] = $a081Run('single');
        $a081 = json_decode($a081Out, true);
        assert_true($a081Code === 0 && is_array($a081), "{$a081Tag}: single-request probe runs (" . substr($a081Out . $a081Err, 0, 300) . ')');
        $a081 = is_array($a081) ? $a081 : [];
        assert_equals(400, $a081['name_null'] ?? null, "{$a081Tag}: PATCH name=null is a 400 validation error, not a 500");
        assert_equals(400, $a081['status_null'] ?? null, "{$a081Tag}: PATCH status=null is a 400 validation error, not a 500");
        assert_equals(400, $a081['name_array'] ?? null, "{$a081Tag}: PATCH name as array is rejected");
        assert_equals(400, $a081['status_array'] ?? null, "{$a081Tag}: PATCH status as array is rejected");
        assert_equals(['name' => 'A081 plain', 'status' => 'active'], array_intersect_key((array)($a081['plain_unchanged'] ?? []), ['name' => 1, 'status' => 1]), "{$a081Tag}: rejected patches leave the product untouched");
        assert_equals([200, 7], [$a081['stock_status'] ?? null, $a081['stock_body'] ?? null], "{$a081Tag}: stock PATCH returns the new available quantity");
        assert_equals(7, $a081['stock_after_sync'] ?? null, "{$a081Tag}: warehouse sync keeps the API stock (booked in the ledger)");
        assert_equals(['qty' => 9, 'reserved' => 2], array_map('intval', (array)($a081['warehouse'] ?? [])), "{$a081Tag}: on-hand absorbs the change, reservations untouched");
        assert_equals(1, $a081['movements'] ?? null, "{$a081Tag}: exactly one ledger movement references the API");
        assert_equals([200, true, 1], $a081['replay'] ?? null, "{$a081Tag}: identical retry replays without a second ledger movement");
        assert_equals([200, 4, 4, 4], $a081['new_row'] ?? null, "{$a081Tag}: stock for a SKU without a warehouse row is booked in the default warehouse");
        assert_equals(['price_huge' => 400, 'stock_huge' => 400, 'weight_huge' => 400, 'compare_huge' => 400],
            array_intersect_key($a081, ['price_huge' => 1, 'stock_huge' => 1, 'weight_huge' => 1, 'compare_huge' => 1]),
            "{$a081Tag}: R7 PATCH price/stock/weight_g/compare_price past the MySQL INTEGER range is a 400 (was a 500 on MySQL)");
        assert_equals([200, ['price' => 2000000000, 'stock' => 4, 'weight_g' => 0, 'compare_price' => null]],
            [$a081['price_max'] ?? null, array_map(static fn($v) => $v === null ? null : (int)$v, (array)($a081['huge_unchanged'] ?? []))],
            "{$a081Tag}: R7 the limit itself is accepted and rejected values change nothing");
        assert_true(str_contains((string)($a081['adjust_over_max'] ?? ''), 'RuntimeException: Adjustment would exceed the stock limit')
            && ($a081['adjust_over_max_qty'] ?? null) === 1999999999,
            "{$a081Tag}: R7 a stock adjustment past the limit is refused and books nothing (" . ($a081['adjust_over_max'] ?? '?') . ')');

        [$a081Code, $a081Out, $a081Err] = $a081Run('race');
        $a081 = json_decode($a081Out, true);
        assert_true($a081Code === 0 && is_array($a081), "{$a081Tag}: concurrent probe runs (" . substr($a081Out . $a081Err, 0, 300) . ')');
        $a081 = is_array($a081) ? $a081 : [];
        assert_equals([[200, false], [200, true]], $a081['results'] ?? null, "{$a081Tag}: concurrent same Idempotency-Key — one applies, the other replays (no 500)");
        assert_equals(['A081 race renamed', 1, 1, 1], [$a081['name'] ?? null, $a081['outbox'] ?? null, $a081['audit'] ?? null, $a081['keys'] ?? null], "{$a081Tag}: concurrent retry mutates, audits and emits exactly once");
    }
} finally {
    if (isset($a081Drivers['mysql'])) $a081Php([$a081Root . '/probe.php', 'teardown', 'mysql', dirname(TEST_CLI_DIR), $a081Drivers['mysql']]);
    foreach (glob($a081Root . '/*') as $a081File) unlink($a081File);
    rmdir($a081Root);
}
