<?php

// Review round 30, finding 1 and a low: Ctrl-C in deploy/bbe-step.sh brought the prompt back (130), but when the tree
// belongs to another account (the usual production case, the step runs through setpriv) the step ran on and changed files
// and the database. The wrapper killed only unshare and relied on the parent-death signal of --kill-child, which the kernel
// clears when setpriv changes the user. The R29 test missed it: its marker was in a directory the owner cannot write.
// Also the step kept root's working directory.
echo "\n--- R30: Ctrl-C in bbe-step stops a step that runs as the tree's owner ---\n";
$r30sRepo = dirname(TEST_CLI_DIR);
if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid') || posix_geteuid() !== 0
    || trim((string)shell_exec('command -v setpriv 2>/dev/null')) === '' || trim((string)shell_exec('command -v bash 2>/dev/null')) === '') {
    echo "  [SKIP] R30 bbe-step: needs root, bash and setpriv\n";
    return;
}
$r30sDir = sys_get_temp_dir() . '/r30-step-sigint-' . getmypid();
$r30sWrap = $r30sRepo . '/deploy/bbe-step.sh';
$r30sApp = $r30sDir . '/app';
// Processes of the step still alive: anything running as the owner with the app's path on its command line.
$r30sAlive = static function () use ($r30sApp): array {
    $alive = [];
    foreach (glob('/proc/[0-9]*') ?: [] as $proc) {
        $cmd = str_replace("\0", ' ', (string)@file_get_contents($proc . '/cmdline'));
        if ($cmd !== '' && str_contains($cmd, $r30sApp) && @fileowner($proc) === 65534) $alive[] = basename($proc) . ': ' . trim($cmd);
    }
    return $alive;
};
try {
    mkdir($r30sApp . '/cli', 0755, true);
    chmod($r30sDir, 0755);
    // A step that starts a helper, then keeps changing a file (standing in for the database) for a few seconds.
    file_put_contents($r30sApp . '/cli/slow.php', '<?php
$app = dirname(__DIR__);
exec("(sleep 2; touch " . escapeshellarg("$app/helper-ran") . ") >/dev/null 2>&1 </dev/null &");
echo "started\n";
for ($i = 0; $i < 30; $i++) { usleep(100000); file_put_contents("$app/db", "$i\n", FILE_APPEND); }
touch("$app/done");
');
    file_put_contents($r30sApp . '/cli/cwd.php', '<?php echo getcwd(), "\n";');
    shell_exec('chown -R 65534 ' . escapeshellarg($r30sApp));
    assert_true(trim((string)shell_exec('setpriv --reuid=65534 --regid=65534 --clear-groups -- sh -c ' . escapeshellarg('touch ' . escapeshellarg($r30sApp . '/w') . ' && echo ok') . ' 2>&1')) === 'ok',
        'R30 bbe-step: the owner can write the app directory, so a marker the step leaves is real (the R29 test wrote where it could not)');
    @unlink($r30sApp . '/w');

    // Without pid namespaces (a container that forbids unshare) the step and its process group are killed instead.
    mkdir($r30sDir . '/nons', 0755);
    file_put_contents($r30sDir . '/nons/unshare', "#!/bin/sh\nexit 1\n");
    chmod($r30sDir . '/nons/unshare', 0755);
    foreach (['SIGINT' => [SIGINT, null], 'SIGHUP' => [SIGHUP, null], 'SIGINT without a pid namespace' => [SIGINT, $r30sDir . '/nons']] as $r30sName => [$r30sSignal, $r30sBin]) {
        foreach (['db', 'done', 'helper-ran'] as $r30sFile) @unlink($r30sApp . '/' . $r30sFile);
        $process = proc_open(['setsid', '/bin/sh', $r30sWrap, $r30sApp, 'slow.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
            $r30sBin === null ? null : ['PATH' => $r30sBin . ':' . (string)getenv('PATH')]);
        $r30sFirst = (string)fgets($pipes[1]);
        $r30sPid = (int)proc_get_status($process)['pid'];
        posix_kill(-$r30sPid, $r30sSignal); // what Ctrl-C (or a closed terminal) sends: the wrapper's whole process group
        $r30sStart = microtime(true);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $r30sExit = proc_close($process);
        $r30sTook = microtime(true) - $r30sStart;
        clearstatcache();
        $r30sDb = @filesize($r30sApp . '/db');
        sleep(4);
        clearstatcache();
        assert_true(trim($r30sFirst) === 'started' && $r30sTook < 2.5,
            "R30 bbe-step: $r30sName brings the prompt back at once (" . round($r30sTook, 2) . " s, exit $r30sExit)");
        assert_equals([[], false, false, $r30sDb], [$r30sAlive(), file_exists($r30sApp . '/done'), file_exists($r30sApp . '/helper-ran'), @filesize($r30sApp . '/db')],
            "R30 bbe-step: after $r30sName no process of the step runs as the owner and nothing changes any more (was: the step ran on through setpriv)");
    }

    // The step runs in the application root, not in root's working directory.
    mkdir($r30sDir . '/rootcwd', 0700);
    $process = proc_open(['/bin/sh', $r30sWrap, $r30sApp, 'cwd.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $r30sDir . '/rootcwd');
    $r30sCwd = trim((string)stream_get_contents($pipes[1]));
    $r30sErr = trim((string)stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    assert_equals((string)realpath($r30sApp), $r30sCwd, 'R30 bbe-step: the step runs in the application root, not root\'s working directory (' . $r30sErr . ')');
} finally {
    foreach ($r30sAlive() as $r30sLine) posix_kill((int)$r30sLine, SIGKILL);
    shell_exec('chmod -R u+rwx ' . escapeshellarg($r30sDir) . ' 2>/dev/null');
    FileSystem::removeTree($r30sDir);
}
