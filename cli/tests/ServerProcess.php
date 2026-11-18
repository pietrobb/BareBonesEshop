<?php
/**
 * R45: test servers (php -S) that die with the test. proc_terminate() signals only the process proc_open() started; with
 * PHP_CLI_SERVER_WORKERS the php -S master dies and its forked workers stay alive, and a suite killed by timeout never
 * reaches its finally at all. On Linux/macOS the server runs in its own process group under a shell that waits for EOF
 * on a pipe from this process; the pipe closes when the test stops the server or when the test process dies in any
 * way (even SIGKILL), and the shell then kills the whole group. A SIGTERM to the shell (proc_terminate) kills the group
 * too, so `proc_terminate(); proc_close();` teardowns keep working. Same arguments as proc_open(); every php -S a
 * test starts must go through here (guarded by AuditRound45ServerSpawnRegression).
 */
function test_spawn_server(array $command, array $descriptors, ?array &$pipes = null, ?string $cwd = null, ?array $env = null)
{
    $pipes = [];
    if (PHP_OS_FAMILY === 'Windows' || !is_executable('/usr/bin/setsid') && !is_executable('/bin/setsid')) {
        return proc_open($command, $descriptors, $pipes, $cwd, $env);
    }
    // The lifeline is fd 3, not stdin: tests may close $pipes[0] right after the spawn. `wait` returns on a trapped
    // signal at once (a foreground cat would defer the trap until EOF).
    $descriptors[3] = ['pipe', 'r'];
    $script = 'trap "kill -KILL 0" TERM INT HUP; "$@" </dev/null 3<&- & cat <&3 >/dev/null & wait $!; kill -KILL 0';
    $process = proc_open(array_merge([is_executable('/usr/bin/setsid') ? '/usr/bin/setsid' : '/bin/setsid', '/bin/sh', '-c', $script, 'bbe-test-server'], $command),
        $descriptors, $pipes, $cwd, $env);
    return $process;
}

/** Stops a server from test_spawn_server(): closes the pipe (the group is killed) and reaps the shell. */
function test_stop_server($process, array &$pipes): void
{
    if (!is_resource($process)) return;
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    $pipes = [];
    if (PHP_OS_FAMILY === 'Windows') proc_terminate($process);
    for ($i = 0; $i < 50 && (proc_get_status($process)['running'] ?? false); $i++) usleep(100000);
    if (proc_get_status($process)['running'] ?? false) proc_terminate($process, 9);
    proc_close($process);
}

/** PIDs of live processes whose command line contains $needle (Linux /proc; [] where there is none). */
function test_processes_matching(string $needle): array
{
    $pids = [];
    foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $dir) {
        $cmdline = @file_get_contents($dir . '/cmdline');
        if (!is_string($cmdline) || !str_contains($cmdline, $needle)) continue;
        $stat = (string)@file_get_contents($dir . '/stat');
        if (preg_match('/\) Z /', $stat)) continue;
        $pids[] = (int)basename($dir);
    }
    return $pids;
}
