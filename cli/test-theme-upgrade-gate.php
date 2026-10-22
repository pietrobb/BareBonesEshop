<?php
require_once __DIR__ . '/cli-only.php';
require_once __DIR__ . '/../src/autoload.php';

// The updater refuses a package whose NEW core breaks the INSTALLED customer theme or store override,
// before any file, snapshot, migration or version state changes; a compatible candidate still applies.
// Everything runs in disposable copies under the system temp directory.
$themeUpgradeGateExit = (static function(): int {
    $repo = dirname(__DIR__);
    $temp = sys_get_temp_dir() . '/bbe-theme-upgrade-' . bin2hex(random_bytes(8));
    $failures = 0;
    $assert = static function(bool $ok, string $message) use (&$failures): void {
        if (!$ok) $failures++;
        if (function_exists('assert_true')) assert_true($ok, 'Theme upgrade gate: ' . $message);
        else echo ($ok ? '[PASS] ' : '[FAIL] ') . $message . "\n";
    };
    $write = static function(string $path, string $content): void {
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        file_put_contents($path, $content);
    };
    $copyTree = static function(string $from, string $to, ?callable $accept = null) use ($write): void {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($from) + 1));
            if ($item->isFile() && ($accept === null || $accept($relative))) $write($to . '/' . $relative, (string)file_get_contents($item->getPathname()));
        }
    };
    $treeHash = static function(string $root): string {
        $rows = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $item) {
            if ($item->isFile()) $rows[] = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1)) . ':' . hash_file('sha256', $item->getPathname());
        }
        sort($rows);
        return hash('sha256', implode("\n", $rows));
    };
    $removeTree = static function(string $path): void {
        if (!is_dir($path)) return;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    };

    try {
        // Release tree "from" = this core. Customer data, secrets and databases are never part of a release.
        $coreScope = static fn(string $path): bool => (bool)preg_match('~^(src|adapters|cli|migrations|lang|forms|templates|themes|blocks)/~', $path)
            || (bool)preg_match('~^[^/]+\.(php|json)$~', $path) && $path !== 'config.php';
        $from = $temp . '/release-from';
        $copyTree($repo, $from, static fn(string $path): bool => $coreScope($path) && !str_starts_with($path, 'cli/tests/scenarios/'));

        $migration = "<?php\nreturn new class {\n    public function up(PDO \$db, bool \$isSqlite): void { \$db->exec('CREATE TABLE theme_gate_release (id INTEGER)'); }\n};\n";
        $release = static function(string $name, callable $edit) use ($temp, $from, $copyTree): string {
            $to = $temp . '/release-' . $name;
            $copyTree($from, $to);
            $edit($to);
            $package = $temp . '/package-' . $name;
            UpdatePackageBuilder::build($from, $to, $package, '9.9.9-' . $name);
            return $package;
        };
        $compatible = $release('compatible', static function(string $to) use ($write, $migration): void {
            $write($to . '/src/ThemeGateRelease.php', "<?php\n// release marker\n");
            $write($to . '/migrations/29990101000000_theme_gate_release.php', $migration);
        });
        $codeOnly = $release('code-only', static function(string $to) use ($write): void {
            $write($to . '/src/ThemeGateRelease.php', "<?php\n// code-only release marker\n");
        });
        // A core change that renames a checkout variable: the bundled default theme moves along, a customer theme cannot.
        $breaking = $release('breaking', static function(string $to) use ($write): void {
            foreach (['src/Entrypoints/Storefront/CheckoutPages.php', 'themes/default/blocks/storefront/checkout.html'] as $file) {
                $write($to . '/' . $file, str_replace('country_options', 'country_choices', (string)file_get_contents($to . '/' . $file)));
            }
        });
        $withoutProbe = $release('without-probe', static function(string $to) use ($write): void {
            unlink($to . '/' . ThemeUpgradeGate::PROBE);
            $write($to . '/src/ThemeGateRelease.php', "<?php\n// probe dropped\n");
        });

        $legacy = (string)file_get_contents(__DIR__ . '/tests/fixtures/theme-contract/legacy-aeon.html');
        $repaired = str_replace(['form_values.', '{{#unless digital_only}}', '{{/unless}}<label for="checkout-country">',
            '{{#each countries}}<option value="{{code}}"{{#if selected}} selected{{/if}}>{{label}}</option>'],
            ['values.', '{{#if address_required}}', '{{/if}}<label for="checkout-country">',
            '{{#each country_options}}<option value="{{code}}"{{#if selected}} selected{{/if}}>{{name}}</option>'], $legacy);
        $installCount = 0;
        // Installed shop = "from" core + the customer's own theme, store override, stores.json, config and database.
        $install = static function(string $themeCheckout, ?string $storeOverride = null) use ($temp, $from, $copyTree, $write, &$installCount): array {
            $root = $temp . '/installed-' . (++$installCount);
            $copyTree($from, $root);
            $write($root . '/themes/aeon/theme.json', json_encode(['name' => 'Aeon', 'version' => '1.0.0']));
            $write($root . '/themes/aeon/blocks/storefront/checkout.html', $themeCheckout);
            if ($storeOverride !== null) $write($root . '/blocks/stores/default/storefront/checkout.html', $storeOverride);
            $stores = json_decode((string)file_get_contents($root . '/stores.json'), true);
            $stores['stores'][$stores['default_store'] ?? 'default']['theme'] = 'aeon';
            $write($root . '/stores.json', json_encode($stores, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $state = $temp . '/state-' . $installCount;
            $write($root . '/config.php', "<?php\nreturn ['update_storage_dir' => " . var_export($state, true) . "];\n");
            $database = $temp . '/shop-' . $installCount . '.sqlite';
            $pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE TABLE orders (id INTEGER PRIMARY KEY, number TEXT); INSERT INTO orders (number) VALUES ('2026-0001')");
            return [$root, $state, $database, $pdo];
        };
        $updater = static function(string $root, string $state, PDO $pdo, int &$migrations): Updater {
            return new Updater($root, $state, $pdo, static function() use (&$migrations): void { $migrations++; }, null, new ThemeUpgradeGate($state));
        };
        $snapshotCount = static fn(string $state): int => count(glob($state . '/snapshots/*', GLOB_ONLYDIR) ?: []);

        // A: the morning's production Aeon checkout against a compatible core release → refused, nothing touched.
        [$root, $state, $database, $pdo] = $install($legacy);
        $before = [$treeHash($root), hash_file('sha256', $database)];
        $migrations = 0;
        $gate = $updater($root, $state, $pdo, $migrations);
        $report = $gate->check(new UpdatePackage($compatible, null, true));
        $blockers = implode("\n", $report['blockers']);
        $assert(!$report['can_apply'] && ($report['theme_contract']['status'] ?? '') === 'NO-GO', 'legacy Aeon blocks the package preflight');
        foreach (['aeon / store default / storefront/checkout / physical [countries]', '[values.name]', '[address.street]', 'Theme checkout contract NO-GO'] as $needle) {
            $assert(str_contains($blockers, $needle), 'diagnostic names theme/store/block/scenario/problem: ' . $needle);
        }
        $refused = null;
        try { $gate->apply(new UpdatePackage($compatible, null, true)); } catch (RuntimeException $e) { $refused = $e->getMessage(); }
        $assert($refused !== null && str_starts_with($refused, 'Update blocked: Theme checkout contract: aeon') && str_contains($refused, 'aeon / store default'),
            'apply refuses before mutating');
        $assert([$treeHash($root), hash_file('sha256', $database)] === $before, 'installed files and database are byte-identical after the refusal');
        $assert($migrations === 0 && $snapshotCount($state) === 0 && !is_file($state . '/installed.json'), 'no migration, snapshot or version state after the refusal');
        $assert(count(glob($state . '/theme-candidate-*') ?: []) === 0, 'private theme candidate is removed after the verdict');

        // B: a good theme cannot hide a broken store block override.
        [$root, $state, , $pdo] = $install($repaired, $legacy);
        $report = $updater($root, $state, $pdo, $migrations)->check(new UpdatePackage($compatible, null, true));
        $assert(!$report['can_apply'] && str_contains(implode("\n", $report['blockers']), 'aeon / store default / storefront/checkout'), 'broken store override blocks the package');

        // C/D: the repaired Aeon passes with today's core, but a core that renames a checkout variable is refused.
        [$root, $state, $database, $pdo] = $install($repaired);
        $before = [$treeHash($root), hash_file('sha256', $database)];
        $migrations = 0;
        $gate = $updater($root, $state, $pdo, $migrations);
        $report = $gate->check(new UpdatePackage($breaking, null, true));
        $assert(!$report['can_apply'] && str_contains(implode("\n", $report['blockers']), 'aeon / store default / storefront/checkout / physical [countries]'),
            'NEW core + installed theme is what gets qualified (renamed country_options is caught)');
        $assert([$treeHash($root), hash_file('sha256', $database)] === $before, 'breaking package leaves files and database unchanged');
        $report = $gate->check(new UpdatePackage($withoutProbe, null, true));
        $assert(!$report['can_apply'] && str_contains(implode("\n", $report['blockers']), 'would not contain ' . ThemeUpgradeGate::PROBE), 'a package that drops the probe fails closed');
        $report = $gate->check(new UpdatePackage($compatible, null, true));
        $assert($report['can_apply'] && ($report['theme_contract']['status'] ?? '') === 'GO' && ($report['theme_contract']['checks'] ?? 0) >= 100
            && in_array('aeon / store default', $report['theme_contract']['targets'] ?? [], true), 'compatible candidate passes for the installed theme and store');
        $applied = $gate->apply(new UpdatePackage($compatible, null, true));
        $assert($applied['version'] === '9.9.9-compatible' && $migrations === 1 && is_file($root . '/src/ThemeGateRelease.php')
            && str_contains((string)file_get_contents($root . '/themes/aeon/blocks/storefront/checkout.html'), 'country_options'), 'compatible candidate applies and keeps the customer theme');

        // E: library use without a gate is flagged, never silently qualified.
        [$root, $state, , $pdo] = $install($legacy);
        $report = (new Updater($root, $state, $pdo))->check(new UpdatePackage($codeOnly, null, true));
        $assert(in_array('Theme checkout contract was not verified: this updater instance has no theme gate', $report['warnings'], true), 'updater without a gate reports the gap');

        // F: the supported CLI (php cli/update.php check/apply) runs the gate for real.
        [$root, , $database] = $install($legacy);
        $before = [$treeHash($root), hash_file('sha256', $database)];
        $cli = static function(string $root, array $arguments): array {
            $pipes = [];
            $process = proc_open(array_merge([PHP_BINARY, $root . '/cli/update.php'], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($process), $output];
        };
        [$exit, $output] = $cli($root, ['check', '--package=' . $codeOnly, '--allow-unsigned']);
        $assert($exit === 1 && str_contains($output, '[BLOCK] Theme checkout contract: aeon / store default / storefront/checkout / physical [countries]')
            && str_contains($output, 'no files were changed'), 'cli check exits 1 with the theme diagnostics');
        [$exit, $output] = $cli($root, ['apply', '--package=' . $codeOnly, '--allow-unsigned']);
        $assert($exit === 1 && !str_contains($output, 'Updated to'), 'cli apply refuses the incompatible upgrade');
        $assert([$treeHash($root), hash_file('sha256', $database)] === $before, 'cli refusal leaves files and database unchanged');
        [$root] = $install($repaired);
        [$exit, $output] = $cli($root, ['apply', '--package=' . $codeOnly, '--allow-unsigned']);
        $assert($exit === 0 && str_contains($output, 'Updated to 9.9.9-code-only') && is_file($root . '/src/ThemeGateRelease.php'), 'cli apply installs a compatible candidate');

        // G: first hop from an updater that predates the gate — run the new release's gate standalone.
        [$root] = $install($legacy);
        $pipes = [];
        $process = proc_open([PHP_BINARY, $repo . '/cli/theme-check.php', '--installed=' . $root, '--package=' . $codeOnly, '--allow-unsigned', '--json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $standalone = json_decode((string)stream_get_contents($pipes[1]), true);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $assert(proc_close($process) === 1 && ($standalone['status'] ?? '') === 'NO-GO', 'standalone package gate (theme-check.php --installed --package) reports NO-GO');

        // H (review round 8): the runbook's `update.php doctor --package` passes the preflight of a package with
        // migrations — it used to build the updater without a migration runner, so every such package was NOT_READY.
        [$root, $state, $database] = $install($repaired);
        $write($root . '/config.php', "<?php\nreturn ['update_storage_dir' => " . var_export($state, true)
            . ", 'db_driver' => 'sqlite', 'db_sqlite_path' => " . var_export(str_replace('\\', '/', $database), true) . "];\n");
        [, $output] = $cli($root, ['doctor', '--package=' . $compatible, '--allow-unsigned', '--json']);
        $doctor = json_decode($output, true);
        $doctorChecks = array_column((array)($doctor['checks'] ?? []), null, 'name');
        $blockers = (array)($doctor['package_preflight']['blockers'] ?? ['no JSON: ' . $output]);
        $assert(($doctorChecks['package']['level'] ?? null) === 'ok' && $blockers === [] && ($doctorChecks['database_backup']['level'] ?? null) === 'ok',
            'cli doctor passes the preflight of a package with migrations (blockers: ' . implode('; ', $blockers) . ')');
        $assert(!is_file($root . '/src/ThemeGateRelease.php') && !is_file($state . '/installed.json'), 'cli doctor changes nothing');
    } finally {
        $gate = $pdo = null; // release SQLite handles so Windows can delete the disposable databases
        gc_collect_cycles();
        $removeTree($temp);
    }
    return $failures === 0 ? 0 : 1;
})();
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) exit($themeUpgradeGateExit);
