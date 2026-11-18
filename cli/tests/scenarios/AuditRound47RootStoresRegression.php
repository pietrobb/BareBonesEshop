<?php

// Review round 17 (R47, low): Updater::configChecks() read private_runtime_dir/stores.json as root, outside
// PrivateOwner::run(). Its owner could replace it with a symlink to a file only root may read (the doctor then told
// whether that file parses as a v1.x stores.json) or with a FIFO (the doctor hung). It is now read as the owner, and
// only as a regular file opened without blocking.
echo "\n--- R47: root's doctor reads the owner's stores.json as the owner, never through a FIFO ---\n";

if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_mkfifo')) {
    echo "  [SKIP] R47 stores.json read: needs Linux (posix_mkfifo)\n";
    return;
}

// Any user: a FIFO is refused at once, a regular file is read, a missing one is null.
$r47sDir = test_fixture_path('r47-stores-read');
mkdir($r47sDir);
posix_mkfifo($r47sDir . '/fifo.json', 0600);
file_put_contents($r47sDir . '/plain.json', '{"stores":{}}');
$r47sProbe = $r47sDir . '/probe.php';
file_put_contents($r47sProbe, '<?php require ' . var_export(dirname(TEST_CLI_DIR) . '/src/autoload.php', true) . ';'
    . ' echo json_encode([DataOverrides::readRegularFile($argv[1] . "/fifo.json"), DataOverrides::readRegularFile($argv[1] . "/plain.json"), DataOverrides::readRegularFile($argv[1] . "/none.json")]);');
$r47sStart = microtime(true);
$r47sOut = (string)shell_exec('timeout 10 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($r47sProbe) . ' ' . escapeshellarg($r47sDir) . ' 2>&1');
assert_equals('[null,"{\"stores\":{}}",null]', trim($r47sOut), 'R47 stores read: a FIFO is refused (not waited on), a regular file is read, a missing file is null');
assert_true(microtime(true) - $r47sStart < 5, 'R47 stores read: the FIFO did not block (' . round(microtime(true) - $r47sStart, 2) . ' s)');

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    echo "  [SKIP] R47 root stores.json: run as root on Linux (wsl -u root / CI root job)\n";
    return;
}

$r47sBase = sys_get_temp_dir() . '/bbe-r47-stores-' . bin2hex(random_bytes(4));
try {
    mkdir($r47sBase, 0755);
    chmod($r47sBase, 0755);
    $r47sApp = $r47sBase . '/app';
    mkdir($r47sApp, 0755);
    mkdir($r47sBase . '/updates', 0700);
    // A file only root may read, in the v1.x stores.json format (no default_store): read as root it makes the doctor
    // report "stores.json is in the v1.x format".
    $r47sSecret = $r47sBase . '/root-only.json';
    file_put_contents($r47sSecret, json_encode(['stores' => ['sk' => ['url_prefix' => '', 'currency' => 'eur']]]));
    chmod($r47sSecret, 0600);
    $r47sPrivate = $r47sBase . '/private';
    mkdir($r47sPrivate, 0755);
    symlink($r47sSecret, $r47sPrivate . '/stores.json');
    shell_exec('chown -hR 65534:65534 ' . escapeshellarg($r47sPrivate));
    $r47sConfig = ['private_runtime_dir' => $r47sPrivate];
    $r47sOwner = PrivateOwner::find([$r47sPrivate]);
    assert_true(is_array($r47sOwner) && $r47sOwner['uid'] === 65534, 'R47 root stores: the private directory is the web user\'s');
    $r47sStoresCheck = static function () use ($r47sApp, $r47sBase, $r47sConfig): ?string {
        foreach ((new Updater($r47sApp, $r47sBase . '/updates'))->doctor(null, null, false, $r47sConfig)['checks'] as $check) {
            if ($check['name'] === 'stores') return $check['level'];
        }
        return null;
    };
    PrivateOwner::record($r47sOwner);
    try {
        $r47sViaLink = $r47sStoresCheck();
        // The owner's own v1.x stores.json is still found (the check itself keeps working).
        unlink($r47sPrivate . '/stores.json');
        copy($r47sSecret, $r47sPrivate . '/stores.json');
        chown($r47sPrivate . '/stores.json', 65534);
        chmod($r47sPrivate . '/stores.json', 0600);
        $r47sOwn = $r47sStoresCheck();
    } finally {
        PrivateOwner::record(null);
    }
    assert_equals([null, 'warn'], [$r47sViaLink, $r47sOwn],
        'R47 root stores: a symlink to a root-only file says nothing about it (was: v1.x warning read as root); the owner\'s own v1.x stores.json is still reported');
} catch (Throwable $r47sE) {
    assert_true(false, 'R47 root stores (' . get_class($r47sE) . ': ' . $r47sE->getMessage() . ' @ ' . basename($r47sE->getFile()) . ':' . $r47sE->getLine() . ')');
} finally {
    PrivateOwner::record(null);
    shell_exec('rm -rf ' . escapeshellarg($r47sBase));
}
