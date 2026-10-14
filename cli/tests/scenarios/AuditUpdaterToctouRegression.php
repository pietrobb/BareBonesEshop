<?php
/**
 * Audit 2026-10-12 / A09.1 / finding 22 — updater payload TOCTOU.
 *
 * UpdatePackage verifies payload hashes once, in its constructor. Updater::apply() copied the
 * payload much later (after the pre-update snapshot, which on MySQL is a minutes-long dump).
 * A payload swapped inside that window was installed unverified, and a failure in the middle of
 * the copy loop left a partially replaced tree. Every write must be staged, re-hashed against
 * the signed manifest, and only then swapped in — a mismatch must fail before any file changes.
 */

echo "\n--- Audit A09.1: updater re-verifies payloads at install time ---\n";

final class A091TamperingBackup implements UpdateDatabaseBackup
{
    public int $restores = 0;

    public function __construct(private string $payloadFile, private string $tamperedContent)
    {
    }

    public function probe(string $workingDirectory): array
    {
        return [];
    }

    public function create(string $snapshotDirectory): array
    {
        // The dump window: the package was verified long before this point.
        file_put_contents($this->payloadFile, $this->tamperedContent);
        return ['driver' => 'mysql', 'fake' => true];
    }

    public function restore(string $snapshotDirectory, array $metadata): void
    {
        $this->restores++;
    }
}

function a091_tree(string $root, array $files): void
{
    foreach ($files as $path => $content) {
        $absolute = $root . '/' . $path;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0750, true);
        }
        file_put_contents($absolute, $content);
    }
}

function a091_snapshot_status(string $stateDirectory): array
{
    $statuses = [];
    foreach (glob($stateDirectory . '/snapshots/*/snapshot.json') ?: [] as $file) {
        $statuses[] = (string)(json_decode((string)file_get_contents($file), true)['status'] ?? '');
    }
    return $statuses;
}

$a091Root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bbe-a091-' . getmypid() . '-' . bin2hex(random_bytes(4));
try {
    // Scenario 1: payload swapped between package verification and apply (any driver).
    $base = $a091Root . '/base';
    $target = $a091Root . '/target';
    $installed = $a091Root . '/installed';
    a091_tree($base, ['src/A.php' => "old a\n", 'src/B.php' => "old b\n"]);
    a091_tree($target, ['src/A.php' => "new a\n", 'src/B.php' => "new b\n"]);
    a091_tree($installed, ['src/A.php' => "old a\n", 'src/B.php' => "old b\n"]);
    UpdatePackageBuilder::build($base, $target, $a091Root . '/package', '9.1.0', '9.0.0');
    $package = new UpdatePackage($a091Root . '/package', null, true);
    $updater = new Updater($installed, $a091Root . '/state');
    $updater->baseline('9.0.0');

    // Only the second file (in manifest order) is swapped, so a non-staged loop installs A first.
    file_put_contents($a091Root . '/package/payload/src/B.php', "<?php /* attacker */\n");
    $error = '';
    try {
        $updater->apply($package);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    assert_true(str_contains($error, 'checksum mismatch'), 'A09.1: payload swapped after verification is refused at install time');
    assert_equals("old b\n", file_get_contents($installed . '/src/B.php'), 'A09.1: tampered payload is never installed');
    assert_equals("old a\n", file_get_contents($installed . '/src/A.php'), 'A09.1: no partial replacement — the untampered sibling stays on the old release');
    $state = json_decode((string)file_get_contents($a091Root . '/state/installed.json'), true);
    assert_equals('9.0.0', $state['version'] ?? null, 'A09.1: installed version is unchanged after the refused apply');
    assert_equals(['aborted'], a091_snapshot_status($a091Root . '/state'), 'A09.1: pre-update snapshot is marked aborted, not rolled back over untouched files');
    $leftovers = array_merge(glob($installed . '/src/.*.update-*') ?: [], glob($installed . '/src/.*.old-*') ?: []);
    assert_equals([], $leftovers, 'A09.1: no staged temporary files are left in the webroot');

    // The honest package still applies afterwards.
    file_put_contents($a091Root . '/package/payload/src/B.php', "new b\n");
    try {
        $applied = $updater->apply(new UpdatePackage($a091Root . '/package', null, true));
    } catch (Throwable $e) {
        $applied = ['version' => $e->getMessage()];
    }
    assert_equals('9.1.0', $applied['version'], 'A09.1: restored payload applies normally');
    assert_equals("new a\nnew b\n", file_get_contents($installed . '/src/A.php') . file_get_contents($installed . '/src/B.php'), 'A09.1: every verified file is installed');

    // Scenario 2: the MySQL dump window — payload is swapped while the pre-update dump runs.
    if (getenv('TEST_MYSQL_DSN')) {
        $mBase = $a091Root . '/m-base';
        $mTarget = $a091Root . '/m-target';
        $mInstalled = $a091Root . '/m-installed';
        a091_tree($mBase, ['src/Core.php' => "m old\n"]);
        a091_tree($mTarget, ['src/Core.php' => "m new\n", 'migrations/20991231000000_a091.php' => "<?php return new class {};\n"]);
        a091_tree($mInstalled, ['src/Core.php' => "m old\n"]);
        UpdatePackageBuilder::build($mBase, $mTarget, $a091Root . '/m-package', '9.3.0', '9.2.0');
        $mPackage = new UpdatePackage($a091Root . '/m-package', null, true);
        $mysql = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $backup = new A091TamperingBackup($a091Root . '/m-package/payload/src/Core.php', "<?php /* swapped during dump */\n");
        $migrations = 0;
        $mUpdater = new Updater($mInstalled, $a091Root . '/m-state', $mysql, static function () use (&$migrations): void {
            $migrations++;
        }, $backup);
        $mUpdater->baseline('9.2.0');
        $mError = '';
        try {
            $mUpdater->apply($mPackage);
        } catch (Throwable $e) {
            $mError = $e->getMessage();
        }
        assert_true(str_contains($mError, 'checksum mismatch'), 'A09.1 mysql: payload swapped during the dump window is refused');
        assert_equals("m old\n", file_get_contents($mInstalled . '/src/Core.php'), 'A09.1 mysql: swapped payload is never installed');
        assert_true(!is_file($mInstalled . '/migrations/20991231000000_a091.php'), 'A09.1 mysql: no file of the refused package lands in the webroot');
        assert_equals(0, $migrations, 'A09.1 mysql: migrations never run for a refused payload');
        assert_equals(0, $backup->restores, 'A09.1 mysql: refused apply does not restore a dump over an untouched database');
        $mysql = null;
    } else {
        echo "  [SKIP] Audit A09.1 MySQL dump window (set TEST_MYSQL_DSN)\n";
    }
} finally {
    if (function_exists('updateTestRemoveTree')) {
        updateTestRemoveTree($a091Root);
    }
}
