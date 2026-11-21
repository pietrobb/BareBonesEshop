<?php

// Review round 18 (R48, finding 1): R47 made the doctor read private_runtime_dir/stores.json as its owner, but update
// check/apply still copied it into the theme candidate as root (ThemeUpgradeGate). The owner could point it at a file
// only root may read (copied into the candidate; the probe's error then quoted it) or swap a FIFO in after the gate's
// is_file() (check, and so apply, hung for good). The gate now reads it as the owner, non-blocking, as a regular file.
echo "\n--- R48: update check/apply copy the owner's stores.json as the owner, never through a FIFO ---\n";

if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid') || posix_geteuid() !== 0 || !function_exists('posix_mkfifo')) {
    echo "  [SKIP] R48 update stores.json: run as root on Linux (wsl -u root / CI root job)\n";
    return;
}

$r48sBase = sys_get_temp_dir() . '/bbe-r48-stores-' . bin2hex(random_bytes(4));
$r48sApp = (string)realpath(dirname(TEST_CLI_DIR));
$r48sOwnJson = '{"stores":{"r48-own":{"theme":"default"}}}';
try {
    mkdir($r48sBase, 0755);
    chmod($r48sBase, 0755);
    // A file only root may read; a store name in it would reach the probe's failure ("<theme> / store <id>: …").
    $r48sSecret = $r48sBase . '/root-only.json';
    file_put_contents($r48sSecret, '{"stores":{"r48-root-secret":{"theme":"r48-root-secret"}}}');
    chmod($r48sSecret, 0600);
    $r48sPrivate = $r48sBase . '/private';
    mkdir($r48sPrivate, 0755);
    symlink($r48sSecret, $r48sPrivate . '/stores.json');
    shell_exec('chown -hR 65534:65534 ' . escapeshellarg($r48sPrivate));
    $r48sOwner = PrivateOwner::find([$r48sPrivate]);
    assert_true(is_array($r48sOwner) && $r48sOwner['uid'] === 65534, 'R48 update stores: the private directory is the web user\'s');

    $r48sGate = new ThemeUpgradeGate($r48sBase . '/updates');
    $r48sCopy = new ReflectionMethod(ThemeUpgradeGate::class, 'copyStores');
    $r48sCopyInto = static function (string $candidate) use ($r48sGate, $r48sCopy, $r48sApp): ?string {
        mkdir($candidate, 0700, true);
        try {
            $r48sCopy->invoke($r48sGate, $r48sApp, $candidate);
            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    };
    DataOverrides::setDirForTesting($r48sPrivate);
    PrivateOwner::record($r48sOwner);
    try {
        $r48sLinkError = $r48sCopyInto($r48sBase . '/candidate-link');
        unlink($r48sPrivate . '/stores.json');
        file_put_contents($r48sPrivate . '/stores.json', $r48sOwnJson);
        chown($r48sPrivate . '/stores.json', 65534);
        chmod($r48sPrivate . '/stores.json', 0600);
        $r48sOwnError = $r48sCopyInto($r48sBase . '/candidate-own');
    } finally {
        PrivateOwner::record(null);
        DataOverrides::setDirForTesting(null);
    }
    assert_true($r48sLinkError !== null && !str_contains($r48sLinkError, 'r48-root-secret') && !file_exists($r48sBase . '/candidate-link/stores.json'),
        'R48 update stores: a symlink to a root-only file is refused and nothing of it reaches the candidate (was: copied as root) — ' . json_encode($r48sLinkError));
    assert_equals([null, $r48sOwnJson], [$r48sOwnError, @file_get_contents($r48sBase . '/candidate-own/stores.json')],
        'R48 update stores: the owner\'s own stores.json is still copied into the candidate');

    // The FIFO: the owner swaps stores.json between its regular file (a hard link) and a new FIFO as fast as it can, while
    // the gate copies it 2000 times. Each copy must finish (the old copy() as root blocked in open() on the first FIFO it
    // met after is_file() had seen the regular file). Renamed files, not symlinks: PHP's realpath cache would pin a link.
    unlink($r48sPrivate . '/stores.json');
    file_put_contents($r48sPrivate . '/plain.json', $r48sOwnJson);
    shell_exec('chown -hR 65534:65534 ' . escapeshellarg($r48sPrivate) . '; chmod 0600 ' . escapeshellarg($r48sPrivate . '/plain.json'));
    $r48sProbe = $r48sBase . '/race.php';
    file_put_contents($r48sProbe, '<?php require ' . var_export($r48sApp . '/src/autoload.php', true) . ';
[, $app, $private, $base, $expected] = $argv;
DataOverrides::setDirForTesting($private);
PrivateOwner::record(PrivateOwner::find([$private]));
$null = [0 => ["file", "/dev/null", "r"], 1 => ["pipe", "w"], 2 => ["file", "/dev/null", "w"]];
// R52 (idea 59): the racer says when its first regular file is in place, so no copy runs before the race has begun.
$racer = proc_open([PHP_BINARY, "-r", \'posix_setgid(65534); posix_setuid(65534); $d = $argv[1]; $end = microtime(true) + 60; for ($n = 0; microtime(true) < $end; $n++) { @link("$d/plain.json", "$d/a"); @rename("$d/a", "$d/stores.json"); if ($n === 0) { fwrite(STDOUT, "ready"); fflush(STDOUT); } usleep(20); @posix_mkfifo("$d/b", 0600); @rename("$d/b", "$d/stores.json"); }\', $private], $null, $pipes);
$ready = [$pipes[1]];
$none = null;
if (stream_select($ready, $none, $none, 20) !== 1 || fread($pipes[1], 5) !== "ready") {
    proc_terminate($racer, 9);
    echo json_encode(["racer did not start" => 1]);
    exit;
}
$gate = new ThemeUpgradeGate($base . "/updates");
$copy = new ReflectionMethod(ThemeUpgradeGate::class, "copyStores");
$seen = [];
try {
    for ($i = 0; $i < 2000; $i++) {
        $candidate = $base . "/race/" . $i;
        mkdir($candidate, 0700, true);
        // R52 (idea 59): each copy stands for one update run, a process of its own. Was: one probe process, and PHP\'s
        // stat cache kept storesPath()\'s FIFO verdict for stores.json, so after the first FIFO every copy saw no regular
        // file ({"example":2000}, about 1 run in 5 under load).
        clearstatcache();
        try {
            $copy->invoke($gate, $app, $candidate);
            $kind = !is_file($candidate . "/stores.json") ? "example" : (file_get_contents($candidate . "/stores.json") === $expected ? "copied" : "wrong");
        } catch (RuntimeException $e) {
            $kind = "refused";
        }
        $seen[$kind] = ($seen[$kind] ?? 0) + 1;
    }
} finally {
    proc_terminate($racer, 9);
    proc_close($racer);
}
ksort($seen);
echo json_encode($seen);');
    $r48sStart = microtime(true);
    $r48sOut = (string)shell_exec('timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . implode(' ', array_map('escapeshellarg', [$r48sProbe, $r48sApp, $r48sPrivate, $r48sBase, $r48sOwnJson])) . ' 2>&1');
    $r48sSeen = json_decode(trim($r48sOut), true);
    $r48sKnown = is_array($r48sSeen) ? array_diff_key($r48sSeen, ['copied' => 0, 'refused' => 0, 'example' => 0]) : null;
    assert_true(is_array($r48sSeen) && $r48sKnown === [] && array_sum($r48sSeen) === 2000 && ($r48sSeen['copied'] ?? 0) > 0,
        'R48 update stores: 2000 copies against a FIFO swapped in finish, each copies the owner\'s file, refuses, or uses the example (was: hung) — '
        . trim($r48sOut) . ' in ' . round(microtime(true) - $r48sStart, 1) . ' s');
} catch (Throwable $r48sE) {
    assert_true(false, 'R48 update stores (' . get_class($r48sE) . ': ' . $r48sE->getMessage() . ' @ ' . basename($r48sE->getFile()) . ':' . $r48sE->getLine() . ')');
} finally {
    PrivateOwner::record(null);
    DataOverrides::setDirForTesting(null);
    shell_exec('rm -rf ' . escapeshellarg($r48sBase));
}
