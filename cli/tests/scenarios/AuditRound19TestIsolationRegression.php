<?php

// Review round 19 (2026-11-06), medium "test isolation": the suite config kept the developer's db_sqlite_path, so the
// processes the suite starts (workers, HTTP children) opened the developer's database and marked its event_outbox rows
// delivered; the R16 theme-check fixture wrote its log lines into the developer's application.jsonl.
echo "\n--- R19: the suite's processes use the suite's database, fixtures keep to their own data directory ---\n";

$r19iRoot = dirname(TEST_CLI_DIR);
$r19iPrivate = str_replace('\\', '/', TEST_DATA_DIR) . '/private';
$r19iConfig = Security::config();
assert_equals([$r19iPrivate . '/db/shop.sqlite', str_replace('\\', '/', $testDbPath)],
    [str_replace('\\', '/', (string)($r19iConfig['db_sqlite_path'] ?? '')), $r19iPrivate . '/db/shop.sqlite'],
    'R19 isolation: the suite config names the suite\'s own SQLite database, the one the suite runs on');

// A child process started by the suite opens that database file.
if (Db::isSqlite()) {
    $r19iProcess = proc_open([PHP_BINARY, '-r', 'require ' . var_export($r19iRoot . '/src/autoload.php', true) . ';
        try { echo Db::get()->query("PRAGMA database_list")->fetchAll()[0]["file"]; } catch (Throwable $e) { echo "error: ", $e->getMessage(); }'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r19iPipes);
    $r19iSeen = trim(stream_get_contents($r19iPipes[1]) . stream_get_contents($r19iPipes[2]));
    fclose($r19iPipes[1]);
    fclose($r19iPipes[2]);
    proc_close($r19iProcess);
    assert_equals(realpath($testDbPath), realpath($r19iSeen) ?: $r19iSeen,
        'R19 isolation: a process the suite starts opens the suite\'s database, not the developer\'s');
}

// A fixture built from config.example.php the way the R16 theme-check copy is: nothing points at the developer's
// private directory (dirname of the checkout), the database and the logs included.
$r19iExample = require $r19iRoot . '/config.example.php';
$r19iFixture = AppConfig::suiteConfigForTesting($r19iExample, $r19iExample, '/tmp/r19-fixture/private');
$r19iDeveloper = str_replace('\\', '/', dirname($r19iRoot)) . '/bareboneseshop-private';
$r19iLeaks = array_keys(array_filter($r19iFixture, static fn($value): bool => is_string($value) && str_starts_with(str_replace('\\', '/', $value), $r19iDeveloper)));
assert_equals([], $r19iLeaks, 'R19 isolation: a fixture config from config.example.php keeps no path in the developer\'s private directory');
$r19iR16 = (string)file_get_contents(TEST_CLI_DIR . '/tests/scenarios/AuditRound16DevDocsRegression.php');
assert_true(str_contains($r19iR16, "AppConfig::suiteConfigForTesting(\$r16dExample, \$r16dExample, \$r16dTemp . '/private')"),
    'R19 isolation: the R16 theme-check fixture writes its config with every private path in its own directory');
