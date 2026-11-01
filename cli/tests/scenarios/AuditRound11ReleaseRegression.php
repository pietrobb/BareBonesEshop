<?php

// Round 11 review (2026-11-01) "release and CI": stores.json was tracked by git and the admin saved into it, so the
// first `git pull` after a merchant edit conflicted. Git now tracks only stores.example.json; the shop's own copy lives
// in private_runtime_dir (setup apply creates or moves it), update packages never touch a stores.json, and the
// Stripe workflow skips pull requests that get no secrets (Dependabot, forks) instead of failing them.
echo "\n--- Audit round 11 (release & CI): stores.json out of git, migration, packages, Dependabot Stripe skip ---\n";
$r11rFail = static fn(string $what, Throwable $e) => assert_true(false, "R11 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
$r11rSrc = dirname(TEST_CLI_DIR);
$r11rBase = test_fixture_path('r11-release');
$r11rRemove = static function (string $path) use (&$r11rRemove): void {
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        if ($item->isDir()) { @rmdir($item->getPathname()); continue; }
        @chmod($item->getPathname(), 0666);
        @unlink($item->getPathname());
    }
    @rmdir($path);
};
$r11rGit = static function (string $cwd, array $args): array {
    $process = proc_open(array_merge(['git', '-c', 'user.name=r11', '-c', 'user.email=r11@example.test', '-c', 'core.autocrlf=false', '-c', 'init.defaultBranch=main'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) return [127, ''];
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
$r11rHasGit = $r11rGit($r11rSrc, ['--version'])[0] === 0;

// The repository: the example is shipped, the merchant's file is not tracked and is ignored.
try {
    assert_true(is_file($r11rSrc . '/stores.example.json') && is_array(json_decode((string)file_get_contents($r11rSrc . '/stores.example.json'), true)),
        'R11 stores.json: the repository ships stores.example.json');
    if ($r11rHasGit && file_exists($r11rSrc . '/.git')) {
        assert_equals([1, 0], [$r11rGit($r11rSrc, ['ls-files', '--error-unmatch', 'stores.json'])[0], $r11rGit($r11rSrc, ['ls-files', '--error-unmatch', 'stores.example.json'])[0]],
            'R11 stores.json: git tracks stores.example.json, not stores.json');
        assert_equals(0, $r11rGit($r11rSrc, ['check-ignore', '-q', '--no-index', 'stores.json'])[0], 'R11 stores.json: a stores.json in the application root is ignored by git');
    }
    $r11rRules = (string)file_get_contents($r11rSrc . '/.htaccess') . (string)file_get_contents($r11rSrc . '/deploy/nginx.conf');
    assert_equals(2, substr_count($r11rRules, 'stores(?:\.example)?\.json'), 'R11 stores.json: Apache and nginx deny both stores.json and stores.example.json');
} catch (Throwable $e) { $r11rFail('repository', $e); }

// Which file the shop reads, where the admin saves, and how setup apply migrates an older install.
try {
    $r11rApp = $r11rBase . '/app';
    $r11rData = $r11rBase . '/private';
    mkdir($r11rApp, 0777, true);
    $r11rExample = (string)file_get_contents($r11rSrc . '/stores.example.json');
    file_put_contents($r11rApp . '/stores.example.json', $r11rExample);
    $r11rConfig = ['private_runtime_dir' => $r11rData];
    assert_equals($r11rApp . '/stores.example.json', DataOverrides::storesPath($r11rApp, $r11rConfig), 'R11 stores.json: a fresh checkout runs on the example until setup apply');
    $r11rDoctor = array_column(Setup::doctor($r11rConfig, $r11rApp)['checks'] ?? [], 'status', 'name');
    assert_equals('warn', $r11rDoctor['stores.file'] ?? null, 'R11 stores.json: doctor warns while the shop runs on the shipped example');

    // An install from before 2.1 keeps an edited stores.json in the application root: apply moves it, once.
    $r11rLegacy = json_decode($r11rExample, true);
    $r11rLegacy['stores'][$r11rLegacy['default_store']]['legal']['name'] = 'R11 Legacy Trading s.r.o.';
    $r11rLegacyJson = json_encode($r11rLegacy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($r11rApp . '/stores.json', $r11rLegacyJson);
    assert_equals($r11rApp . '/stores.json', DataOverrides::storesPath($r11rApp, $r11rConfig), 'R11 stores.json: an older install keeps reading its root stores.json before apply');
    $r11rSeed = DataOverrides::seedStores($r11rApp, $r11rConfig);
    assert_equals(['moved from the application root', $r11rLegacyJson, false],
        [$r11rSeed['action'] ?? null, (string)@file_get_contents($r11rData . '/stores.json'), is_file($r11rApp . '/stores.json')],
        'R11 stores.json: setup apply moves the old root stores.json into private_runtime_dir unchanged');
    assert_equals($r11rData . '/stores.json', DataOverrides::storesPath($r11rApp, $r11rConfig), 'R11 stores.json: the shop then reads the private copy');
    assert_equals('unchanged', DataOverrides::seedStores($r11rApp, $r11rConfig)['action'] ?? null, 'R11 stores.json: setup apply is idempotent');
    $r11rDoctor = array_column(Setup::doctor($r11rConfig, $r11rApp)['checks'] ?? [], 'status', 'name');
    assert_equals('pass', $r11rDoctor['stores.file'] ?? null, 'R11 stores.json: doctor passes once the shop has its own stores.json');

    // A fresh install gets a copy of the example; the admin saves into the private copy, never into the example.
    $r11rFresh = $r11rBase . '/fresh-private';
    $r11rSeed = DataOverrides::seedStores($r11rApp, ['private_runtime_dir' => $r11rFresh]);
    assert_equals(['created from stores.example.json', str_replace('"sample": true', '"public": false', $r11rExample)], [$r11rSeed['action'] ?? null, (string)@file_get_contents($r11rFresh . '/stores.json')],
        'R11 stores.json: a fresh install gets its own copy of stores.example.json (sample stores not public)');
    DataOverrides::setDirForTesting($r11rFresh);
    try {
        assert_equals(str_replace('\\', '/', $r11rFresh) . '/stores.json', DataOverrides::storesWritePath(), 'R11 stores.json: the admin saves into private_runtime_dir/stores.json');
    } finally {
        DataOverrides::setDirForTesting(null);
    }
    assert_equals($r11rExample, (string)file_get_contents($r11rApp . '/stores.example.json'), 'R11 stores.json: nothing writes the tracked example');
} catch (Throwable $e) { $r11rFail('stores file', $e); }

// Update packages: stores.json is the merchant's, so no package may carry, write or delete it.
try {
    assert_true(Updater::isProtectedPath('stores.json') && !Updater::isProtectedPath('stores.example.json'), 'R11 stores.json: the updater refuses a package entry for stores.json');
    $r11rExcluded = new ReflectionMethod(UpdatePackageBuilder::class, 'excluded');
    $r11rExcluded->setAccessible(true);
    assert_equals([true, false], [$r11rExcluded->invoke(null, 'stores.json'), $r11rExcluded->invoke(null, 'stores.example.json')],
        'R11 stores.json: the package builder leaves out a stores.json and ships the example');
} catch (Throwable $e) { $r11rFail('packages', $e); }

// The documented upgrade of a git checkout whose tracked stores.json the merchant edited: no pull conflict, edits kept,
// and later admin saves leave the checkout clean.
if ($r11rHasGit) {
    try {
        $r11rUp = $r11rBase . '/upstream';
        $r11rShop = $r11rBase . '/shop';
        $r11rShopData = $r11rBase . '/shop-private';
        mkdir($r11rUp, 0777, true);
        $r11rGit($r11rUp, ['init', '-q']);
        file_put_contents($r11rUp . '/stores.json', $r11rExample);
        file_put_contents($r11rUp . '/.gitignore', "config.php\n");
        $r11rGit($r11rUp, ['add', '-A']);
        $r11rGit($r11rUp, ['commit', '-q', '-m', 'v2.0']);
        [$r11rCloneCode] = $r11rGit($r11rBase, ['clone', '-q', $r11rUp, $r11rShop]);
        // 2.1: the rename and the repository's .gitignore.
        $r11rGit($r11rUp, ['mv', 'stores.json', 'stores.example.json']);
        copy($r11rSrc . '/.gitignore', $r11rUp . '/.gitignore');
        $r11rGit($r11rUp, ['add', '-A']);
        $r11rGit($r11rUp, ['commit', '-q', '-m', 'v2.1']);
        // The merchant's admin saved into the tracked file of the old version.
        file_put_contents($r11rShop . '/stores.json', $r11rLegacyJson);
        [$r11rBlocked] = $r11rGit($r11rShop, ['pull', '-q', '--ff-only']);
        // CHANGELOG steps: copy into private_runtime_dir, drop the local edits, pull, apply.
        mkdir($r11rShopData, 0777, true);
        copy($r11rShop . '/stores.json', $r11rShopData . '/stores.json');
        $r11rGit($r11rShop, ['checkout', '--', 'stores.json']);
        [$r11rPull, $r11rPullOut] = $r11rGit($r11rShop, ['pull', '-q', '--ff-only']);
        $r11rShopConfig = ['private_runtime_dir' => $r11rShopData];
        DataOverrides::seedStores($r11rShop, $r11rShopConfig);
        assert_equals([0, true, 0], [$r11rCloneCode, $r11rBlocked !== 0, $r11rPull], "R11 stores.json: the edited tracked file blocks a plain pull, the documented steps pull cleanly ({$r11rPullOut})");
        assert_equals($r11rLegacyJson, (string)file_get_contents(DataOverrides::storesPath($r11rShop, $r11rShopConfig)), 'R11 stores.json: after the upgrade the shop reads the merchant\'s edited stores');
        // Without private_runtime_dir the admin writes an untracked root stores.json: git status stays clean.
        file_put_contents($r11rShop . '/stores.json', $r11rLegacyJson);
        assert_equals('', trim($r11rGit($r11rShop, ['status', '--porcelain'])[1]), 'R11 stores.json: an admin-saved stores.json leaves the checkout clean for the next pull');
    } catch (Throwable $e) { $r11rFail('git upgrade', $e); }
}

// CI: Dependabot and fork pull requests get no repository secrets; the Stripe test checkout skips them with a notice.
try {
    $r11rWorkflow = (string)file_get_contents($r11rSrc . '/.github/workflows/payment-gateway.yml');
    assert_true(str_contains($r11rWorkflow, 'dependabot[bot]') && str_contains($r11rWorkflow, 'head.repo.fork') && str_contains($r11rWorkflow, '::notice::'),
        'R11 CI: the Stripe workflow recognises pull requests without secrets (Dependabot, forks) and says why it skips');
    assert_true(str_contains($r11rWorkflow, "if: needs.stripe-test-key.outputs.run == 'true'") && substr_count($r11rWorkflow, 'exit 1') >= 1 && str_contains($r11rWorkflow, '::error::Repository secret STRIPE_TEST_SECRET_KEY'),
        'R11 CI: the checkout job runs only with the key, and a missing key still fails every other run');
} catch (Throwable $e) { $r11rFail('CI', $e); }

$r11rRemove($r11rBase);
assert_true(!is_dir($r11rBase), 'R11 release: the scenario leaves no fixture directory behind');
