<?php

// Review round 28, findings 1 and 6.
// 1: cli/cli-only.php switches a step started as root to the owner of the application root, but it is code in a tree
//    the web user can often write: changed or replaced, root runs the copy. The docs claimed the root hole fixed; they
//    now say root never runs PHP of a tree another account can write, and deploy/bbe-step.sh switches outside the tree.
// 6: the 2.1.2 updater keeps root. Its 2.1.3 theme probe was refused by cli-only.php and the refusal read as "the
//    installed theme would break checkout"; and a state directory root created made the next step report "version
//    unknown" and "no snapshots". The probe now names the cause, and the updater refuses unreadable state with the fix.
echo "\n--- R28: root steps — truthful docs, wrapper outside the tree, root-owned update state ---\n";

$r28dRoot = dirname(TEST_CLI_DIR);
$r28dChangelog = (string)file_get_contents($r28dRoot . '/CHANGELOG.md');
$r28dReadme = (string)file_get_contents($r28dRoot . '/README.md');
$r28dRunbook = (string)file_get_contents($r28dRoot . '/docs/AI_UPDATE_RUNBOOK.md');
assert_true(!str_contains($r28dChangelog, 'no longer gives the web user root') && !str_contains($r28dChangelog, 'Fixed for every step at once')
    && str_contains($r28dChangelog, 'never run PHP of a tree another account can write') && str_contains($r28dReadme, 'bbe-step')
    && str_contains($r28dRunbook, 'bbe-step'),
    'R28 docs: no claim that a root step is safe in a tree the web user can write; the wrapper outside the tree is documented');
assert_true(str_contains($r28dChangelog, 'chown -R') && str_contains($r28dChangelog, 'as the owner of the application root')
    && str_contains($r28dChangelog, 'Not a theme problem'),
    'R28 upgrade notes: install 2.1.3 as the owner, chown what an update run as root left, the probe refusal is not the theme');

$r28dWrapper = (string)file_get_contents($r28dRoot . '/deploy/bbe-step.sh');
assert_true(str_starts_with($r28dWrapper, "#!/bin/sh\n") && !str_contains($r28dWrapper, "\r") && str_contains($r28dWrapper, 'setpriv --reuid=')
    && !preg_match('/\bphp\b[^\n]*cli\/cli-only/', $r28dWrapper),
    'R28 wrapper: POSIX sh, LF, switches with setpriv before PHP runs and loads nothing of the tree itself');

if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid')) {
    echo "  [SKIP] R28 root steps: POSIX owners (Windows)\n";
    return;
}

$r28dDir = sys_get_temp_dir() . '/r28-root-docs-' . getmypid();
$r28dRun = static function (array $command, ?string $cwd = null): array {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out, $err];
};
try {
    @mkdir($r28dDir . '/app', 0755, true);
    if (posix_geteuid() !== 0) {
        // Finding 6b: state left by a root update (a snapshot directory this user cannot list or write).
        $r28dState = $r28dDir . '/updates';
        mkdir($r28dState . '/snapshots/20261101-root', 0755, true);
        file_put_contents($r28dState . '/installed.json', '{"version":"2.1.2"}');
        chmod($r28dState . '/snapshots/20261101-root', 0500);
        $r28dError = '';
        try {
            new Updater($r28dRoot, $r28dState);
        } catch (RuntimeException $e) {
            $r28dError = $e->getMessage();
        }
        chmod($r28dState . '/snapshots/20261101-root', 0755);
        assert_true(str_contains($r28dError, '/snapshots/20261101-root') && str_contains($r28dError, 'chown -R')
            && str_contains($r28dError, 'never as root'),
            'R28 state: a snapshot directory this step cannot write is refused with the chown to run, not read as "no snapshots"');
        chmod($r28dState . '/installed.json', 0200);
        $r28dError = '';
        try {
            new Updater($r28dRoot, $r28dState);
        } catch (RuntimeException $e) {
            $r28dError = $e->getMessage();
        }
        chmod($r28dState . '/installed.json', 0644);
        assert_true(str_contains($r28dError, 'installed.json') && str_contains($r28dError, 'cannot read'),
            'R28 state: an installed.json this step cannot read is refused, not read as "version unknown"');
        $r28dOk = true;
        try {
            new Updater($r28dRoot, $r28dState);
        } catch (Throwable $e) {
            $r28dOk = false;
        }
        assert_true($r28dOk, 'R28 state: state this step can use is accepted');
        echo "  [SKIP] R28 probe and wrapper as root (needs root)\n";
        return;
    }

    // Finding 6a: the theme probe of the new release started as root (by the 2.1.2 updater) says why, as a verdict.
    [$r28dExit, $r28dOut, $r28dErr] = $r28dRun([PHP_BINARY, $r28dRoot . '/' . ThemeUpgradeGate::PROBE], $r28dRoot);
    $r28dVerdict = json_decode($r28dOut, true);
    assert_true($r28dExit === 1 && is_array($r28dVerdict) && ($r28dVerdict['status'] ?? '') === 'NO-GO'
        && str_contains((string)(($r28dVerdict['failures'] ?? [])[0] ?? ''), 'Not a theme problem')
        && str_contains((string)(($r28dVerdict['failures'] ?? [])[0] ?? ''), 'as the owner of the application root'),
        'R28 probe: started as root it returns a NO-GO verdict that names root, not the theme (' . trim($r28dErr . $r28dOut) . ')');

    // Finding 1: the wrapper switches before PHP runs; a root-owned tree or a path as step is refused.
    if (!is_executable('/bin/sh') || trim((string)shell_exec('command -v setpriv 2>/dev/null')) === '') {
        echo "  [SKIP] R28 wrapper: no setpriv\n";
        return;
    }
    mkdir($r28dDir . '/app/cli');
    file_put_contents($r28dDir . '/app/cli/whoami.php', "<?php echo posix_geteuid(), ' ', posix_getegid(), ' ', implode(',', posix_getgroups());\n");
    chown($r28dDir . '/app', 65534);
    chgrp($r28dDir . '/app', 0);
    chmod($r28dDir, 0755);
    [$r28dExit, $r28dOut, $r28dErr] = $r28dRun(['/bin/sh', $r28dRoot . '/deploy/bbe-step.sh', $r28dDir . '/app', 'whoami.php']);
    $r28dIds = explode(' ', trim($r28dOut));
    assert_true($r28dExit === 0 && ($r28dIds[0] ?? '') === '65534' && ($r28dIds[1] ?? '0') !== '0'
        && !in_array('0', explode(',', $r28dIds[2] ?? '0'), true),
        'R28 wrapper: a step runs as the owner of the application root, never with group root (' . trim($r28dErr . $r28dOut) . ')');
    // Capability review run 399: the step must not keep root's terminal as its controlling terminal, or code the owner
    // can change types into root's shell with TIOCSTI. Run on a pty (script); the step may use the terminal for its
    // output but cannot open /dev/tty, so TIOCSTI fails whatever dev.tty.legacy_tiocsti says. Its exit status comes back.
    file_put_contents($r28dDir . '/app/cli/tty.php', "<?php \$t = @fopen('/dev/tty', 'r');\n"
        . "echo 'TTY0=', posix_isatty(0) ? 'yes' : 'no', ' CTTY=', \$t === false ? 'none' : 'root-terminal', ' SID=', posix_getsid(0) === posix_getpid() ? 'own' : 'shared', \"\\n\";\nexit(3);\n");
    if (trim((string)shell_exec('command -v script 2>/dev/null')) !== '') {
        $r28dCmd = '/bin/sh ' . escapeshellarg($r28dRoot . '/deploy/bbe-step.sh') . ' ' . escapeshellarg($r28dDir . '/app') . ' tty.php';
        [, $r28dOut, $r28dErr] = $r28dRun(['script', '-qec', $r28dCmd, '/dev/null']);
        assert_true(str_contains($r28dOut, 'TTY0=yes CTTY=none SID=own'),
            'R28 wrapper: on root\'s terminal the step runs in its own session without a controlling terminal, so it cannot type into root\'s shell (' . trim($r28dErr . $r28dOut) . ')');
    } else {
        echo "  [SKIP] R28 wrapper on a terminal: no script(1)\n";
    }
    [$r28dExit, $r28dOut] = $r28dRun(['/bin/sh', $r28dRoot . '/deploy/bbe-step.sh', $r28dDir . '/app', 'tty.php']);
    assert_true($r28dExit === 3 && str_contains($r28dOut, 'CTTY=none'), 'R28 wrapper: the step\'s exit status is the wrapper\'s, and the step has no controlling terminal (' . trim($r28dOut) . ')');
    [$r28dExit, , $r28dErr] = $r28dRun(['/bin/sh', $r28dRoot . '/deploy/bbe-step.sh', $r28dDir . '/app', '../whoami.php']);
    assert_true($r28dExit === 2 && str_contains($r28dErr, 'file name in cli/'), 'R28 wrapper: a path as step is refused');
    // R29 (finding 1): a tree root owns alone runs as root (AuditRound29RootLockRegression); one another account can change does not.
    chown($r28dDir . '/app', 0);
    chmod($r28dDir . '/app', 0775);
    [$r28dExit, $r28dOut, $r28dErr] = $r28dRun(['/bin/sh', $r28dRoot . '/deploy/bbe-step.sh', $r28dDir . '/app', 'whoami.php']);
    assert_true($r28dExit === 1 && $r28dOut === '' && str_contains($r28dErr, 'belongs to root, but') && str_contains($r28dErr, 'can be changed by another account'),
        'R28 wrapper: a root-owned tree another account can change is refused before PHP runs (' . trim($r28dErr) . ')');
} finally {
    if (is_dir($r28dDir)) {
        shell_exec('chmod -R u+rwx ' . escapeshellarg($r28dDir) . ' 2>/dev/null');
        FileSystem::removeTree($r28dDir);
    }
}
