<?php

// Review round 19 (R49, finding 1): R47/R48 read the owner's stores.json as the owner, but root's update doctor still
// read private_runtime_dir/mail.json as root (UpgradeChecks::mailSender → MailSettings::raw → file_get_contents). A
// symlink to a root-only file put its sender address into the doctor's output; a FIFO swapped in after is_file() hung
// the doctor. MailSettings::raw() now reads as the owner, non-blocking, as a regular file.
echo "\n--- R49: the update doctor reads the owner's mail.json as the owner, never through a FIFO ---\n";

if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid') || posix_geteuid() !== 0 || !function_exists('posix_mkfifo')) {
    echo "  [SKIP] R49 doctor mail.json: run as root on Linux (wsl -u root / CI root job)\n";
    return;
}

$r49mBase = sys_get_temp_dir() . '/bbe-r49-mail-' . bin2hex(random_bytes(4));
$r49mApp = (string)realpath(dirname(TEST_CLI_DIR));
$r49mOwnJson = '{"mode":"log","from":"shop@r49-owner.example"}';
$r49mEnvFrom = getenv('MAIL_FROM');
putenv('MAIL_FROM');
try {
    mkdir($r49mBase, 0755);
    chmod($r49mBase, 0755);
    $r49mSecret = $r49mBase . '/root-only.json';
    file_put_contents($r49mSecret, '{"mode":"log","from":"secret@r49-root-only.example"}');
    chmod($r49mSecret, 0600);
    $r49mPrivate = $r49mBase . '/private';
    mkdir($r49mPrivate, 0755);
    symlink($r49mSecret, $r49mPrivate . '/' . MailSettings::FILE);
    shell_exec('chown -hR 65534:65534 ' . escapeshellarg($r49mPrivate));
    $r49mOwner = PrivateOwner::find([$r49mPrivate]);
    assert_true(is_array($r49mOwner) && $r49mOwner['uid'] === 65534, 'R49 doctor mail: the private directory is the web user\'s');

    DataOverrides::setDirForTesting($r49mPrivate);
    PrivateOwner::record($r49mOwner);
    try {
        $r49mLink = UpgradeChecks::mailSender([]);
        unlink($r49mPrivate . '/' . MailSettings::FILE);
        file_put_contents($r49mPrivate . '/' . MailSettings::FILE, $r49mOwnJson);
        chown($r49mPrivate . '/' . MailSettings::FILE, 65534);
        chmod($r49mPrivate . '/' . MailSettings::FILE, 0600);
        $r49mOwn = UpgradeChecks::mailSender([]);
    } finally {
        PrivateOwner::record(null);
        DataOverrides::setDirForTesting(null);
    }
    assert_true(!str_contains(json_encode($r49mLink), 'r49-root-only'),
        'R49 doctor mail: a mail.json symlink to a root-only file reveals nothing of it (was: its sender address in the doctor output) — ' . json_encode($r49mLink));
    assert_true(str_contains($r49mOwn[1], 'shop@r49-owner.example'), 'R49 doctor mail: the owner\'s own mail.json is still read — ' . json_encode($r49mOwn));

    // The FIFO: the owner swaps mail.json between its regular file (a hard link) and a new FIFO while the doctor's mail
    // check runs for 3 s (at least 2000 times) as root. Each must finish (the old file_get_contents() blocked in open() on a FIFO).
    unlink($r49mPrivate . '/' . MailSettings::FILE);
    file_put_contents($r49mPrivate . '/plain.json', $r49mOwnJson);
    shell_exec('chown -hR 65534:65534 ' . escapeshellarg($r49mPrivate) . '; chmod 0600 ' . escapeshellarg($r49mPrivate . '/plain.json'));
    $r49mProbe = $r49mBase . '/race.php';
    file_put_contents($r49mProbe, '<?php require ' . var_export($r49mApp . '/src/autoload.php', true) . ';
[, $private] = $argv;
DataOverrides::setDirForTesting($private);
PrivateOwner::record(PrivateOwner::find([$private]));
$null = [0 => ["file", "/dev/null", "r"], 1 => ["file", "/dev/null", "w"], 2 => ["file", "/dev/null", "w"]];
$racer = proc_open([PHP_BINARY, "-r", \'posix_setgid(65534); posix_setuid(65534); $d = $argv[1]; $end = microtime(true) + 60; while (microtime(true) < $end) { @link("$d/plain.json", "$d/a"); @rename("$d/a", "$d/mail.json"); usleep(20); @posix_mkfifo("$d/b", 0600); @rename("$d/b", "$d/mail.json"); }\', $private], $null, $pipes);
$seen = [];
try {
    for ($wait = 0; $wait < 500 && !file_exists("$private/mail.json"); $wait++) usleep(10000);
    for ($i = 0, $end = microtime(true) + 3; $i < 2000 || microtime(true) < $end; $i++) {
        $message = UpgradeChecks::mailSender([])[1];
        $kind = str_contains($message, "shop@r49-owner.example") ? "read" : (str_contains($message, "MAIL_FROM is not set") ? "unset" : "other");
        $seen[$kind] = ($seen[$kind] ?? 0) + 1;
    }
} finally {
    proc_terminate($racer, 9);
    proc_close($racer);
}
ksort($seen);
echo json_encode($seen);');
    $r49mStart = microtime(true);
    $r49mOut = (string)shell_exec('timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($r49mProbe) . ' ' . escapeshellarg($r49mPrivate) . ' 2>&1');
    $r49mSeen = json_decode(trim($r49mOut), true);
    // "other": the check reads mail.json twice (MailService::sender, MailSettings::from) and the swap fell in between.
    assert_true(is_array($r49mSeen) && array_sum($r49mSeen) >= 2000 && ($r49mSeen['read'] ?? 0) > 0,
        'R49 doctor mail: 3 s of doctor mail checks against a FIFO swapped in finish and still read the owner\'s file (was: hung) — '
        . trim($r49mOut) . ' in ' . round(microtime(true) - $r49mStart, 1) . ' s');
} catch (Throwable $r49mE) {
    assert_true(false, 'R49 doctor mail (' . get_class($r49mE) . ': ' . $r49mE->getMessage() . ' @ ' . basename($r49mE->getFile()) . ':' . $r49mE->getLine() . ')');
} finally {
    PrivateOwner::record(null);
    DataOverrides::setDirForTesting(null);
    if ($r49mEnvFrom !== false) putenv('MAIL_FROM=' . $r49mEnvFrom);
    shell_exec('rm -rf ' . escapeshellarg($r49mBase));
}
