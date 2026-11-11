<?php

// Review round 32, lows 1-3 in deploy/bbe-step.sh: (1) the wrapper took every tool from root's PATH after `cd` into the
// tree, so an empty entry or `.` ran the owner's own `setsid` as uid 0; (2) only the inner shell trapped signals, so a
// SIGTERM to the wrapper's main process alone (kill <pid>, a supervisor, sudo relaying it) left the step running; (3) the
// scan for pid 1 read only the first line of /proc/PID/stat, so a step that put a newline into its name dodged Ctrl-C.
echo "\n--- R32: bbe-step ignores root's PATH, stops on SIGTERM to the wrapper and on a step with a newline in its name ---\n";
$r32sRepo = dirname(TEST_CLI_DIR);
if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid') || posix_geteuid() !== 0
    || trim((string)shell_exec('command -v setpriv 2>/dev/null')) === '' || trim((string)shell_exec('command -v bash 2>/dev/null')) === '') {
    echo "  [SKIP] R32 bbe-step: needs root, bash and setpriv\n";
    return;
}
$r32sDir = sys_get_temp_dir() . '/r32-step-' . getmypid();
$r32sWrap = $r32sRepo . '/deploy/bbe-step.sh';
$r32sApp = $r32sDir . '/app';
$r32sAlive = static function () use ($r32sApp): array {
    $alive = [];
    foreach (glob('/proc/[0-9]*') ?: [] as $proc) {
        $cmd = str_replace("\0", ' ', (string)@file_get_contents($proc . '/cmdline'));
        if ($cmd !== '' && str_contains($cmd, $r32sApp) && @fileowner($proc) === 65534) $alive[] = basename($proc) . ': ' . trim($cmd);
    }
    return $alive;
};
try {
    mkdir($r32sApp . '/cli', 0755, true);
    mkdir($r32sDir . '/rootcwd', 0700);
    chmod($r32sDir, 0755);
    $r32sSlow = '<?php
$app = dirname(__DIR__);
%s
echo "started\n";
for ($i = 0; $i < 30; $i++) { usleep(100000); file_put_contents("$app/db", "$i\n", FILE_APPEND); }
touch("$app/done");
';
    file_put_contents($r32sApp . '/cli/slow.php', sprintf($r32sSlow, ''));
    // Low 3: the step's own name gets a newline, as a step that wants to outlive Ctrl-C would do.
    file_put_contents($r32sApp . '/cli/rename.php', sprintf($r32sSlow, '@file_put_contents("/proc/self/comm", "a\nb) S 1 1 1");'));
    file_put_contents($r32sApp . '/cli/cwd.php', '<?php echo getcwd(), "\n";');
    // Low 1: a `setsid` of the owner's in the application root, the directory the wrapper cd's into.
    file_put_contents($r32sApp . '/setsid', "#!/bin/sh\nid -u > " . escapeshellarg($r32sDir . '/spoofed') . "\nexec /usr/bin/setsid \"\$@\"\n");
    chmod($r32sApp . '/setsid', 0755);
    shell_exec('chown -R 65534 ' . escapeshellarg($r32sApp));

    foreach ([':/usr/bin:/bin' => 'an empty entry', '.:/usr/bin:/bin' => '"."'] as $r32sPath => $r32sLabel) {
        @unlink($r32sDir . '/spoofed');
        $process = proc_open(['/bin/sh', $r32sWrap, $r32sApp, 'cwd.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $r32sDir . '/rootcwd',
            ['PATH' => $r32sPath]);
        $r32sOut = trim((string)stream_get_contents($pipes[1]));
        $r32sErr = trim((string)stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $r32sExit = proc_close($process);
        assert_equals([0, (string)realpath($r32sApp), false], [$r32sExit, $r32sOut, file_exists($r32sDir . '/spoofed')],
            "R32 bbe-step: with $r32sLabel in root's PATH the step runs and the owner's setsid in the tree does not run as root ($r32sErr)");
    }

    $r32sCases = [
        'SIGTERM to the wrapper alone' => ['slow.php', static fn(int $pid): bool => posix_kill($pid, SIGTERM)],
        'Ctrl-C on a step with a newline in its name' => ['rename.php', static fn(int $pid): bool => posix_kill(-$pid, SIGINT)],
    ];
    foreach ($r32sCases as $r32sName => [$r32sStep, $r32sSend]) {
        foreach (['db', 'done'] as $r32sFile) @unlink($r32sApp . '/' . $r32sFile);
        $process = proc_open(['setsid', '/bin/sh', $r32sWrap, $r32sApp, $r32sStep], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $r32sFirst = (string)fgets($pipes[1]);
        $r32sSend((int)proc_get_status($process)['pid']);
        $r32sStart = microtime(true);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $r32sExit = proc_close($process);
        $r32sTook = microtime(true) - $r32sStart;
        clearstatcache();
        $r32sDb = @filesize($r32sApp . '/db');
        sleep(4);
        clearstatcache();
        assert_true(trim($r32sFirst) === 'started' && $r32sTook < 2.5,
            "R32 bbe-step: $r32sName brings the prompt back at once (" . round($r32sTook, 2) . " s, exit $r32sExit)");
        assert_equals([[], false, $r32sDb], [$r32sAlive(), file_exists($r32sApp . '/done'), @filesize($r32sApp . '/db')],
            "R32 bbe-step: after $r32sName no process of the step runs as the owner and nothing changes any more");
    }
} finally {
    foreach ($r32sAlive() as $r32sLine) posix_kill((int)$r32sLine, SIGKILL);
    shell_exec('chmod -R u+rwx ' . escapeshellarg($r32sDir) . ' 2>/dev/null');
    FileSystem::removeTree($r32sDir);
}
