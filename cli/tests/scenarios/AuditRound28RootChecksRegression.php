<?php

// Review round 28, findings 4 and 5 and lows (all reproduced by the reviewer as root):
// 4: `cli/update.php --installed=<shop>` decided by the owner of the (trusted, root-owned) release tree that root may stay
//    root, then ran the shop's config.php and changed its files as root;
// 5: root kept root in a root-owned tree, but the update directory (in the private directory the web user writes for
//    SQLite) was not checked: code put into a snapshot was installed root:root by a rollback and run as root next;
// low: a tree of group root (www-data:root) left the step gid 0; a POSIX ACL (setfacl u:www-data:rwx src/) shows only as
//    the group bits of a root:root entry and passed the check.
echo "\n--- R28: root — the shop decides (--installed), the update directory is checked, no group 0, ACL masks count ---\n";

$r28cRepo = dirname(TEST_CLI_DIR);
$r28cOnly = (string)file_get_contents($r28cRepo . '/cli/cli-only.php');
assert_true(str_contains($r28cOnly, "((int)\$s['mode'] & 0022) !== 0") && str_contains($r28cOnly, "if (\$gid === 0) \$gid = ")
    && str_contains((string)file_get_contents($r28cRepo . '/cli/update.php'), 'cli_drop_root($root);')
    && str_contains((string)file_get_contents($r28cRepo . '/src/Updater.php'), 'cli_not_root_only($this->stateDirectory)'),
    'R28 root: group write counts for group root, gid 0 is replaced, --installed drops for the shop, the update directory is checked');

if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    echo "  [SKIP] R28 root checks: run as root (CI runs this scenario as root)\n";
    return;
}

$r28cDir = sys_get_temp_dir() . '/r28-root-checks-' . getmypid();
$r28cRun = static function (array $command): array {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out . $err];
};
$r28cTree = static function (string $to) use ($r28cRepo): void {
    // A root-only release tree: src/ and the CLI entry points, root:root, no group or other write.
    mkdir($to . '/cli', 0755, true);
    shell_exec('cp -r ' . escapeshellarg($r28cRepo . '/src') . ' ' . escapeshellarg($to . '/src'));
    foreach (['cli-only.php', 'update.php'] as $file) copy($r28cRepo . '/cli/' . $file, $to . '/cli/' . $file);
    shell_exec('chown -R 0:0 ' . escapeshellarg($to) . ' && chmod -R go-w ' . escapeshellarg($to));
};
try {
    mkdir($r28cDir, 0755);
    chmod($r28cDir, 0755);

    // 5. Root-only shop tree, update directory under a private directory the web user (65534) writes.
    $r28cApp = $r28cDir . '/app';
    $r28cTree($r28cApp);
    mkdir($r28cDir . '/private/updates', 0755, true);
    chown($r28cDir . '/private', 65534);
    file_put_contents($r28cApp . '/config.php', "<?php return ['update_storage_dir' => " . var_export($r28cDir . '/private/updates', true) . "];\n");
    chmod($r28cApp . '/config.php', 0644);
    [$r28cExit, $r28cOut] = $r28cRun([PHP_BINARY, $r28cApp . '/cli/update.php', 'list']);
    assert_true($r28cExit !== 0 && str_contains($r28cOut, "update directory is not root's alone") && str_contains($r28cOut, $r28cDir . '/private'),
        'R28 root: root in a root-only tree refuses an update directory another account can write (' . trim($r28cOut) . ')');
    // Once the update directory is root's alone too, root may run there.
    chown($r28cDir . '/private', 0);
    [$r28cExit, $r28cOut] = $r28cRun([PHP_BINARY, $r28cApp . '/cli/update.php', 'list']);
    assert_true($r28cExit === 0 && str_contains($r28cOut, 'No update snapshots'), 'R28 root: a root-only update directory is accepted (' . trim($r28cOut) . ')');

    // Low: group write of group root (an ACL mask looks the same) is another account's write.
    chmod($r28cApp . '/src', 0775);
    [$r28cExit, $r28cOut] = $r28cRun([PHP_BINARY, $r28cApp . '/cli/update.php', 'list']);
    assert_true($r28cExit === 1 && str_contains($r28cOut, 'REFUSED') && str_contains($r28cOut, $r28cApp . '/src (owner 0, group 0, mode 0775)'),
        'R28 root: a root:root directory with group write is refused (was: "root\'s group" passed) (' . trim($r28cOut) . ')');
    chmod($r28cApp . '/src', 0755);
    if (trim((string)shell_exec('command -v setfacl 2>/dev/null')) !== '') {
        shell_exec('setfacl -m u:65534:rwx ' . escapeshellarg($r28cApp . '/src') . ' 2>/dev/null');
        [$r28cExit, $r28cOut] = $r28cRun([PHP_BINARY, $r28cApp . '/cli/update.php', 'list']);
        assert_true($r28cExit === 1 && str_contains($r28cOut, 'REFUSED'), 'R28 root: setfacl u:<web user>:rwx on src/ is refused (' . trim($r28cOut) . ')');
        shell_exec('setfacl -b ' . escapeshellarg($r28cApp . '/src'));
    }

    // 4 + low: --installed runs as the shop's owner, never with group 0. The shop (65534:root) records who ran its config.php.
    $r28cShop = $r28cDir . '/shop';
    mkdir($r28cShop . '/src', 0755, true);
    mkdir($r28cDir . '/shop-private/updates', 0755, true);
    file_put_contents($r28cShop . '/config.php', "<?php file_put_contents(__DIR__ . '/who', posix_geteuid() . ' ' . posix_getegid() . ' ' . implode(',', posix_getgroups()));\n"
        . "return ['update_storage_dir' => " . var_export($r28cDir . '/shop-private/updates', true) . "];\n");
    shell_exec('chown -R 65534:0 ' . escapeshellarg($r28cShop) . ' ' . escapeshellarg($r28cDir . '/shop-private'));
    [$r28cExit, $r28cOut] = $r28cRun([PHP_BINARY, $r28cApp . '/cli/update.php', 'list', '--installed=' . $r28cShop]);
    $r28cWho = explode(' ', trim((string)@file_get_contents($r28cShop . '/who')));
    assert_true($r28cExit === 0 && ($r28cWho[0] ?? '') === '65534' && ($r28cWho[1] ?? '0') !== '0'
        && !in_array('0', explode(',', $r28cWho[2] ?? '0'), true),
        'R28 root: --installed runs the shop\'s config.php as the shop\'s owner, not with group root (was: as root) (' . trim($r28cOut) . ' / ' . implode(' ', $r28cWho) . ')');
} finally {
    if (is_dir($r28cDir)) shell_exec('rm -rf ' . escapeshellarg($r28cDir));
}
