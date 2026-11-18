<?php

// Review round 16 (R44, low 2 and 3): the doctor root runs checked the private directories with is_writable() inside
// PrivateOwner::run(); access() goes by the real user (root), so a jobs_dir the owner cannot write was reported
// writable. And `update.php doctor` as root created the update directory (root's) in the web user's private directory
// before it refused to use it. The doctor now creates and deletes a file as the owner; root creates the update
// directory only where it may use it.
echo "\n--- R44 root: doctor checks the private paths by writing as the owner; no root update directory left behind ---\n";

$r44dSource = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Updater.php');
assert_true(str_contains($r44dSource, "private static function canCreateIn(") && !str_contains($r44dSource, 'is_writable($existing)'),
    'R44 doctor: the private paths are probed by creating a file, not with is_writable()');

if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    echo "  [SKIP] R44 root doctor: run as root on Linux (wsl -u root / CI root job)\n";
    return;
}

$r44dBase = sys_get_temp_dir() . '/bbe-r44-doctor-' . bin2hex(random_bytes(4));
try {
    mkdir($r44dBase, 0755);
    chmod($r44dBase, 0755);
    $r44dApp = $r44dBase . '/app';
    mkdir($r44dApp, 0755);
    $r44dPrivate = $r44dBase . '/private';
    mkdir($r44dPrivate . '/jobs', 0755, true);
    mkdir($r44dPrivate . '/locked', 0755);
    mkdir($r44dPrivate . '/secrets', 0755);
    shell_exec('chown -R 65534:65534 ' . escapeshellarg($r44dPrivate));
    chmod($r44dPrivate . '/jobs', 0555);   // the owner's, but not writable even by the owner
    chmod($r44dPrivate . '/locked', 0555);
    mkdir($r44dBase . '/updates', 0700);
    $r44dConfig = ['private_runtime_dir' => $r44dPrivate, 'jobs_dir' => $r44dPrivate . '/jobs', 'private_cache_dir' => $r44dPrivate . '/locked/cache',
        'gdpr_salt_file' => $r44dPrivate . '/secrets/gdpr_salt'];
    $r44dOwner = PrivateOwner::find([$r44dPrivate]);
    assert_true(is_array($r44dOwner) && $r44dOwner['uid'] === 65534, 'R44 doctor: the private directory is the web user\'s');

    $r44dLevels = static function (array $report): array {
        $levels = [];
        foreach ($report['checks'] as $check) {
            if (in_array($check['name'], ['jobs_dir', 'private_cache_dir', 'gdpr_salt_file'], true)) $levels[$check['name']] = $check['level'];
        }
        return $levels;
    };
    PrivateOwner::record($r44dOwner);
    try {
        $r44dChecks = (new Updater($r44dApp, $r44dBase . '/updates'))->doctor(null, null, false, $r44dConfig);
        $r44dFirst = $r44dLevels($r44dChecks);
        chmod($r44dPrivate . '/jobs', 0755);
        chmod($r44dPrivate . '/locked', 0755);
        $r44dSecond = $r44dLevels((new Updater($r44dApp, $r44dBase . '/updates'))->doctor(null, null, false, $r44dConfig));
    } finally {
        PrivateOwner::record(null);
    }
    clearstatcache();
    assert_equals([['jobs_dir' => 'fail', 'private_cache_dir' => 'fail', 'gdpr_salt_file' => 'ok'],
        ['jobs_dir' => 'ok', 'private_cache_dir' => 'ok', 'gdpr_salt_file' => 'ok'], 0],
        [$r44dFirst, $r44dSecond, count(glob($r44dPrivate . '/{,*/}.bbe-doctor-*', GLOB_BRACE) ?: [])],
        'R44 doctor: as root, a jobs_dir and a cache parent the owner cannot write are FAIL (was: PASS), writable ones PASS; no probe file is left');

    // The update directory in the web user's private directory: refused before root creates it.
    $r44dRefused = '';
    try {
        new Updater($r44dApp, $r44dPrivate . '/updates');
    } catch (RuntimeException $r44dErr) {
        $r44dRefused = $r44dErr->getMessage();
    }
    clearstatcache();
    assert_true(str_contains($r44dRefused, 'REFUSED') && !file_exists($r44dPrivate . '/updates'),
        'R44 doctor: root refuses an update directory in the web user\'s private directory without creating it (was: left root\'s updates/) (' . $r44dRefused . ')');
    // A missing one in a root-only directory is still created.
    new Updater($r44dApp, $r44dBase . '/state/updates');
    clearstatcache();
    assert_true(is_dir($r44dBase . '/state/updates') && fileowner($r44dBase . '/state/updates') === 0,
        'R44 doctor: root still creates a missing update directory in a directory only root can write');
} catch (Throwable $r44dE) {
    assert_true(false, 'R44 root doctor (' . get_class($r44dE) . ': ' . $r44dE->getMessage() . ' @ ' . basename($r44dE->getFile()) . ':' . $r44dE->getLine() . ')');
} finally {
    PrivateOwner::record(null);
    shell_exec('rm -rf ' . escapeshellarg($r44dBase));
}
