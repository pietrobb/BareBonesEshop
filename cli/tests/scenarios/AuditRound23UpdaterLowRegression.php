<?php

// Review round 23 (2026-11-08, kolo 7), low "updater snapshots and root":
// - pruning counted code-only and database snapshots apart, so it deleted a code-only snapshot between two kept ones;
//   rolling back from the newest then reached the older snapshot and left the deleted update's files: a mix of releases.
// - G5: run as root, the staged copy was handed over with lchown on its name after an inode check. With
//   fs.protected_hardlinks=0 the web user could hard-link e.g. /etc/shadow under that name in between, and root gave
//   it away. The owner is now set through the open descriptor (/proc/self/fd/N).

try {
    $r23lState = test_fixture_path('r23-updater-prune') . '/updates';
    mkdir(dirname($r23lState) . '/app', 0700, true);
    $r23lSnap = static function (int $i, bool $database, string $status) use ($r23lState): string {
        $id = sprintf('2026110800000%d-%012x', $i, 0x230 + $i);
        mkdir($r23lState . '/snapshots/' . $id, 0700, true);
        file_put_contents($r23lState . '/snapshots/' . $id . '/snapshot.json', json_encode(['id' => $id, 'reason' => 'pre-update', 'status' => $status,
            'created_at' => "2026-11-08T00:00:0{$i}+00:00", 'database' => $database ? ['driver' => 'sqlite', 'path' => 'database.sqlite'] : null]));
        return $id;
    };
    // A migration update, then three updates of code only, all still installed.
    $r23lIds = [1 => $r23lSnap(1, true, 'applied'), 2 => $r23lSnap(2, false, 'applied'), 3 => $r23lSnap(3, false, 'applied'), 4 => $r23lSnap(4, false, 'applied')];
    $r23lUpdater = new Updater(dirname($r23lState) . '/app', $r23lState);
    assert_equals([[], [true, true, true, true]], [$r23lUpdater->pruneSnapshots(2), array_map(static fn(string $id): bool => is_dir($r23lState . '/snapshots/' . $id), array_values($r23lIds))],
        'R23 snapshots: pruning keeps every installed update newer than the oldest kept snapshot (was: the code-only snapshot in the middle was deleted)');
    // A rolled-back update is out of the chain and still goes; the oldest end of the chain may go too.
    $r23lIds[5] = $r23lSnap(5, false, 'rolled-back');
    $r23lIds[6] = $r23lSnap(6, false, 'applied');
    $r23lIds[7] = $r23lSnap(7, false, 'applied');
    assert_equals([$r23lIds[5]], $r23lUpdater->pruneSnapshots(2), 'R23 snapshots: a rolled-back code-only snapshot is still pruned');
    foreach ([8, 9] as $i) $r23lIds[$i] = $r23lSnap($i, true, 'applied');
    assert_equals([$r23lIds[4], $r23lIds[3], $r23lIds[2], $r23lIds[1]], $r23lUpdater->pruneSnapshots(2),
        'R23 snapshots: once two newer database snapshots exist, the chain below the oldest kept snapshot goes as a whole (no hole)');
} catch (Throwable $e) {
    assert_true(false, 'R23 snapshot pruning (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

// G5: the descriptor still names the staged inode after somebody re-links the name.
if (DIRECTORY_SEPARATOR === '/' && is_dir('/proc/self/fd')) {
    $r23lDir = test_fixture_path('r23-owner');
    mkdir($r23lDir, 0700, true);
    $r23lVictim = $r23lDir . '/victim';
    $r23lStaged = $r23lDir . '/.staged.tmp';
    file_put_contents($r23lVictim, 'secret');
    $r23lHandle = fopen($r23lStaged, 'xb');
    $r23lInode = fstat($r23lHandle)['ino'];
    unlink($r23lStaged);
    link($r23lVictim, $r23lStaged); // the race: the name now is a hard link to another file
    $r23lPath = (new ReflectionMethod('FileOwnership', 'descriptorPath'))->invoke(null, $r23lHandle);
    clearstatcache();
    assert_true(is_string($r23lPath) && stat($r23lPath)['ino'] === $r23lInode && stat($r23lStaged)['ino'] !== $r23lInode,
        'R23 G5: the owner is set through /proc/self/fd, which still points at the staged inode after the name is re-linked');
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $r23lBefore = fileowner($r23lVictim);
        FileOwnership::handOver($r23lHandle, $r23lStaged, 65534, 65534);
        clearstatcache();
        assert_equals([$r23lBefore, 65534], [fileowner($r23lVictim), fstat($r23lHandle)['uid']],
            'R23 G5: as root, a hard link swapped in under the staged name is not handed over; the staged file is');
    }
    fclose($r23lHandle);
}
$r23lSources = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Updater.php') . (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/BackupService.php');
assert_true(substr_count($r23lSources, 'FileOwnership::handOver(') >= 2 && !str_contains($r23lSources, 'lchown($temporary') && !str_contains($r23lSources, 'applyOwnership($temporary')
    && !str_contains($r23lSources, 'applyOwnership($restoreTemp'),
    'R23 G5: the updater and the restore hand staged files over only through FileOwnership (not lchown by name)');
