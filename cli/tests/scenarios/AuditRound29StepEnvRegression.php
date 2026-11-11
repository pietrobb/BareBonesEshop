<?php

// Review round 29, finding 3 and a low: deploy/bbe-step.sh gave the step root's terminal as stdin/stdout/stderr (a
// process the step left running read what root typed after the prompt came back: `mysql -pHunter2`), Ctrl-C stopped only
// the wrapper while the step ran on, the step got root's environment and open files, and it took the tree's group
// (e.g. disk) even when the owner was not in it.
echo "\n--- R29: bbe-step gives the step no terminal, environment or file of root's, and nothing outlives it ---\n";
$r29eRepo = dirname(TEST_CLI_DIR);
$r29eWrapper = (string)file_get_contents($r29eRepo . '/deploy/bbe-step.sh');
assert_true(str_contains($r29eWrapper, '</dev/null') && str_contains($r29eWrapper, 'env -i') && str_contains($r29eWrapper, 'unshare --pid --fork --kill-child=SIGKILL')
    && str_contains($r29eWrapper, 'from a release you verified'),
    'R29 wrapper: stdin /dev/null, a clean environment, a pid namespace; installed from a verified release, not the shop\'s copy');
if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid') || posix_geteuid() !== 0
    || trim((string)shell_exec('command -v setpriv 2>/dev/null')) === '' || trim((string)shell_exec('command -v bash 2>/dev/null')) === '') {
    echo "  [SKIP] R29 bbe-step: needs root, bash and setpriv\n";
    return;
}
$r29eNs = trim((string)shell_exec('unshare --pid --fork --kill-child=SIGKILL true 2>/dev/null && echo yes')) === 'yes';
$r29eDir = sys_get_temp_dir() . '/r29-step-env-' . getmypid();
$r29eWrap = $r29eRepo . '/deploy/bbe-step.sh';
try {
    mkdir($r29eDir . '/app/cli', 0755, true);
    chmod($r29eDir, 0755);
    // What the step can see: its descriptors (and where they point), its environment, its ids.
    file_put_contents($r29eDir . '/app/cli/probe.php', '<?php
$fds = [];
foreach (scandir("/proc/self/fd") as $fd) if (ctype_digit($fd)) $fds[$fd] = (string)@readlink("/proc/self/fd/$fd");
echo json_encode(["fds" => $fds, "env" => getenv(), "uid" => posix_geteuid(), "gid" => posix_getegid(), "groups" => posix_getgroups(),
    "stdin" => stream_get_contents(STDIN)]), "\n";
');
    // A step that leaves a process behind, which writes a file after the step ended.
    file_put_contents($r29eDir . '/app/cli/linger.php', '<?php
exec("(sleep 2; touch " . escapeshellarg($argv[1]) . ") >/dev/null 2>&1 </dev/null &");
echo "started\n";
');
    file_put_contents($r29eDir . '/app/cli/slow.php', '<?php
echo "started\n";
sleep(4);
touch($argv[1]);
');
    chown($r29eDir . '/app', 65534);
    chgrp($r29eDir . '/app', 6); // disk: nobody is not a member
    $r29eNobodyGid = (int)(posix_getpwuid(65534)['gid'] ?? 65534);

    // 1. Descriptors, environment and group: started with an extra open file and secrets in root's environment.
    $r29eSecret = $r29eDir . '/root-secret.txt';
    file_put_contents($r29eSecret, 'root only');
    $process = proc_open(['/bin/sh', $r29eWrap, $r29eDir . '/app', 'probe.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 7 => ['file', $r29eSecret, 'r'], 12 => ['file', $r29eSecret, 'r']],
        $pipes, null, ['PATH' => (string)getenv('PATH'), 'AWS_SECRET_ACCESS_KEY' => 'root-key', 'MYSQL_PWD' => 'Hunter2',
            'BBE_ADMIN_PASSWORD' => 'kept', 'SMTP_HOST' => 'mail.test', 'HOME' => '/root', 'PHPRC' => $r29eDir . '/evil.ini']);
    fwrite($pipes[0], "typed by root\n");
    fclose($pipes[0]);
    $r29eOut = (string)stream_get_contents($pipes[1]);
    $r29eErr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $r29eExit = proc_close($process);
    $r29eSeen = json_decode(trim($r29eOut), true) ?? [];
    $r29eFds = (array)($r29eSeen['fds'] ?? []);
    assert_true($r29eExit === 0 && ($r29eSeen['stdin'] ?? null) === '' && ($r29eFds[0] ?? '') === '/dev/null',
        'R29 bbe-step: stdin is /dev/null, so the step reads nothing root types (' . trim($r29eErr . $r29eOut) . ')');
    assert_true(str_starts_with((string)($r29eFds[1] ?? ''), 'pipe:') && str_starts_with((string)($r29eFds[2] ?? ''), 'pipe:'),
        'R29 bbe-step: stdout and stderr are pipes root copies, not root\'s terminal (' . json_encode($r29eFds) . ')');
    assert_equals([], array_values(array_filter($r29eFds, static fn(string $t): bool => $t === $r29eSecret || str_starts_with($t, '/dev/pts') || str_starts_with($t, '/dev/tty'))),
        'R29 bbe-step: no file root had open (fd 7, fd 12) and no terminal reaches the step');
    $r29eEnv = (array)($r29eSeen['env'] ?? []);
    assert_equals([null, null, null, 'kept', 'mail.test', (string)(posix_getpwuid(65534)['dir'] ?? '')],
        [$r29eEnv['AWS_SECRET_ACCESS_KEY'] ?? null, $r29eEnv['MYSQL_PWD'] ?? null, $r29eEnv['PHPRC'] ?? null,
            $r29eEnv['BBE_ADMIN_PASSWORD'] ?? null, $r29eEnv['SMTP_HOST'] ?? null, $r29eEnv['HOME'] ?? null],
        'R29 bbe-step: root\'s environment is not passed, only the variables a step reads (BBE_*, SMTP_* …), HOME is the owner\'s');
    assert_true(($r29eSeen['uid'] ?? 0) === 65534 && ($r29eSeen['gid'] ?? 0) === $r29eNobodyGid && !in_array(6, (array)($r29eSeen['groups'] ?? []), true),
        'R29 bbe-step: the step does not take the tree\'s group (disk) its owner is not in (' . json_encode([$r29eSeen['gid'] ?? null, $r29eSeen['groups'] ?? null]) . ')');

    // 2. Exit status passes through the pipes.
    file_put_contents($r29eDir . '/app/cli/fail.php', "<?php fwrite(STDERR, \"bad\\n\"); exit(5);\n");
    $process = proc_open(['/bin/sh', $r29eWrap, $r29eDir . '/app', 'fail.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $r29eOut = (string)stream_get_contents($pipes[1]);
    $r29eErr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    assert_true(proc_close($process) === 5 && $r29eOut === '' && trim($r29eErr) === 'bad', 'R29 bbe-step: the step\'s exit status and stderr come back unchanged');

    if (!$r29eNs) {
        echo "  [SKIP] R29 bbe-step: no pid namespaces here (unshare refused)\n";
        return;
    }
    // 3. Nothing the step started outlives it.
    $r29eLate = $r29eDir . '/app/late'; // R30: where the owner can write (in $r29eDir it never could, so this proved nothing)
    $process = proc_open(['/bin/sh', $r29eWrap, $r29eDir . '/app', 'linger.php', $r29eLate], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $r29eOut = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $r29eExit = proc_close($process);
    sleep(3);
    assert_true($r29eExit === 0 && trim($r29eOut) === 'started' && !file_exists($r29eLate),
        'R29 bbe-step: a process the step leaves running ends with it (was: it read root\'s terminal after the prompt came back)');

    // 4. Ctrl-C (SIGINT to the wrapper's process group) stops the step, not only the wrapper.
    $r29eDone = $r29eDir . '/app/done'; // R30: the owner can write here
    $process = proc_open(['setsid', '/bin/sh', $r29eWrap, $r29eDir . '/app', 'slow.php', $r29eDone], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $r29eFirst = (string)fgets($pipes[1]);
    $r29ePid = (int)proc_get_status($process)['pid'];
    posix_kill(-$r29ePid, SIGINT);
    $r29eStart = microtime(true);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $r29eTook = microtime(true) - $r29eStart;
    sleep(5);
    assert_true(trim($r29eFirst) === 'started' && $r29eTook < 3 && !file_exists($r29eDone),
        'R29 bbe-step: Ctrl-C stops the step (was: only the wrapper; the step ran on and read the next input)');
} finally {
    shell_exec('chmod -R u+rwx ' . escapeshellarg($r29eDir) . ' 2>/dev/null');
    FileSystem::removeTree($r29eDir);
}
