<?php

// CR-M11: a process that dies during restore/rollback (fatal, OOM, kill) never runs `finally`, so
// the maintenance marker stayed and Db::get() refused every request until someone deleted it.
echo "\n--- CR-M11: stale SQLite maintenance marker after a crashed restore ---\n";
$smRoot = test_fixture_path('sqlite-maintenance-stale');
mkdir($smRoot);
$smDb = $smRoot . '/shop.sqlite';
(new PDO('sqlite:' . $smDb))->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY)');
$smMarker = SqliteMaintenance::markerPath($smDb);
file_put_contents($smRoot . '/child.php', '<?php
require ' . var_export(dirname(TEST_CLI_DIR) . '/src/autoload.php', true) . ';
SqliteMaintenance::enter($argv[1], "restore");
echo "entered\n";
if ($argv[2] === "hold") fgets(STDIN); // keep the gate up until the parent closes stdin
exit(1); // dies inside the operation: leave() is never called
');
$smChild = static function (string $mode) use ($smRoot, $smDb): array {
    $process = proc_open([PHP_BINARY, $smRoot . '/child.php', $smDb, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return [$process, $pipes];
};
$smAge = static fn() => touch($smMarker, time() - 60) && clearstatcache();
try {
    // 1. The owner is still running: the gate stays up even when the marker is old.
    [$smProc, $smPipes] = $smChild('hold');
    $smEntered = trim((string)fgets($smPipes[1]));
    $smAge();
    assert_true($smEntered === 'entered' && SqliteMaintenance::isActive($smDb), 'CR-M11: maintenance held by a running process stays active');
    fclose($smPipes[0]);
    stream_get_contents($smPipes[1]);
    fclose($smPipes[1]);
    fclose($smPipes[2]);
    proc_close($smProc);

    // 2. That process died without leave(): the marker is left behind.
    assert_true(is_file($smMarker), 'CR-M11: a crashed restore leaves its marker on disk');
    $smAge();
    assert_true(!SqliteMaintenance::isActive($smDb), 'CR-M11: a stale marker of a dead process no longer blocks the shop');
    assert_true(!is_file($smMarker), 'CR-M11: the stale marker is removed');
    SqliteMaintenance::exclusive($smDb, 'next restore', static fn() => null, 1.0);
    assert_true(!SqliteMaintenance::isActive($smDb), 'CR-M11: the next restore can run and lifts its gate');

    // 3. Crash between moving the live file aside and moving the replacement in: keep the shop closed.
    [$smProc, $smPipes] = $smChild('die');
    stream_get_contents($smPipes[1]);
    fclose($smPipes[0]);
    fclose($smPipes[1]);
    fclose($smPipes[2]);
    proc_close($smProc);
    rename($smDb, $smDb . '.pre-restore-test.rollback');
    $smAge();
    assert_true(SqliteMaintenance::isActive($smDb), 'CR-M11: with the live database missing the gate stays up (no empty database is created)');
    rename($smDb . '.pre-restore-test.rollback', $smDb);
    assert_true(!SqliteMaintenance::isActive($smDb), 'CR-M11: once the database is back, the stale gate clears');

    // 4. A fresh marker is never treated as stale (enter() creates it, then locks it).
    file_put_contents($smMarker, '');
    assert_true(SqliteMaintenance::isActive($smDb), 'CR-M11: an unlocked marker inside the grace period still counts as active');
    unlink($smMarker);
} finally {
    foreach (glob($smRoot . '/*') ?: [] as $smFile) @unlink($smFile);
    @rmdir($smRoot);
}
