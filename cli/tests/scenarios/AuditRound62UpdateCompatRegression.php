<?php

// Review round 27 (R62), high 1 (goal #658): a signed update from 2.1.x runs this release's migrations inside the 2.1.x
// updater, whose DataOverrides is already loaded. Bootstrap::stores() called DataOverrides::readStores(), which that class
// does not have, so the update rolled back on every shop. The real update from each released tree is
// cli/test-release-upgrade.php (CI); here the static check against the recorded preloaded API, and the fallback itself.
echo "\n--- Audit round 62: migrations under the updater of an installed 2.1.x release ---\n";
require_once TEST_CLI_DIR . '/tests/UpdaterCompat.php';
$r62uRepo = dirname(TEST_CLI_DIR);
$r62uRoot = test_fixture_path('r62-update-compat');
mkdir($r62uRoot, 0700, true);

try {
    $r62uFixture = json_decode((string)file_get_contents(TEST_CLI_DIR . '/tests/fixtures/updater-preloaded-api.json'), true);
    $r62uReleases = is_array($r62uFixture['releases'] ?? null) ? $r62uFixture['releases'] : [];
    assert_true(isset($r62uReleases['2.1.0'], $r62uReleases['2.1.1'], $r62uReleases['2.1.2'])
        && in_array('readStores', $r62uReleases['2.1.2']['classes']['DataOverrides']['methods'] ?? ['readStores'], true) === false
        && in_array('storesPath', $r62uReleases['2.1.2']['classes']['DataOverrides']['methods'] ?? [], true),
        'R62 compat: the recorded updaters of 2.1.0–2.1.2 had DataOverrides loaded, with storesPath() and without readStores()');
    $r62uCompat = new UpdaterCompat($r62uRepo);
    foreach ($r62uReleases as $r62uVersion => $r62uRelease) {
        $r62uFindings = $r62uCompat->findings($r62uRelease);
        assert_equals([], $r62uFindings, "R62 compat: no migration 2.1.x has not applied reaches a member its updater's loaded classes lack ({$r62uVersion}; was: Bootstrap::stores → DataOverrides::readStores from 6 migrations)");
    }

    // The check bites: an unguarded call to a member the preloaded class lacks, two functions down from a migration.
    mkdir($r62uRoot . '/tree/src', 0700, true);
    mkdir($r62uRoot . '/tree/migrations', 0700, true);
    file_put_contents($r62uRoot . '/tree/src/R62Probe.php', "<?php\nfinal class R62Probe {\n"
        . "    public static function outer(): void { self::inner(); }\n"
        . "    private static function inner(): void { DataOverrides::readStores(); DataOverrides::storesPath(); }\n"
        . "    public static function guarded(): void { if (method_exists('DataOverrides', 'readStores')) DataOverrides::readStores(); }\n}\n");
    file_put_contents($r62uRoot . '/tree/migrations/20991231000000_r62_probe.php',
        "<?php\nreturn new class { public function up(PDO \$db, bool \$isSqlite): void { R62Probe::outer(); R62Probe::guarded(); } };\n");
    $r62uProbe = (new UpdaterCompat($r62uRoot . '/tree'))->findings($r62uReleases['2.1.2'] ?? ['migrations' => [], 'classes' => []]);
    assert_true(count($r62uProbe) === 1 && str_contains($r62uProbe[0], 'src/R62Probe.php:4 DataOverrides::readStores via R62Probe::inner <- R62Probe::outer <- migration 20991231000000_r62_probe::up'),
        'R62 compat: an unguarded readStores() two calls below a new migration is reported with its chain, a method_exists() guard and storesPath() are not (' . implode(' | ', $r62uProbe) . ')');
} catch (Throwable $e) {
    assert_true(false, 'R62 compat analysis (' . get_class($e) . ': ' . $e->getMessage() . ')');
}

try {
    // Bootstrap::stores() under the DataOverrides of 2.1.x: only storesPath() is there.
    $r62uChild = $r62uRoot . '/child.php';
    file_put_contents($r62uChild, <<<'PHP'
<?php
[, $root, $dir] = $argv;
final class DataOverrides {
    public static function storesPath(): string { return $GLOBALS['argv'][2] . '/stores.json'; }
}
require $root . '/src/autoload.php';
$json = json_encode(['default_store' => 'default', 'stores' => ['default' => ['name' => 'R62U']]]);
$read = new ReflectionMethod(Bootstrap::class, 'storesUnderOlderUpdater');
$out = [];
file_put_contents($dir . '/stores.json', $json);
$out['small'] = Bootstrap::stores()['stores']['default']['name'] ?? null;
file_put_contents($dir . '/stores.json', $json . str_repeat(' ', 2 * 1048576 + 1 - strlen($json)));
$out['over2mb'] = $read->invoke(null);
file_put_contents($dir . '/stores.json', '{"stores":');
$out['broken'] = $read->invoke(null);
unlink($dir . '/stores.json');
$out['missing'] = $read->invoke(null);
if (function_exists('posix_mkfifo') && posix_mkfifo($dir . '/stores.json', 0600)) {
    $start = microtime(true);
    $out['fifo'] = [$read->invoke(null), microtime(true) - $start < 2];
    unlink($dir . '/stores.json');
} else {
    $out['fifo'] = [null, true];
}
echo 'r62u ' . json_encode($out) . "\n";
PHP);
    $r62uOut = [];
    exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, $r62uChild, $r62uRepo, $r62uRoot])) . ' 2>&1', $r62uOut);
    $r62uLine = array_values(array_filter($r62uOut, static fn(string $line): bool => str_starts_with($line, 'r62u ')))[0] ?? '';
    assert_equals(['small' => 'R62U', 'over2mb' => null, 'broken' => null, 'missing' => null, 'fifo' => [null, true]],
        $r62uLine !== '' ? json_decode(substr($r62uLine, 5), true) : implode(' | ', $r62uOut),
        'R62 compat: under a 2.1.x DataOverrides, Bootstrap::stores() reads stores.json with the same 2 MB limit, refuses a broken file and a FIFO without waiting (was: Call to undefined method DataOverrides::readStores())');
} catch (Throwable $e) {
    assert_true(false, 'R62 Bootstrap::stores under an older DataOverrides (' . get_class($e) . ': ' . $e->getMessage() . ')');
} finally {
    @unlink($r62uRoot . '/stores.json');
    @unlink($r62uRoot . '/child.php');
}
