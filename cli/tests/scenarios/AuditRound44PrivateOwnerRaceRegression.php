<?php

// Review round 16 (R44, finding 1): PrivateOwner::find() took the owner from realpath()/stat() (links followed) and checked
// the links on the way in a later, separate lstat() walk. Where the web user may change a directory on the way to the
// private directory (a parent it owns, or a sticky /tmp), it swapped the private directory for a link to a directory only
// root may read between the two: find() said "root's alone" (~4% of calls) and migrate.php wrote schema_migrations into
// root's database as root; a link to another account's directory made root switch to that account. find() now walks the
// path the way openat() would (chdir into each directory, stat "." against lstat of the name) and decides owner and
// safety from the one inode it stands in; a link as the last component is refused.
echo "\n--- R44 root: PrivateOwner::find() owner and path from one walk (writable parent, sticky parent, swap race) ---\n";

$r44oRepo = dirname(TEST_CLI_DIR);
assert_true(str_contains((string)file_get_contents($r44oRepo . '/src/PrivateOwner.php'), "@stat('.')"),
    'R44 root: find() stats the directory it stands in, not a path it resolves again');

if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    echo "  [SKIP] R44 root private owner race: run as root on Linux (wsl -u root / CI root job)\n";
    return;
}

$r44oBase = sys_get_temp_dir() . '/bbe-r44-owner-' . bin2hex(random_bytes(4));
$r44oRun = static function (array $command): array {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
// The web user (65534) exchanges <dir>/<name> (its real directory) with its links <name>.l<i> to the given targets,
// atomically and as fast as it can (renameat2 RENAME_EXCHANGE, as the reviewer did); $r44oStop puts the directory back.
$r44oSwapper = static function (string $dir, string $name, array $targets) {
    $script = 'import ctypes, os, sys' . "\n"
        . 'libc = ctypes.CDLL(None, use_errno=True)' . "\n"
        . 'os.chdir(sys.argv[1]); n = sys.argv[2].encode(); links = []' . "\n"
        . 'for i, t in enumerate(sys.argv[3:]):' . "\n"
        . '    l = n + b".l%d" % i; os.symlink(t, l); links.append(l)' . "\n"
        . 'while True:' . "\n"
        . '    for l in links:' . "\n"
        . '        for _ in range(1000): libc.renameat2(-100, n, -100, l, 2)' . "\n";
    $process = proc_open(array_merge(['setpriv', '--reuid=65534', '--regid=65534', '--clear-groups', 'python3', '-c', $script, $dir, $name], $targets), [], $pipes);
    // The race is only proven when the swapper really runs: it must be alive with all its links in place, else the
    // test fails (a missing setpriv/python3 or a failing renameat2 must not pass as "0 leaks").
    $links = 0;
    for ($wait = 0; $wait < 40 && $links < count($targets); $wait++) {
        usleep(50000);
        clearstatcache();
        $links = count(glob($dir . '/' . $name . '.l*', GLOB_NOSORT) ?: []);
    }
    if (!is_resource($process) || !proc_get_status($process)['running'] || $links !== count($targets)) {
        throw new RuntimeException("R44 swapper did not start in {$dir} ({$links} of " . count($targets) . ' links)');
    }
    return $process;
};
$r44oAlive = static fn($swapper): bool => is_resource($swapper) && proc_get_status($swapper)['running'];
$r44oStop = static function ($swapper, string $dir, string $name): void {
    proc_terminate($swapper, 9);
    proc_close($swapper);
    clearstatcache();
    foreach (glob($dir . '/' . $name . '.l*') ?: [] as $entry) {
        if (!is_link($entry) && is_dir($entry)) {
            if (is_link($dir . '/' . $name)) unlink($dir . '/' . $name);
            rename($entry, $dir . '/' . $name);
        } else {
            unlink($entry);
        }
    }
    clearstatcache();
};
// find() on $dir for $seconds while the swapper runs: how often it answers root's alone, another account, the owner, refused.
$r44oRace = static function (string $dir, float $seconds): array {
    $count = ['root' => 0, 'other' => 0, 'owner' => 0, 'refused' => 0, 'calls' => 0];
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        clearstatcache(true); // as in a new root step: no realpath() cached from the call before
        $r = PrivateOwner::find([$dir]);
        $count['calls']++;
        if ($r === null) $count['root']++;
        elseif (is_string($r)) $count['refused']++;
        elseif ($r['uid'] === 65534) $count['owner']++;
        else $count['other']++;
    }
    return $count;
};
$r44oMkdir = static function (string $dir, int $mode, int $uid): void {
    mkdir($dir, $mode);
    chmod($dir, $mode);
    chown($dir, $uid);
    chgrp($dir, $uid);
};
$r44oCwd = getcwd();
try {
    $r44oMkdir($r44oBase, 0755, 0);
    $r44oMkdir($r44oBase . '/secret', 0700, 0);
    $r44oMkdir($r44oBase . '/other', 0755, 1);
    $r44oSecretDb = $r44oBase . '/secret/shop.sqlite';
    (new PDO('sqlite:' . $r44oSecretDb))->exec("PRAGMA journal_mode=DELETE; CREATE TABLE t (v TEXT); INSERT INTO t VALUES ('R44-ROOT-ONLY')");
    // Layout A: the parent is the web user's. Layout B: a sticky /tmp-like parent (root, 1777).
    $r44oMkdir($r44oBase . '/web', 0755, 65534);
    $r44oMkdir($r44oBase . '/web/private', 0755, 65534);
    $r44oMkdir($r44oBase . '/tmp', 01777, 0);
    $r44oMkdir($r44oBase . '/tmp/private', 0755, 65534);

    // Without a race: both layouts keep their owner, and find() leaves the working directory where it was.
    $r44oA = PrivateOwner::find([$r44oBase . '/web/private']);
    $r44oB = PrivateOwner::find([$r44oBase . '/tmp/private', $r44oBase . '/tmp/private/db/absent']);
    assert_true(is_array($r44oA) && $r44oA['uid'] === 65534 && $r44oA['dir'] === $r44oBase . '/web/private'
        && is_array($r44oB) && $r44oB['uid'] === 65534 && getcwd() === $r44oCwd,
        'R44 root: a parent of the web user\'s and a sticky parent keep the private directory\'s owner; the working directory is restored');

    // Refused outright: the private directory as a link (even root's), a parent anyone can write without the sticky bit
    // (was: accepted for the web user's directory), a link of another account on the way. A root-owned link in a
    // root-only directory on the way is followed.
    symlink($r44oBase . '/web/private', $r44oBase . '/link-private');
    $r44oMkdir($r44oBase . '/open', 0777, 0);
    $r44oMkdir($r44oBase . '/open/private', 0755, 65534);
    symlink($r44oBase . '/web', $r44oBase . '/web-link');
    symlink($r44oBase . '/tmp', $r44oBase . '/tmp/up');
    lchown($r44oBase . '/tmp/up', 65533);
    $r44oViaLink = PrivateOwner::find([$r44oBase . '/web-link/private']);
    $r44oRefused = [PrivateOwner::find([$r44oBase . '/link-private']), PrivateOwner::find([$r44oBase . '/open/private']),
        PrivateOwner::find([$r44oBase . '/tmp/up/private'])];
    assert_true(count(array_filter($r44oRefused, 'is_string')) === 3 && str_contains((string)$r44oRefused[0], 'is a link')
        && str_contains((string)$r44oRefused[1], 'any account') && str_contains((string)$r44oRefused[2], 'link of another account'),
        'R44 root: the private directory as a link, a parent anyone can write, another account\'s link on the way are refused (' . json_encode($r44oRefused, JSON_UNESCAPED_SLASHES) . ')');
    assert_true(is_array($r44oViaLink) && $r44oViaLink['uid'] === 65534 && $r44oViaLink['dir'] === $r44oBase . '/web/private',
        'R44 root: root\'s link in a root-only directory on the way is followed to the directory it points to');

    // The race: the web user swaps its private directory with links to root's directory and another account's.
    $r44oResults = [];
    foreach (['web', 'tmp'] as $r44oLayout) {
        $r44oParent = $r44oBase . '/' . $r44oLayout;
        $r44oSwap = $r44oSwapper($r44oParent, 'private', [$r44oBase . '/secret', $r44oBase . '/other']);
        try {
            $r44oResults[$r44oLayout] = $r44oRace($r44oParent . '/private', 4.0);
            $r44oResults[$r44oLayout]['alive'] = $r44oAlive($r44oSwap);
        } finally {
            $r44oStop($r44oSwap, $r44oParent, 'private');
        }
    }
    foreach ($r44oResults as $r44oLayout => $r44oCount) {
        assert_true($r44oCount['root'] === 0 && $r44oCount['other'] === 0 && $r44oCount['owner'] > 0,
            "R44 root: {$r44oCount['calls']} find() calls while the web user swaps its private directory ({$r44oLayout} parent): never root's alone,"
            . ' never another account, the owner otherwise (' . json_encode($r44oCount) . ')');
        // Proof the swap landed during find(): the swapper outlived the race and some calls caught the link and refused.
        assert_true($r44oCount['alive'] && $r44oCount['refused'] > 0,
            "R44 root: the swap really happened during the race ({$r44oLayout} parent): swapper alive, refused > 0 (" . json_encode($r44oCount) . ')');
    }

    // End to end: migrate.php started as root in a root-owned tree, its database in the swapped directory, never
    // writes into root's database.
    $r44oApp = $r44oBase . '/app';
    mkdir($r44oApp . '/cli', 0755, true);
    mkdir($r44oApp . '/migrations', 0755);
    shell_exec('cp -r ' . escapeshellarg($r44oRepo . '/src') . ' ' . escapeshellarg($r44oApp . '/src'));
    foreach (['cli-only.php', 'migrate.php'] as $r44oFile) copy($r44oRepo . '/cli/' . $r44oFile, $r44oApp . '/cli/' . $r44oFile);
    file_put_contents($r44oApp . '/migrations/20260101000000_r44_probe.php', "<?php\nreturn new class {\n    public function up(PDO \$db, bool \$isSqlite): void {\n"
        . "        \$db->exec('CREATE TABLE r44_probe (id INTEGER PRIMARY KEY)');\n    }\n};\n");
    file_put_contents($r44oApp . '/config.php', '<?php return ' . var_export(['db_driver' => 'sqlite', 'db_sqlite_path' => $r44oBase . '/web/private/shop.sqlite',
        'private_runtime_dir' => $r44oBase . '/web/private'], true) . ';');
    shell_exec('chown -R 0:0 ' . escapeshellarg($r44oApp) . ' && chmod -R go-w ' . escapeshellarg($r44oApp));
    // Without the swap the same migrate.php works (as the private directory's owner): the harness itself is sound.
    [$r44oX, $r44oO] = $r44oRun([PHP_BINARY, $r44oApp . '/cli/migrate.php', '--skip-backup']);
    assert_true($r44oX === 0 && is_file($r44oBase . '/web/private/shop.sqlite'),
        'R44 root: without the swap migrate.php as root runs as the owner and creates its database (exit ' . $r44oX . ': ' . substr(trim($r44oO), -300) . ')');
    $r44oSwap = $r44oSwapper($r44oBase . '/web', 'private', [$r44oBase . '/secret']);
    try {
        $r44oExits = [];
        for ($r44oI = 0; $r44oI < 40; $r44oI++) $r44oExits[] = $r44oRun([PHP_BINARY, $r44oApp . '/cli/migrate.php', '--skip-backup'])[0];
        $r44oE2eAlive = $r44oAlive($r44oSwap);
    } finally {
        $r44oStop($r44oSwap, $r44oBase . '/web', 'private');
    }
    // Proof the swap reached migrate.php: the swapper outlived the 40 runs and some were refused by find() (exit 1); the
    // rest switched to the owner first, so a swap after the check only fails to open the database (no root write).
    assert_true($r44oE2eAlive && in_array(1, $r44oExits, true),
        'R44 root: the swapper ran through the 40 migrations and some were refused by the owner check (exits ' . json_encode(array_count_values($r44oExits)) . ')');
    $r44oSecret = new PDO('sqlite:' . $r44oSecretDb);
    assert_equals([['t'], ['.', '..', 'shop.sqlite']],
        [$r44oSecret->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN), scandir($r44oBase . '/secret')],
        'R44 root: 40 root migrations while the web user swaps its private directory (its parent is the web user\'s): root\'s database gets no schema_migrations, nothing appears next to it');
    $r44oSecret = null;
} catch (Throwable $r44oE) {
    assert_true(false, 'R44 root private owner race (' . get_class($r44oE) . ': ' . $r44oE->getMessage() . ' @ ' . basename($r44oE->getFile()) . ':' . $r44oE->getLine() . ')');
} finally {
    if (is_string($r44oCwd)) chdir($r44oCwd);
    shell_exec('rm -rf ' . escapeshellarg($r44oBase));
}
