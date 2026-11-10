<?php

// Review round 27 (2026-11-10, "kolo 11"), goal #394: root through the maintenance marker
// - finding 1 (high): DataOverrides::atomicWrite() chmodded its temporary file by name to the mode of the file it replaces,
//   in a directory the web user can write. The web user planted a 0666 .maintenance and swapped a symbolic link to
//   /etc/shadow in for .maintenance.*.tmp: the next step run as root made /etc/shadow 0666 (won in < 30 cycles). The same
//   helper ran as root in seedStores and UpdateKeyRotation, and a root step also ran the code of a src/ the web user had
//   replaced. Fixed for every step at once: cli/cli-only.php runs a step started as root as the app root's owner, before
//   any code of src/ is loaded (root stays only in a root-only tree; otherwise the step is refused).
// - MaintenanceMode::restore() dated the marker back with touch(), which follows a link and creates its target.
// - lows of the step lock: a file of the app root's owner renamed to .maintenance.lock (.env, 0600) was emptied; root
//   opened the lock through a link the web user swapped in; the claim file without flock was root:root.
echo "\n--- R27: no root through the maintenance marker — CLI steps leave root, nothing chmodded or touched by name ---\n";
$r27Dir = test_fixture_path('r27-root');
@mkdir($r27Dir, 0700, true);
$r27AsRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
if (getenv('BAREBONES_REQUIRE_ROOT_TEST') === '1') {
    assert_true($r27AsRoot, 'R27 root: BAREBONES_REQUIRE_ROOT_TEST=1 runs as root');
}
// The web user's race: two processes that swap a symbolic link to $target in for every temporary file of the marker
// (.maintenance.*.tmp) as fast as they can. Returns the function that stops them.
$r27Racer = static function (string $dir, string $target): callable {
    file_put_contents($dir . '/racer.php', '<?php [, $dir, $target, $n] = $argv; $end = microtime(true) + 60;
while (microtime(true) < $end && !is_file($dir . "/stop")) {
    foreach (scandir($dir) ?: [] as $f) if (str_starts_with($f, ".maintenance.") && str_ends_with($f, ".tmp")) {
        @symlink($target, $dir . "/l" . $n); @rename($dir . "/l" . $n, $dir . "/" . $f); }
}');
    @unlink($dir . '/stop');
    $processes = [];
    foreach ([1, 2] as $n) {
        $processes[] = proc_open([PHP_BINARY, $dir . '/racer.php', $dir, $target, (string)$n], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    }
    usleep(300000);
    return static function () use ($dir, $processes): void {
        touch($dir . '/stop');
        foreach ($processes as $process) proc_close($process);
    };
};
try {
    if (PHP_OS_FAMILY !== 'Windows') {
        // 1. atomicWrite: a link to the victim swapped in for each temporary file leaves the victim's mode alone (R26: 0666
        //    in the first cycle).
        $r27Victim = $r27Dir . '/victim';
        file_put_contents($r27Victim, "root:\$6\$secret:19000:0:99999:7:::\n");
        chmod($r27Victim, 0600);
        $r27Marker = $r27Dir . '/.maintenance';
        $r27Stop = $r27Racer($r27Dir, $r27Victim);
        $r27Changed = 0;
        for ($i = 0; $i < 3000 && $r27Changed === 0; $i++) {
            @unlink($r27Marker);
            file_put_contents($r27Marker, '{}');
            chmod($r27Marker, 0666);
            DataOverrides::atomicWrite($r27Marker, "{\"reason\":\"update\"}\n");
            clearstatcache();
            if ((fileperms($r27Victim) & 0777) !== 0600) $r27Changed = $i + 1;
        }
        $r27Stop();
        assert_equals([0, 0600], [$r27Changed, fileperms($r27Victim) & 0777],
            'R27 atomicWrite: a link swapped in for the temporary file never gets the replaced file\'s mode (cycle that changed the victim; its mode)');

        // 1b. Without a race: the new file takes the old one's mode, or the one asked for (seedStores: 0640, no chmod after).
        file_put_contents($r27Dir . '/plain.json', '{}');
        chmod($r27Dir . '/plain.json', 0604);
        DataOverrides::atomicWrite($r27Dir . '/plain.json', '{"a":1}');
        DataOverrides::atomicWrite($r27Dir . '/new.json', '{"b":1}', 0640);
        clearstatcache();
        assert_equals([0604, '{"a":1}', 0640, '{"b":1}', []], [fileperms($r27Dir . '/plain.json') & 0777, file_get_contents($r27Dir . '/plain.json'),
            fileperms($r27Dir . '/new.json') & 0777, file_get_contents($r27Dir . '/new.json'), glob($r27Dir . '/*.tmp') ?: []],
            'R27 atomicWrite: keeps the replaced file\'s mode, takes an asked mode, leaves no temporary file');
        $r27Source = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/DataOverrides.php');
        assert_true(!str_contains($r27Source, '@chmod($target, 0640)') && !str_contains($r27Source, '@chmod($tmp'),
            'R27 seedStores/atomicWrite: no chmod by name after the write');

        // 2. restore() puts a marker back without touch(): a dangling link swapped in for the temporary file (renamed onto
        //    .maintenance by the write) creates nothing.
        $r27Shop = $r27Dir . '/shop';
        mkdir($r27Shop, 0700);
        $r27Stop = $r27Racer($r27Shop, $r27Shop . '/created');
        // (R26's restore() also took the marker's old mtime: passed when it is there, so this file proves the R26 behaviour.)
        $r27Restore = new ReflectionMethod(MaintenanceMode::class, 'restore');
        $r27Put = static fn(array $marker) => $r27Restore->invokeArgs(null, $r27Restore->getNumberOfParameters() === 3
            ? [$r27Shop, $marker, strtotime($marker['since'])] : [$r27Shop, $marker]);
        $r27Old = ['since' => gmdate('c', time() - 600), 'reason' => 'update 27.0.1', 'pid' => 999999, 'host' => (string)gethostname()];
        for ($i = 0; $i < 3000 && !file_exists($r27Shop . '/created'); $i++) $r27Put($r27Old);
        $r27Stop();
        assert_true(!file_exists($r27Shop . '/created') && !is_link($r27Shop . '/created'),
            'R27 restore: a dangling link swapped in for the marker never makes the step create its target');

        // 2b. The window keeps its age through its 'since': fresh (10 min) still blocks, one older than STALE_AFTER does not.
        @unlink($r27Shop . '/.maintenance');
        $r27Put($r27Old);
        $r27Fresh = MaintenanceMode::active($r27Shop);
        $r27Put(['since' => gmdate('c', time() - MaintenanceMode::STALE_AFTER - 60)] + $r27Old);
        assert_equals([true, false, $r27Old['reason']], [$r27Fresh, MaintenanceMode::active($r27Shop), MaintenanceMode::marker($r27Shop)['reason'] ?? null],
            'R27 restore: a window put back keeps its age (a killed updater\'s 10-minute marker blocks, a stale one does not)');
        @unlink($r27Shop . '/.maintenance');

        // 3. A file of the app root's owner renamed to the lock's name (.env, 0600) is refused, not emptied.
        $r27Lock = $r27Shop . '/' . MaintenanceMode::LOCK_FILE;
        file_put_contents($r27Lock, "STRIPE_SECRET=sk_live_x\nDB_PASSWORD=y\n");
        chmod($r27Lock, 0600);
        try {
            $r27Answer = MaintenanceMode::claim($r27Shop) ? 'claimed' : 'busy';
            MaintenanceMode::unclaim($r27Shop);
        } catch (RuntimeException $e) {
            $r27Answer = $e->getMessage();
        }
        clearstatcache();
        assert_equals([true, "STRIPE_SECRET=sk_live_x\nDB_PASSWORD=y\n", 0600], [str_contains($r27Answer, 'holds something else'),
            file_get_contents($r27Lock), fileperms($r27Lock) & 0777], 'R27 step lock: a renamed .env at the lock\'s name is refused and kept — ' . $r27Answer);
        unlink($r27Lock);
    }

    if ($r27AsRoot && PHP_OS_FAMILY !== 'Windows') {
        // A copy of a step (cli/update.php with cli/cli-only.php) whose src/ the web user replaced: the planted autoload
        // reports who runs it.
        $r27App = static function (string $name, int $uid, int $gid, int $mode): string {
            $app = sys_get_temp_dir() . '/r27-app-' . $name . '-' . getmypid();
            foreach (['', '/cli', '/src'] as $dir) {
                @mkdir($app . $dir, 0755, true);
                chown($app . $dir, $uid);
                chgrp($app . $dir, $gid);
                chmod($app . $dir, $dir === '' ? $mode : 0755);
            }
            copy(TEST_CLI_DIR . '/cli-only.php', $app . '/cli/cli-only.php');
            copy(TEST_CLI_DIR . '/update.php', $app . '/cli/update.php');
            file_put_contents($app . '/src/autoload.php', '<?php
if (is_file(dirname(__DIR__) . "/config.php")) require dirname(__DIR__) . "/config.php"; // as src/Db.php does
file_put_contents(sys_get_temp_dir() . "/' . basename($app) . '.json", json_encode(["euid" => posix_geteuid(), "uid" => posix_getuid(),
    "egid" => posix_getegid(), "groups" => posix_getgroups(), "regained" => @posix_seteuid(0)]));
exit(0);');
            return $app;
        };
        $r27Run = static function (string $app): array {
            $report = sys_get_temp_dir() . '/' . basename($app) . '.json';
            @unlink($report);
            $process = proc_open([PHP_BINARY, $app . '/cli/update.php', 'status'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $err = stream_get_contents($pipes[2]);
            stream_get_contents($pipes[1]);
            $code = proc_close($process);
            $ran = json_decode((string)@file_get_contents($report), true);
            @unlink($report);
            return [$code, is_array($ran) ? $ran : null, (string)$err];
        };

        // 4. App root of another owner (65534, the web user's tree): the planted code runs as that owner, for good.
        $r27Foreign = $r27App('foreign', 65534, 65534, 0755);
        [$r27Code, $r27Ran, $r27Err] = $r27Run($r27Foreign);
        assert_equals([65534, 65534, 65534, false, false], [$r27Ran['euid'] ?? null, $r27Ran['uid'] ?? null, $r27Ran['egid'] ?? null,
            in_array(0, $r27Ran['groups'] ?? [0], true), $r27Ran['regained'] ?? null],
            'R27 root: a step started as root runs as the app root\'s owner before src/ is loaded, without root\'s groups and with no way back (R26: euid 0) — '
            . json_encode([$r27Code, $r27Ran, $r27Err]));

        // 5. A root-owned tree another account can write (group 65534, 0775) is refused before its code runs; a root-only
        //    tree keeps root.
        $r27Shared = $r27App('shared', 0, 65534, 0775);
        [$r27Code, $r27Ran, $r27Err] = $r27Run($r27Shared);
        assert_equals([1, null, true], [$r27Code, $r27Ran, str_contains($r27Err, 'REFUSED') && str_contains($r27Err, 'writable by another account')],
            'R27 root: a root-owned tree the group 65534 can write is refused, its code never runs — ' . $r27Err);
        $r27Own = $r27App('own', 0, 0, 0755);
        [$r27Code, $r27Ran] = $r27Run($r27Own);
        assert_equals(0, $r27Ran['euid'] ?? null, 'R27 root: a root-only tree runs as root');

        // 5b. Capability review (run 390): in a root-owned 0755 tree, code another account owns — config.php (the web
        //     installer writes it), a file of adapters/, a symbolic link out of the tree — is refused before root
        //     loads it (was: only the app root, src/ and cli/ were checked, and root ran the web user's config.php).
        $r27CfgReport = sys_get_temp_dir() . '/r27cfg-' . getmypid() . '.json';
        $r27Planted = [];
        foreach (['config', 'adapter', 'link'] as $r27Kind) {
            $r27Tree = $r27App('planted-' . $r27Kind, 0, 0, 0755);
            $r27Planted[] = $r27Tree;
            $r27Php = '<?php file_put_contents(' . var_export($r27CfgReport, true) . ', json_encode(["euid" => posix_geteuid()])); return [];';
            if ($r27Kind === 'config') {
                file_put_contents($r27Tree . '/config.php', $r27Php);
                chown($r27Tree . '/config.php', 65534);
                chmod($r27Tree . '/config.php', 0644);
            } elseif ($r27Kind === 'adapter') {
                mkdir($r27Tree . '/adapters', 0755);
                file_put_contents($r27Tree . '/adapters/PaymentAdapter.php', $r27Php);
                chown($r27Tree . '/adapters/PaymentAdapter.php', 65534);
            } else {
                $r27Outside = $r27Tree . '-outside';
                $r27Planted[] = $r27Outside;
                mkdir($r27Outside, 0755);
                chown($r27Outside, 65534);
                symlink($r27Outside, $r27Tree . '/vendor');
            }
            @unlink($r27CfgReport);
            [$r27Code, $r27Ran, $r27Err] = $r27Run($r27Tree);
            assert_equals([1, null, false, true], [$r27Code, $r27Ran, is_file($r27CfgReport), str_contains($r27Err, 'REFUSED')],
                "R27 root ({$r27Kind}): code of another account in a root-owned tree is refused before root runs it — " . $r27Err);
            @unlink($r27CfgReport);
        }

        // 6. MaintenanceMode as root outside a step's CLI: the lock of an app root another account can write is refused.
        $r27ForeignLock = $r27Foreign . '/' . MaintenanceMode::LOCK_FILE;
        try {
            $r27Answer = MaintenanceMode::claim($r27Foreign) ? 'claimed' : 'busy';
            MaintenanceMode::unclaim($r27Foreign);
        } catch (RuntimeException $e) {
            $r27Answer = $e->getMessage();
        }
        assert_equals([true, false], [str_contains($r27Answer, 'REFUSED: root does not open'), file_exists($r27ForeignLock) || is_link($r27ForeignLock)],
            'R27 step lock (root): refused in an app root owned by another account, nothing created — ' . $r27Answer);

        // 7. The claim file without flock is made in the app root's group (R26: root:root).
        $r27Grouped = $r27App('grouped', 0, 65534, 0755);
        $r27NoFlock = new ReflectionMethod(MaintenanceMode::class, 'claimWithoutFlock');
        $r27NoFlock->setAccessible(true);
        $r27GroupedLock = $r27Grouped . '/' . MaintenanceMode::LOCK_FILE;
        $r27Claimed = $r27NoFlock->invoke(null, $r27GroupedLock);
        clearstatcache();
        assert_equals([true, 0, 65534, 0660], [$r27Claimed, fileowner($r27GroupedLock . MaintenanceMode::HELD_SUFFIX),
            filegroup($r27GroupedLock . MaintenanceMode::HELD_SUFFIX), fileperms($r27GroupedLock . MaintenanceMode::HELD_SUFFIX) & 0777],
            'R27 step lock (root): the claim file without flock belongs to the app root\'s group, 0660');
        MaintenanceMode::unclaim($r27Grouped);
        foreach ([$r27Foreign, $r27Shared, $r27Own, $r27Grouped, ...$r27Planted] as $r27Tree) {
            if (function_exists('updateTestRemoveTree')) updateTestRemoveTree($r27Tree);
        }
    }
} catch (Throwable $e) {
    assert_true(false, 'R27 root (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    MaintenanceMode::unclaim($r27Dir . '/shop');
    if (function_exists('updateTestRemoveTree')) updateTestRemoveTree($r27Dir);
}
