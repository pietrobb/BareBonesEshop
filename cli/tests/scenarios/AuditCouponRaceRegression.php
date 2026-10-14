<?php

// Finding 13b: concurrent redemptions on a real second connection cannot exceed max_uses.
$a062Root = test_fixture_path('coupon-race');
mkdir($a062Root);
file_put_contents($a062Root . '/race.php', <<<'PHP'
<?php
[, $driver, $phase, $root, $target] = $argv;
require $root . '/src/autoload.php';
$connect = static function() use ($driver, $target): PDO {
    $db = $driver === 'mysql'
        ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
        : new PDO('sqlite:' . $target, null, null, [PDO::ATTR_TIMEOUT => 1]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $db;
};
$a = $connect();
if ($driver === 'mysql') {
    if ($phase === 'teardown') { $a->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    $a->exec("CREATE DATABASE `{$target}`");
    $a->exec("USE `{$target}`");
}
Db::init($a, $driver);
(new Migrator($a, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
$b = $connect();
if ($driver === 'mysql') { $b->exec("USE `{$target}`"); $b->exec('SET SESSION innodb_lock_wait_timeout = 1'); }
foreach (['R1', 'R2'] as $number) {
    $a->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id) VALUES (?, 'pending', 'pending', 'A062', 'a062@example.test', 'card', 1000, 1000, 'eur', 'default')")->execute(['A062-' . $number]);
}
$ids = $a->query("SELECT id FROM orders ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
Pricing::setConfig(['cart_rules' => [['type' => 'coupon', 'active' => true, 'code' => 'A062MAX', 'name' => 'A062 single use', 'discount_type' => 'fixed', 'discount_value' => 100, 'max_uses' => 1]]]);
$use = static function(PDO $db, int $orderId, string $email) use ($driver): string {
    Db::init($db, $driver);
    try {
        Pricing::recordCouponUse('A062MAX', $orderId, $email, 100);
        return 'ok';
    } catch (Throwable $e) {
        return 'refused';
    }
};
$a->beginTransaction();
$first = $use($a, (int)$ids[0], 'first@example.test');
$b->beginTransaction();
$concurrent = $use($b, (int)$ids[1], 'second@example.test');
if ($concurrent === 'ok') $b->commit(); else $b->rollBack();
$a->commit();
$b->beginTransaction();
$after = $use($b, (int)$ids[1], 'second@example.test');
if ($after === 'ok') $b->commit(); else $b->rollBack();
echo json_encode([$first, $concurrent, $after, (int)$a->query("SELECT COUNT(*) FROM coupon_uses WHERE coupon_code = 'A062MAX'")->fetchColumn()]);
PHP);
$a062Php = static function(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
$a062Drivers = ['sqlite' => $a062Root . '/race.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a062Drivers['mysql'] = 'bbe_a062_coupon_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A06.2 native coupon race (set TEST_MYSQL_DSN)\n";
try {
    foreach ($a062Drivers as $a062Driver => $a062Target) {
        [$a062Code, $a062Out] = $a062Php([$a062Root . '/race.php', $a062Driver, 'run', dirname(TEST_CLI_DIR), $a062Target]);
        assert_equals([0, ['ok', 'refused', 'refused', 1]], [$a062Code, json_decode($a062Out, true) ?? $a062Out], "A06.2 {$a062Driver} coupon: concurrent second redemption cannot exceed max_uses=1");
    }
} finally {
    if (isset($a062Drivers['mysql'])) $a062Php([$a062Root . '/race.php', 'mysql', 'teardown', dirname(TEST_CLI_DIR), $a062Drivers['mysql']]);
    foreach (glob($a062Root . '/*') as $a062File) unlink($a062File);
    rmdir($a062Root);
}
