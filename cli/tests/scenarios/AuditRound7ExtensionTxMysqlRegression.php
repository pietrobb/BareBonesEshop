<?php

// Round 7 review (2026-10-20), capability follow-up: an extension listener that opens its transaction with
// exec('BEGIN') is rolled back and reported as one failed delivery, also on native MySQL. Db::transactionOpen()
// used to query @@in_transaction, which only MariaDB has: MySQL 8.0 refused it (1193) and every delivery failed.
echo "\n--- Audit round 7: extension listener raw BEGIN on native MySQL ---\n";
if (!getenv('TEST_MYSQL_DSN')) {
    print "  [SKIP] Audit R7 extension raw BEGIN on MySQL (set TEST_MYSQL_DSN)\n";
    return;
}
$r7xRoot = test_fixture_path('r7-ext-tx');
mkdir($r7xRoot, 0777, true);
file_put_contents($r7xRoot . '/probe.php', <<<'PHP'
<?php
// php probe.php <phase> <root> <database> <dir>
[, $phase, $root, $database, $dir] = $argv;
require $root . '/src/autoload.php';
$db = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$database}`"); exit(0); }
$db->exec("CREATE DATABASE `{$database}`");
$db->exec("USE `{$database}`");
Db::init($db, 'mysql');
Bootstrap::initStoreContext('/', '', 'default');
(new Migrator($db, $root . '/migrations', $dir . '/migrate.lock'))->apply();
$out = ['server' => (string)$db->query('SELECT VERSION()')->fetchColumn()];
$db->beginTransaction();
$eventId = Outbox::record(DomainEvents::ORDER_CREATED, 'order', 97802, ['order_id' => 97802, 'order_number' => 'R7X-EXT']);
$db->commit();
$event = $db->query("SELECT * FROM event_outbox WHERE id = " . $db->quote((string)$eventId))->fetch();
$after = 0;
(new ReflectionProperty(Extensions::class, 'booted'))->setValue(null, true);
(new ReflectionProperty(Extensions::class, 'listeners'))->setValue(null, [
    'ext:r7x:raw' => ['events' => DomainEvents::ORDER_CREATED, 'listener' => static function () use ($db): void {
        $db->exec('BEGIN');
        $db->exec("INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES ('default', 'r7x-leak', 'R7X leak', NULL, 'r7x-leak', 0)");
    }],
    'ext:r7x:after' => ['events' => DomainEvents::ORDER_CREATED, 'listener' => static function () use (&$after): void { $after++; }],
]);
try {
    $result = Extensions::deliver($event, Outbox::envelope($event));
    $out['failures'] = $result['failures'];
} catch (Throwable $e) {
    $out['deliver_error'] = get_class($e) . ': ' . $e->getMessage();
}
$out['after_ran'] = $after;
$out['open'] = $db->inTransaction();
if ($out['open']) $db->rollBack();
$out['leaked'] = (int)$db->query("SELECT COUNT(*) FROM categories WHERE slug = 'r7x-leak'")->fetchColumn();
echo json_encode($out);
PHP);
$r7xDatabase = 'bbe_r7x_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
$r7xRun = static function (string $phase) use ($r7xRoot, $r7xDatabase): array {
    $process = proc_open([PHP_BINARY, $r7xRoot . '/probe.php', $phase, dirname(TEST_CLI_DIR), $r7xDatabase, $r7xRoot],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out, $err];
};
try {
    [$r7xCode, $r7xOut, $r7xErr] = $r7xRun('deliver');
    $r7x = json_decode($r7xOut, true);
    assert_true($r7xCode === 0 && is_array($r7x), 'R7 MySQL extensions: the delivery probe runs (' . substr($r7xOut . $r7xErr, 0, 300) . ')');
    $r7x = is_array($r7x) ? $r7x : [];
    $r7xTag = 'R7 MySQL extensions (' . ($r7x['server'] ?? '?') . ')';
    assert_equals(null, $r7x['deliver_error'] ?? null, "{$r7xTag}: delivering to a raw-BEGIN listener does not throw (was 1193 Unknown system variable 'in_transaction' on MySQL 8.0)");
    $r7xFailures = (array)($r7x['failures'] ?? []);
    assert_true(count($r7xFailures) === 1 && str_contains((string)$r7xFailures[0], 'ext:r7x:raw') && str_contains((string)$r7xFailures[0], 'transaction'),
        "{$r7xTag}: the raw-BEGIN listener is the single failed delivery naming the open transaction (" . json_encode($r7xFailures) . ')');
    assert_equals([1, false, 0], [$r7x['after_ran'] ?? null, $r7x['open'] ?? null, $r7x['leaked'] ?? null],
        "{$r7xTag}: the next listener still runs, no transaction is left open and the listener's write is rolled back");
} finally {
    $r7xRun('teardown');
    foreach (glob($r7xRoot . '/*') ?: [] as $r7xFile) @unlink($r7xFile);
    @rmdir($r7xRoot);
}
