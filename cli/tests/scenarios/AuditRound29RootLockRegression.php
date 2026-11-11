<?php
// Review round 29, finding 1 (high; reproduced by the reviewer and every agent, as root):
// - in a tree of group root the first step made .maintenance.lock 0660 root:root (MaintenanceMode umask 0007), R28's rule
//   (cli-only.php: group write is another account's, even group root's) then refused every later root step: worker,
//   backup, migration, update; `setup.php install` as root failed half-way. The message blamed code in an empty lock.
// - a tree root owns could not be updated at all: the theme probe refused root and told to run as the owner (root), and
//   bbe-step.sh refused root-owned trees. A release extracted as root keeps the archive's 0664 and was refused unexplained.
echo "\n--- R29: root in a root-owned tree — the step lock does not lock root out, the tree can be updated ---\n";

$r29lRepo = dirname(TEST_CLI_DIR);
$r29lMode = (string)file_get_contents($r29lRepo . '/src/MaintenanceMode.php');
assert_true(str_contains($r29lMode, "(int)\$app['gid'] === 0 ? 0077 : 0007") && !str_contains($r29lMode, 'umask(0007)'),
    'R29 lock: the step lock of a tree of group root is created 0600, not 0660');
$r29lReadme = (string)file_get_contents($r29lRepo . '/README.md');
assert_true(str_contains($r29lReadme, '**A tree root owns** is supported') && str_contains($r29lReadme, 'tar --no-same-permissions')
    && !str_contains($r29lReadme, 'refuses a tree root owns') && str_contains((string)file_get_contents($r29lRepo . '/CHANGELOG.md'), 'BBE_INSTALLED_ROOT='),
    'R29 docs: the README says when a tree root owns is supported and how to extract a release as root; the upgrade notes name BBE_INSTALLED_ROOT');

if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    echo "  [SKIP] R29 root lock: run as root (CI runs this scenario as root)\n";
    return;
}

$r29lDir = sys_get_temp_dir() . '/r29-root-lock-' . getmypid();
$r29lRun = static function (array $command, array $env = []): array {
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env === [] ? null : $env + getenv());
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out, $err];
};
$r29lPerm = static function (string $path): string { clearstatcache(); return sprintf('%04o', (int)(@stat($path)['mode'] ?? 0) & 07777); };
try {
    mkdir($r29lDir, 0755);
    chmod($r29lDir, 0755);
    // A whole shop as a clone has it (tracked files, the bbf submodule), root:root without group or other write.
    $r29lApp = $r29lDir . '/shop';
    mkdir($r29lApp);
    shell_exec('cd ' . escapeshellarg($r29lRepo) . ' && tar --exclude=./.git --exclude=./node_modules --exclude=./releases --exclude=./docs'
        . ' --exclude=./cli/tests/fixtures --exclude=./config.php --exclude=./db --exclude=./cache --exclude=./logs --exclude=./test-results'
        . ' --exclude=./playwright-report --exclude=./.maintenance --exclude=./.maintenance.lock --exclude=./.migrations.lock -cf - . 2>/dev/null'
        . ' | tar -xf - -C ' . escapeshellarg($r29lApp));
    file_put_contents($r29lApp . '/cli/r29-lockstep.php', "<?php\nrequire __DIR__ . '/cli-only.php';\nrequire dirname(__DIR__) . '/src/autoload.php';\n"
        . "\$ok = MaintenanceMode::claim(dirname(__DIR__));\nMaintenanceMode::unclaim(dirname(__DIR__));\necho \$ok ? 'claimed' : 'busy', ' ', posix_geteuid(), \"\\n\";\n");
    shell_exec('chown -R 0:0 ' . escapeshellarg($r29lApp) . ' && chmod -R go-w ' . escapeshellarg($r29lApp));
    $r29lLock = $r29lApp . '/.maintenance.lock';
    $r29lStep = static fn(): array => $r29lRun([PHP_BINARY, $r29lApp . '/cli/r29-lockstep.php']);

    // The reported sequence: setup.php install as root, then more root steps.
    $r29lInstall = $r29lRun([PHP_BINARY, $r29lApp . '/cli/setup.php', 'install', '--json', '--app-url=http://127.0.0.1:9', '--admin-username=owner'],
        ['BBE_ADMIN_PASSWORD' => 'R29-root-lock-Passw0rd!']);
    assert_true($r29lInstall[0] === 0 && (json_decode($r29lInstall[1], true)['status'] ?? null) === 'installed',
        'R29 lock: setup.php install as root on a root-owned clean tree completes (was: refused half-way by its own lock) (' . substr(trim($r29lInstall[1] . $r29lInstall[2]), -500) . ')');
    [$r29lExit, $r29lOut, $r29lErr] = $r29lStep();
    assert_true($r29lExit === 0 && trim($r29lOut) === 'claimed 0', 'R29 lock: a root step after the first one runs (' . trim($r29lErr . $r29lOut) . ')');
    [$r29lExit, $r29lOut, $r29lErr] = $r29lStep();
    assert_true($r29lExit === 0 && trim($r29lOut) === 'claimed 0', 'R29 lock: and the one after (' . trim($r29lErr . $r29lOut) . ')');
    assert_equals('0600', $r29lPerm($r29lLock), 'R29 lock: root made the lock of a tree of group root 0600 root:root');
    [$r29lExit, $r29lOut, $r29lErr] = $r29lRun([PHP_BINARY, $r29lApp . '/cli/migrate.php']);
    assert_true($r29lExit === 0 && !str_contains($r29lErr, 'REFUSED'), 'R29 lock: migrate.php as root after install runs (' . substr(trim($r29lErr . $r29lOut), -300) . ')');

    // A 0660 root:root lock left by 2.1.3 neither refuses a step nor stays: it is replaced by a 0600 one.
    chmod($r29lLock, 0660);
    [$r29lExit, $r29lOut, $r29lErr] = $r29lStep();
    assert_true($r29lExit === 0 && trim($r29lOut) === 'claimed 0', 'R29 lock: the 0660 root:root lock of 2.1.3 does not refuse root (' . trim($r29lErr . $r29lOut) . ')');
    assert_equals('0600', $r29lPerm($r29lLock), 'R29 lock: the 0660 lock of 2.1.3 is replaced by a 0600 one');
    // The lock is spared only as the lock: writable by others, a hard link or anything else of group write is refused.
    chmod($r29lLock, 0666);
    [$r29lExit, , $r29lErr] = $r29lStep();
    assert_true($r29lExit === 1 && str_contains($r29lErr, 'REFUSED') && str_contains($r29lErr, '.maintenance.lock'), 'R29 lock: a lock others can write is refused (' . trim($r29lErr) . ')');
    chmod($r29lLock, 0600);
    link($r29lLock, $r29lApp . '/r29-hardlink');
    chmod($r29lLock, 0660);
    [$r29lExit, , $r29lErr] = $r29lStep();
    assert_true($r29lExit === 1 && str_contains($r29lErr, 'REFUSED'), 'R29 lock: a group-writable lock with a second name is refused (' . trim($r29lErr) . ')');
    unlink($r29lApp . '/r29-hardlink');
    chmod($r29lLock, 0600);

    // A release extracted as root keeps the archive's 0664: refused, and the message says how to make it root's alone
    // (it no longer tells root to run the step as the owner, which is root).
    chmod($r29lApp . '/src/Money.php', 0664);
    [$r29lExit, , $r29lErr] = $r29lStep();
    assert_true($r29lExit === 1 && str_contains($r29lErr, 'src/Money.php (owner 0, group 0, mode 0664)') && str_contains($r29lErr, 'chmod -R go-w ' . $r29lApp)
        && str_contains($r29lErr, 'tar --no-same-permissions') && !str_contains($r29lErr, 'sudo -u <owner>'),
        'R29 lock: a 0664 file of an extracted release is refused with the command that fixes it (' . trim($r29lErr) . ')');
    chmod($r29lApp . '/src/Money.php', 0644);

    // The theme probe of a root-only candidate copied from a root-only shop runs as root (was: always NO-GO "run as the
    // owner", the owner being root). The gate names the shop (--installed-root), root may name it for 2.1.2 (environment).
    $r29lProbeRun = static fn(array $args = [], array $env = []): array => json_decode(trim($r29lRun([PHP_BINARY, $r29lApp . '/cli/tests/ThemeContractProbe.php', ...$args], $env)[1]), true) ?? [];
    $r29lProbe = $r29lProbeRun(['--installed-root=' . $r29lApp]);
    assert_true(($r29lProbe['status'] ?? null) === 'GO' && (int)($r29lProbe['checks'] ?? 0) > 0,
        'R29 update: the checkout probe of a root-only tree runs as root and passes (' . substr(json_encode($r29lProbe), 0, 400) . ')');
    assert_equals('GO', $r29lProbeRun([], ['BBE_INSTALLED_ROOT' => $r29lApp])['status'] ?? null, 'R29 update: the 2.1.2 updater as root: root names the shop in BBE_INSTALLED_ROOT');
    assert_equals('GO', $r29lProbeRun()['status'] ?? null, 'R29 update: theme-check as root in a root-only shop (the tree is the shop) runs the probe');
    $r29lCandidate = $r29lDir . '/theme-candidate-r29test';
    rename($r29lApp, $r29lCandidate);
    try {
        $r29lProbe = json_decode(trim($r29lRun([PHP_BINARY, $r29lCandidate . '/cli/tests/ThemeContractProbe.php'])[1]), true) ?? [];
    } finally {
        rename($r29lCandidate, $r29lApp);
    }
    assert_true(($r29lProbe['status'] ?? null) === 'NO-GO' && str_contains((string)($r29lProbe['failures'][0] ?? ''), 'BBE_INSTALLED_ROOT'),
        'R29 update: a gate candidate run as root that is not told which shop it copied is a NO-GO saying how to name it (' . substr(json_encode($r29lProbe), 0, 400) . ')');
    // A root-only copy of a shop another account can change: root would run that account's code.
    mkdir($r29lDir . '/webshop');
    chown($r29lDir . '/webshop', 65534);
    $r29lProbe = $r29lProbeRun(['--installed-root=' . $r29lDir . '/webshop']);
    assert_true(($r29lProbe['status'] ?? null) === 'NO-GO' && str_contains((string)($r29lProbe['failures'][0] ?? ''), 'Not a theme problem')
        && str_contains((string)($r29lProbe['failures'][0] ?? ''), $r29lDir . '/webshop'),
        'R29 update: root does not run a copy of a shop another account can change (' . substr(json_encode($r29lProbe), 0, 400) . ')');
    chmod($r29lApp . '/themes', 0775);
    $r29lProbe = $r29lProbeRun(['--installed-root=' . $r29lApp]);
    assert_true(($r29lProbe['status'] ?? null) === 'NO-GO' && str_contains((string)($r29lProbe['failures'][0] ?? ''), 'Not a theme problem')
        && str_contains((string)($r29lProbe['failures'][0] ?? ''), $r29lApp . '/themes'),
        'R29 update: a candidate another account can change is a NO-GO that names the path, not a theme failure (' . substr(json_encode($r29lProbe), 0, 400) . ')');
    chmod($r29lApp . '/themes', 0755);

    // The whole update of a shop root owns alone, as root: check, the theme gate (its probe as root), apply.
    foreach (['base', 'target'] as $r29lTree) {
        mkdir($r29lDir . "/{$r29lTree}/src", 0755, true);
        copy($r29lApp . '/src/Money.php', $r29lDir . "/{$r29lTree}/src/Money.php");
    }
    file_put_contents($r29lDir . '/target/src/Money.php', "\n// r29 update\n", FILE_APPEND);
    UpdatePackageBuilder::build($r29lDir . '/base', $r29lDir . '/target', $r29lDir . '/package', '9.9.9', '9.9.8');
    $r29lUpdate = static fn(string ...$args): array => $r29lRun([PHP_BINARY, $r29lApp . '/cli/update.php', ...$args]);
    [$r29lExit, $r29lOut, $r29lErr] = $r29lUpdate('baseline', '--version=9.9.8');
    assert_true($r29lExit === 0, 'R29 update: baseline as root in a shop root owns (' . trim($r29lErr . $r29lOut) . ')');
    [$r29lExit, $r29lOut, $r29lErr] = $r29lUpdate('apply', '--package=' . $r29lDir . '/package', '--allow-unsigned');
    assert_true($r29lExit === 0 && str_contains($r29lOut, 'Updated to 9.9.9') && str_contains((string)file_get_contents($r29lApp . '/src/Money.php'), '// r29 update'),
        'R29 update: a shop root owns alone is updated as root, theme gate included (was: NO-GO "run as the owner") (' . substr(trim($r29lErr . ' ' . $r29lOut), -700) . ')');
    [$r29lExit, $r29lOut, $r29lErr] = $r29lStep();
    assert_true($r29lExit === 0 && trim($r29lOut) === 'claimed 0', 'R29 update: and root steps still run after it (' . trim($r29lErr . $r29lOut) . ')');

    // bbe-step runs a step of a tree root owns alone as root (R28 refused it), and refuses one another account can change.
    if (trim((string)shell_exec('command -v setsid 2>/dev/null')) !== '') {
        $r29lWrap = [$r29lApp . '/deploy/bbe-step.sh', $r29lApp, 'r29-lockstep.php'];
        chmod($r29lLock, 0660); // as 2.1.3 left it
        [$r29lExit, $r29lOut, $r29lErr] = $r29lRun(['/bin/sh', ...$r29lWrap]);
        assert_true($r29lExit === 0 && trim($r29lOut) === 'claimed 0', 'R29 bbe-step: a step of a tree root owns alone runs as root (' . trim($r29lErr . $r29lOut) . ')');
        chmod($r29lApp . '/src/Money.php', 0664);
        [$r29lExit, $r29lOut, $r29lErr] = $r29lRun(['/bin/sh', ...$r29lWrap]);
        assert_true($r29lExit === 1 && $r29lOut === '' && str_contains($r29lErr, $r29lApp . '/src/Money.php') && str_contains($r29lErr, 'chmod -R go-w'),
            'R29 bbe-step: a group-writable file in a root-owned tree is refused before PHP runs (' . trim($r29lErr) . ')');
        chmod($r29lApp . '/src/Money.php', 0644);
        symlink('/etc', $r29lApp . '/r29-out');
        [$r29lExit, $r29lOut, $r29lErr] = $r29lRun(['/bin/sh', ...$r29lWrap]);
        assert_true($r29lExit === 1 && $r29lOut === '' && str_contains($r29lErr, 'symbolic link out of the tree'), 'R29 bbe-step: a link out of a root-owned tree is refused (' . trim($r29lErr) . ')');
        unlink($r29lApp . '/r29-out');
        chown($r29lApp . '/cli', 65534);
        [$r29lExit, $r29lOut, $r29lErr] = $r29lRun(['/bin/sh', ...$r29lWrap]);
        assert_true($r29lExit === 1 && $r29lOut === '' && str_contains($r29lErr, $r29lApp . '/cli'), 'R29 bbe-step: an entry of another owner in a root-owned tree is refused (' . trim($r29lErr) . ')');
        chown($r29lApp . '/cli', 0);
        chmod($r29lDir, 0777);
        [$r29lExit, $r29lOut, $r29lErr] = $r29lRun(['/bin/sh', ...$r29lWrap]);
        assert_true($r29lExit === 1 && $r29lOut === '' && str_contains($r29lErr, 'above the root-owned'), 'R29 bbe-step: a writable directory above a root-owned tree is refused (' . trim($r29lErr) . ')');
        chmod($r29lDir, 0755);
    } else {
        echo "  [SKIP] R29 bbe-step: no setsid\n";
    }
} catch (Throwable $e) {
    assert_true(false, 'R29 root lock: the scenario raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if (is_dir($r29lDir)) {
        @unlink($r29lApp . '/r29-out');
        FileSystem::removeTree($r29lDir);
    }
}
