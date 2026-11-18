<?php

// Round 45 (ideas #52/#58, capability review run 554): only the R12 web-worker test started its php -S through
// test_spawn_server(); 27 other scenarios used a bare proc_open() and a finally that a suite killed by its timeout never
// reaches, so their servers outlived the run. Every scenario server now goes through the helper, and the helper also
// survives the usual teardown (`proc_terminate(); proc_close();`) and an early fclose($pipes[0]).
echo "\n--- Audit round 45: every test php -S server dies with the test ---\n";

$r45sBare = [];
foreach (glob(TEST_CLI_DIR . '/tests/scenarios/*.php') ?: [] as $r45sFile) {
    if (preg_match_all('/\bproc_open\(\s*(?:array_merge\(\s*)?\[PHP_BINARY,[^\]]*?\'-S\'/s', (string)file_get_contents($r45sFile), $r45sHits)) {
        $r45sBare[] = basename($r45sFile) . ' x' . count($r45sHits[0]);
    }
}
assert_equals([], $r45sBare, 'R45 servers: no scenario starts php -S with a bare proc_open() (use test_spawn_server)');

if (PHP_OS_FAMILY !== 'Windows') {
    // [label, close stdin right after the spawn (what many scenarios do)]
    foreach ([['teardown', false], ['early stdin close', true]] as [$r45sLabel, $r45sCloseStdin]) {
        $r45sBase = test_fixture_path('r45-server-term');
        mkdir($r45sBase, 0777, true);
        file_put_contents($r45sBase . '/ping.php', '<?php echo "pong";');
        $r45sProbe = stream_socket_server('tcp://127.0.0.1:0');
        $r45sPort = (int)substr(strrchr((string)stream_socket_get_name($r45sProbe, false), ':'), 1);
        fclose($r45sProbe);
        $r45sServer = test_spawn_server([PHP_BINARY, '-S', "127.0.0.1:{$r45sPort}", '-t', $r45sBase],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $r45sPipes, $r45sBase,
            array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '2']));
        if ($r45sCloseStdin) fclose($r45sPipes[0]);
        $r45sGet = static fn(): string => (string)@file_get_contents("http://127.0.0.1:{$r45sPort}/ping.php", false, stream_context_create(['http' => ['timeout' => 2]]));
        for ($i = 0; $i < 50 && $r45sGet() !== 'pong'; $i++) usleep(100000);
        assert_equals('pong', $r45sGet(), "R45 servers ({$r45sLabel}): the server is up and serving");
        $r45sStarted = count(test_processes_matching($r45sBase));
        proc_terminate($r45sServer);
        proc_close($r45sServer);
        for ($i = 0; $i < 30 && test_processes_matching($r45sBase) !== []; $i++) usleep(100000);
        $r45sLeft = test_processes_matching($r45sBase);
        if ($r45sLeft !== []) exec('kill -9 ' . implode(' ', $r45sLeft));
        @unlink($r45sBase . '/ping.php');
        @rmdir($r45sBase);
        assert_true($r45sStarted >= 3, "R45 servers ({$r45sLabel}): the probe server ran with its workers ({$r45sStarted} processes)");
        assert_equals([], $r45sLeft, "R45 servers ({$r45sLabel}): the usual proc_terminate() + proc_close() teardown ends the server and its workers");
    }
}
