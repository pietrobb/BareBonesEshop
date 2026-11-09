<?php

// Review round 25 (2026-11-09, "kolo 9"), finding 5 (goal #385): the R24 step lock <app root>/.maintenance.lock
// - was created 0644 by whoever ran a step first: made by root, it shut the web user out (cron or a container as
//   www-data: "Cannot open the maintenance step lock", every migrate/restore/update/rollback failed for good), and any
//   local account could open it to read and flock it, holding off every step with an empty reason in the message;
// - on a file system without file locks (NFS without lockd, CIFS nobrl) refused every step as "another window is open".
echo "\n--- R25: the maintenance step lock — permissions, holder, file systems without locks ---\n";
$r25lRoot = test_fixture_path('r25-steplock');
@mkdir($r25lRoot, 0700, true);
$r25lLock = $r25lRoot . '/' . MaintenanceMode::LOCK_FILE;
try {
    // 1. Created group-writable and not world-readable, whatever the umask.
    $r25lUmask = umask(0022);
    try {
        $r25lClaimed = MaintenanceMode::claim($r25lRoot);
    } finally {
        umask($r25lUmask);
    }
    clearstatcache();
    assert_equals([true, PHP_OS_FAMILY === 'Windows' ? true : 0660], [$r25lClaimed, PHP_OS_FAMILY === 'Windows' ? true : (fileperms($r25lLock) & 0777)],
        'R25 step lock: created 0660 under umask 022 (was 0644: other accounts could open and flock it, root\'s shut www-data out)');
    MaintenanceMode::unclaim($r25lRoot);
    clearstatcache();
    assert_equals(0, filesize($r25lLock), 'R25 step lock: emptied when the step lets go');

    // 2. A step refused while another holds the lock says who holds it (was an empty reason).
    file_put_contents($r25lRoot . '/hold.php', '<?php
require ' . var_export(dirname(TEST_CLI_DIR) . '/src/autoload.php', true) . ';
echo MaintenanceMode::claim($argv[1]) ? "held\n" : "refused\n";
fgets(STDIN);
');
    $r25lChild = proc_open([PHP_BINARY, $r25lRoot . '/hold.php', $r25lRoot, 'r25-holder'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r25lPipes);
    $r25lHeld = trim((string)fgets($r25lPipes[1]));
    $r25lRefused = !MaintenanceMode::claim($r25lRoot);
    $r25lBusy = MaintenanceMode::busy($r25lRoot);
    fwrite($r25lPipes[0], "\n");
    fclose($r25lPipes[0]);
    fclose($r25lPipes[1]);
    fclose($r25lPipes[2]);
    proc_close($r25lChild);
    assert_equals(['held', true, true, true], [$r25lHeld, $r25lRefused, str_contains($r25lBusy, 'step lock held by pid '), str_contains($r25lBusy, 'hold.php')],
        'R25 step lock: a refused step names the process that holds the lock — ' . $r25lBusy);
    assert_true(MaintenanceMode::claim($r25lRoot), 'R25 step lock: free again once the holder ended');
    MaintenanceMode::unclaim($r25lRoot);

    // 3. FileOwnership sets the permission bits through the open descriptor (where /proc is there).
    if (is_dir('/proc/self/fd')) {
        $r25lFile = $r25lRoot . '/owned';
        $r25lHandle = fopen($r25lFile, 'c');
        FileOwnership::handOver($r25lHandle, $r25lFile, null, null, 0640);
        fclose($r25lHandle);
        clearstatcache();
        assert_equals(0640, fileperms($r25lFile) & 0777, 'R25 step lock: FileOwnership::handOver() sets the mode through the descriptor');
    }

    // 4. A file system that refuses every lock (not "would block"): the step runs, warned, instead of being refused for good.
    if (!class_exists('R25NoLockStream', false)) {
        final class R25NoLockStream
        {
            public $context;
            public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool { return true; }
            public function stream_lock(int $operation): bool { return false; }
            public function stream_truncate(int $size): bool { return true; }
            public function stream_write(string $data): int { return strlen($data); }
            public function stream_flush(): bool { return true; }
            public function stream_close(): void {}
            public function stream_stat(): array|false { return false; }
            public function stream_set_option(int $option, int $arg1, ?int $arg2): bool { return false; }
        }
    }
    if (!in_array('r25nolock', stream_get_wrappers(), true)) stream_wrapper_register('r25nolock', 'R25NoLockStream');
    $r25lNoLock = PHP_OS_FAMILY === 'Windows' ? true : MaintenanceMode::claim('r25nolock://shop');
    MaintenanceMode::unclaim('r25nolock://shop');
    assert_true($r25lNoLock, 'R25 step lock: without file locks a step runs (was: refused for good as "another window is open")');

    $r25lSource = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/MaintenanceMode.php');
    assert_true(str_contains($r25lSource, 'FileOwnership::handOver($handle, $path, $app[\'uid\'], $app[\'gid\'], 0660)'),
        'R25 step lock: run as root, the lock goes to the owner and group of the app root (0660)');
} catch (Throwable $e) {
    assert_true(false, 'R25 step lock (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    MaintenanceMode::unclaim($r25lRoot);
    if (function_exists('updateTestRemoveTree')) updateTestRemoveTree($r25lRoot);
}
