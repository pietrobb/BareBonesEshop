<?php

// Review round 26 (2026-11-09, "kolo 10"), goal #389: the R25 step lock <app root>/.maintenance.lock
// - finding 1 (high): opened with fopen('c'), which follows a symbolic link; run as root it then handed the file to the app
//   root's owner (0660) and emptied it. The web user can write the app root: `ln -s /etc/passwd .maintenance.lock` and
//   the next restore, update or migration as root gave it /etc/passwd (reproduced: root 0600 file → nobody 0660, 0 bytes);
// - finding 3: without /proc and with protected_hardlinks=0 that hand-over threw outside any try: every root step died
//   (exit 255);
// - low: only root fixed a lock's mode (0644 from before R25 stayed, any account could lock it), a deploy account's lock
//   shut the web user out, on a file system without file locks steps did not exclude each other at all, and the holder
//   record was printed unescaped.
echo "\n--- R26: the maintenance step lock — no links followed, no foreign file changed, no /proc needed ---\n";
$r26lRoot = test_fixture_path('r26-steplock');
@mkdir($r26lRoot, 0700, true);
$r26lLock = $r26lRoot . '/' . MaintenanceMode::LOCK_FILE;
$r26lRefused = static function (string $root): string {
    try {
        $claimed = MaintenanceMode::claim($root);
        MaintenanceMode::unclaim($root);
        return $claimed ? 'claimed' : 'busy';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
};
$r26lState = static function (string $file): array {
    clearstatcache();
    return [(string)@file_get_contents($file), @fileperms($file) & 0777, @fileowner($file)];
};
$r26lAsRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
// The suite runs as a normal account, where 7 and 8 below are skipped. CI runs this file once more as root with /proc
// hidden (ci.yml, "Step lock as root without /proc"); there the flag makes a skip a failure.
if (getenv('BAREBONES_REQUIRE_ROOT_TEST') === '1') {
    assert_equals([true, false], [$r26lAsRoot, is_dir('/proc/self')],
        'R26 step lock: BAREBONES_REQUIRE_ROOT_TEST=1 runs as root with /proc hidden');
}
try {
    if (PHP_OS_FAMILY !== 'Windows') {
        // 1. A symbolic link at the lock's name is refused; the file it points at keeps its content, mode and owner.
        $r26lVictim = test_fixture_path('r26-steplock-victim');
        file_put_contents($r26lVictim, "root:x:0:0:root:/root:/bin/bash\n");
        chmod($r26lVictim, 0600);
        $r26lBefore = $r26lState($r26lVictim);
        @unlink($r26lLock);
        symlink($r26lVictim, $r26lLock);
        $r26lAnswer = $r26lRefused($r26lRoot);
        assert_true(str_contains($r26lAnswer, 'is a symbolic link'), 'R26 step lock: a symbolic link at .maintenance.lock is refused — ' . $r26lAnswer);
        assert_equals($r26lBefore, $r26lState($r26lVictim), 'R26 step lock: the file the link points at is not emptied, chmodded or chowned');
        assert_true(is_link($r26lLock), 'R26 step lock: the link is left for the operator to inspect');
        unlink($r26lLock);

        // 2. A hard link to another file (protected_hardlinks=0) is refused too.
        link($r26lVictim, $r26lLock);
        $r26lAnswer = $r26lRefused($r26lRoot);
        assert_true(str_contains($r26lAnswer, 'is a hard link'), 'R26 step lock: a hard link at .maintenance.lock is refused — ' . $r26lAnswer);
        assert_equals($r26lBefore, $r26lState($r26lVictim), 'R26 step lock: the hard-linked file keeps its content and mode');
        unlink($r26lLock);
        unlink($r26lVictim);

        // 3. A lock from before R25 (0644: any account could lock it) is not chmodded but replaced by a new 0660 one.
        file_put_contents($r26lLock, '');
        chmod($r26lLock, 0644);
        clearstatcache();
        $r26lOldIno = fileinode($r26lLock);
        assert_equals('claimed', $r26lRefused($r26lRoot), 'R26 step lock: a 0644 lock is still usable');
        clearstatcache();
        assert_equals([0660, true], [fileperms($r26lLock) & 0777, fileinode($r26lLock) !== $r26lOldIno],
            'R26 step lock: a 0644 lock is replaced by a new file with 0660 (its mode is not changed in place)');
        assert_equals(0, filesize($r26lLock), 'R26 step lock: the new lock is emptied when the step lets go');

        // 4. The holder record is shown as printable text (any account of the group can write it).
        file_put_contents($r26lLock, json_encode(['pid' => 4242, 'host' => "h\e[2J", 'since' => 'now', 'command' => "cli/update.php\n\e[31mFAKE OK\x07"]));
        $r26lHolder = MaintenanceMode::holder($r26lRoot);
        assert_equals([0, true], [preg_match('/[\x00-\x1f\x7f]/', $r26lHolder), str_starts_with($r26lHolder, 'pid 4242 on h?')],
            'R26 step lock: control characters in the holder record are not printed — ' . json_encode($r26lHolder));
        file_put_contents($r26lLock, '');

        // 5. A file system without file locks: steps exclude each other by an exclusive claim file (R25 let all run).
        $r26lNoFlock = new ReflectionMethod(MaintenanceMode::class, 'claimWithoutFlock');
        $r26lNoFlock->setAccessible(true);
        $r26lHeld = $r26lLock . MaintenanceMode::HELD_SUFFIX;
        @unlink($r26lHeld);
        file_put_contents($r26lRoot . '/noflock.php', '<?php
require ' . var_export(dirname(TEST_CLI_DIR) . '/src/autoload.php', true) . ';
$m = new ReflectionMethod(MaintenanceMode::class, "claimWithoutFlock");
$m->setAccessible(true);
echo $m->invoke(null, $argv[1]) ? "claimed" : "refused";
');
        $r26lChild = static fn(): string => trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($r26lRoot . '/noflock.php') . ' ' . escapeshellarg($r26lLock) . ' 2>/dev/null'));
        $r26lFirst = $r26lNoFlock->invoke(null, $r26lLock);
        $r26lWhileHeld = $r26lChild();
        MaintenanceMode::unclaim($r26lRoot);
        $r26lAfter = $r26lChild(); // the child ended: its claim names a process that no longer runs
        $r26lStale = $r26lChild();
        assert_equals([true, 'refused', 'claimed', 'claimed'], [$r26lFirst, $r26lWhileHeld, $r26lAfter, $r26lStale],
            'R26 step lock: without file locks a second step is refused while the first holds the claim file, and a dead step\'s claim is taken over');
        @unlink($r26lHeld);

        // 6. MaintenanceMode no longer hands a file over (by name or /proc): nothing to fail without /proc. This source check
        //    only guards against the hand-over coming back; 7 and 8 run the root path, in CI without /proc.
        $r26lSource = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/MaintenanceMode.php');
        assert_true(!str_contains($r26lSource, 'FileOwnership::handOver') && str_contains($r26lSource, "@fopen(\$path, 'x+')"),
            'R26 step lock: no hand-over and no link-following open of the lock');
        $r26lRestore = (string)file_get_contents(TEST_CLI_DIR . '/restore.php');
        assert_true(preg_match('/try \{\s*\$claimed = MaintenanceMode::claim\(\$root\);/', $r26lRestore) === 1,
            'R26 step lock: cli/restore.php refuses with the lock\'s reason instead of dying on an uncaught exception');
    }

    if ($r26lAsRoot) {
        // 7. As root: the lock is created as the app root's owner and group (seteuid: no chown, no /proc), a symbolic link
        //    to a root-only file is refused and that file stays root's, 0600, with its content.
        $r26lApp = sys_get_temp_dir() . '/r26-steplock-app-' . getmypid(); // a parent the app root's owner can enter
        @mkdir($r26lApp, 0775, true);
        chown($r26lApp, 65534);
        chgrp($r26lApp, 65534);
        $r26lAppLock = $r26lApp . '/' . MaintenanceMode::LOCK_FILE;
        @unlink($r26lAppLock);
        assert_equals('claimed', $r26lRefused($r26lApp), 'R26 step lock (root): claimed in an app root owned by another account');
        clearstatcache();
        assert_equals([65534, 65534, 0660, 0, 0], [fileowner($r26lAppLock), filegroup($r26lAppLock), fileperms($r26lAppLock) & 0777, posix_geteuid(), posix_getegid()],
            'R26 step lock (root): created as the app root\'s owner and group, 0660, and root again afterwards');
        unlink($r26lAppLock);

        $r26lShadow = test_fixture_path('r26-steplock-shadow');
        file_put_contents($r26lShadow, "root:\$6\$secret:19000:0:99999:7:::\n");
        chown($r26lShadow, 0);
        chmod($r26lShadow, 0600);
        $r26lBefore = $r26lState($r26lShadow);
        symlink($r26lShadow, $r26lAppLock);
        $r26lAnswer = $r26lRefused($r26lApp);
        assert_true(str_contains($r26lAnswer, 'is a symbolic link'), 'R26 step lock (root): a symbolic link to a root file is refused — ' . $r26lAnswer);
        assert_equals([[(string)file_get_contents($r26lShadow), 0600, 0], [(string)file_get_contents($r26lShadow), 0600, 0]], [$r26lBefore, $r26lState($r26lShadow)],
            'R26 step lock (root): the root-only file keeps owner root, 0600 and its content (R25: nobody:nogroup 0660, 0 bytes)');
        unlink($r26lAppLock);
        link($r26lShadow, $r26lAppLock); // root may hard-link any file, whatever fs.protected_hardlinks says
        $r26lAnswer = $r26lRefused($r26lApp);
        assert_true(str_contains($r26lAnswer, 'is a hard link'), 'R26 step lock (root): a hard link to a root file is refused — ' . $r26lAnswer);
        assert_equals($r26lBefore, $r26lState($r26lShadow), 'R26 step lock (root): the hard-linked root file keeps owner root, 0600 and its content');
        unlink($r26lAppLock);
        unlink($r26lShadow);

        // 8. As root: a lock root made 0644 before R25 or a deploy account's 1234:1234 0660 one is replaced, not changed.
        foreach ([[0, 0, 0644], [1234, 1234, 0660]] as [$r26lUid, $r26lGid, $r26lMode]) {
            file_put_contents($r26lAppLock, '');
            chown($r26lAppLock, $r26lUid);
            chgrp($r26lAppLock, $r26lGid);
            chmod($r26lAppLock, $r26lMode);
            clearstatcache();
            $r26lOldIno = fileinode($r26lAppLock);
            assert_equals('claimed', $r26lRefused($r26lApp), "R26 step lock (root): a {$r26lUid}:{$r26lGid} lock is usable");
            clearstatcache();
            assert_equals([65534, 0660, true], [fileowner($r26lAppLock), fileperms($r26lAppLock) & 0777, fileinode($r26lAppLock) !== $r26lOldIno],
                "R26 step lock (root): a {$r26lUid}:{$r26lGid} " . sprintf('%04o', $r26lMode) . ' lock is replaced by one the web user can use');
            unlink($r26lAppLock);
        }
        @rmdir($r26lApp);
    }
} catch (Throwable $e) {
    assert_true(false, 'R26 step lock (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    MaintenanceMode::unclaim($r26lRoot);
    if (function_exists('updateTestRemoveTree')) updateTestRemoveTree($r26lRoot);
}
