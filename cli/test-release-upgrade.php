<?php
require_once __DIR__ . '/cli-only.php';

/**
 * R62 (review round 27, high 1): the signed update from a released tree, as a shop runs it.
 *
 * For every --from tag: export that release (git archive, LF), install it (SQLite, or MySQL with --driver=mysql), seed
 * products plus that release's own E2E order/invoice/RMA fixture, build a signed package from it to --to with this
 * release's builder, and run THE INSTALLED RELEASE's `php cli/update.php apply`. Its classes loaded before the files are
 * swapped stay old while this release's migrations run; v2.1.x rolled back on every shop when a migration reached
 * DataOverrides::readStores(). After the update this release's code must find no pending migration, no doctor failure
 * but the example seller data, the seeded orders still there, and a storefront that answers 200.
 *
 *   php cli/test-release-upgrade.php [--from=v2.1.0,v2.1.1 (default: every tag from v2.1.0)] [--to=HEAD] [--driver=sqlite|mysql]
 *       [--record-preloaded=cli/tests/fixtures/updater-preloaded-api.json] [--keep]
 *
 * MySQL: TEST_MYSQL_UPDATE_HOST/_PORT/_USER/_PASS (the user creates and drops barebones_rel_*), TEST_MYSQL_DUMP_BINARY and
 * TEST_MYSQL_CLIENT_BINARY (a client that matches the server: MariaDB's dump of a MySQL 8 schema does not restore).
 * --record-preloaded writes, per release, which application classes its updater had loaded before the swap and their
 * API: cli/tests/UpdaterCompat.php checks every migration against it in the ordinary suite. Without the option the run
 * fails when that file does not cover a tested release's loaded classes. Needs git, tar and the tags.
 */
require_once __DIR__ . '/../src/autoload.php';

/** Every released tree from the first with this updater on (2.1.0); a new tag is tested with no change here. */
const RELEASE_UPGRADE_FIRST = '2.1.0';

$options = getopt('', ['from:', 'to:', 'driver:', 'record-preloaded:', 'keep', 'help']);
if (isset($options['help'])) {
    echo "Usage: php cli/test-release-upgrade.php [--from=TAG,TAG] [--to=REF] [--driver=sqlite|mysql] [--record-preloaded=FILE] [--keep]\n";
    exit(0);
}
if (PHP_OS_FAMILY === 'Windows') {
    fwrite(STDERR, "Run the release upgrade gate on Linux (WSL): it installs and updates real release trees with tar and php -S.\n");
    exit(2);
}
$repo = dirname(__DIR__);
$driver = (string)($options['driver'] ?? 'sqlite');
if (!in_array($driver, ['sqlite', 'mysql'], true)) {
    fwrite(STDERR, "--driver must be sqlite or mysql\n");
    exit(2);
}
$tags = isset($options['from'])
    ? array_values(array_filter(array_map('trim', explode(',', (string)$options['from']))))
    : releaseUpgradeTags($repo);
$to = (string)($options['to'] ?? 'HEAD');
$record = isset($options['record-preloaded']) ? (string)$options['record-preloaded'] : null;
$fixturePath = $record ?? __DIR__ . '/tests/fixtures/updater-preloaded-api.json';
$fixtureReleases = (json_decode((string)@file_get_contents($fixturePath), true) ?: [])['releases'] ?? [];
$keep = isset($options['keep']);
$failures = 0;
$recorded = [];

foreach ($tags as $tag) {
    $work = sys_get_temp_dir() . '/barebones-e2e-release-upgrade-' . preg_replace('/[^A-Za-z0-9.]+/', '-', $tag) . '-' . getmypid();
    $label = "{$tag} → {$to} ({$driver})";
    $mysqlDatabases = [];
    try {
        releaseUpgradeRemove($work);
        foreach (['old', 'new', 'shop/htdocs'] as $dir) mkdir($work . '/' . $dir, 0700, true);
        releaseUpgradeExport($repo, $tag, $work . '/old');
        releaseUpgradeExport($repo, $to, $work . '/new');
        $shop = $work . '/shop/htdocs';
        releaseUpgradeRun(['cp', '-a', $work . '/old/.', $shop . '/'], $repo);
        releaseUpgradeExportBbf($repo, $tag, $shop . '/vendor/bbf');
        $baseVersion = trim((string)@file_get_contents($work . '/old/VERSION')) ?: ltrim($tag, 'v');

        $env = ['BBE_ADMIN_PASSWORD' => 'Release-upgrade-Passw0rd-1'];
        $install = ['php', 'cli/setup.php', 'install', '--app-url=http://127.0.0.1'];
        $configExtra = [];
        if ($driver === 'mysql') {
            $host = (string)(getenv('TEST_MYSQL_UPDATE_HOST') ?: '127.0.0.1');
            $port = (string)(getenv('TEST_MYSQL_UPDATE_PORT') ?: '3306');
            $user = (string)(getenv('TEST_MYSQL_UPDATE_USER') ?: 'root');
            $pass = (string)(getenv('TEST_MYSQL_UPDATE_PASS') ?: '');
            $name = 'barebones_rel_' . getmypid() . '_' . preg_replace('/[^0-9]+/', '', $tag) . '_e2e_test';
            $probe = 'barebones_rel_' . getmypid() . '_' . preg_replace('/[^0-9]+/', '', $tag) . '_update_probe';
            $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $mysqlDatabases = [$server, [$name, $probe]];
            $env['BBE_DB_PASSWORD'] = $pass;
            array_push($install, '--db-driver=mysql', "--db-host={$host}", "--db-port={$port}", "--db-name={$name}", "--db-user={$user}");
            $configExtra = ['update_mysql_probe_database' => $probe,
                'update_mysql_dump_binary' => (string)(getenv('TEST_MYSQL_DUMP_BINARY') ?: 'mysqldump'),
                'update_mysql_client_binary' => (string)(getenv('TEST_MYSQL_CLIENT_BINARY') ?: 'mysql')];
        } else {
            $install[] = '--db-driver=sqlite';
        }
        releaseUpgradeRun($install, $shop, $env);
        if ($configExtra !== []) releaseUpgradeConfig($shop . '/config.php', $configExtra);

        releaseUpgradeRun(['php', 'cli/seed.php', '--count=30'], $shop);
        releaseUpgradeRun(['php', 'tests/e2e/seed.php'], $shop, ['E2E_STATE_PATH' => $work . '/e2e-state.json']);
        $ordersBefore = releaseUpgradeCount($shop, 'orders');
        $invoicesBefore = releaseUpgradeCount($shop, 'invoices');
        if ($ordersBefore < 1 || $invoicesBefore < 1) throw new RuntimeException("the {$tag} E2E fixture seeded no order or invoice");

        releaseUpgradeRun(['php', $work . '/new/cli/build-update.php', 'keygen', "--public={$work}/update-public.key", "--secret={$work}/update-secret.key"], $shop);
        releaseUpgradeRun(['php', $work . '/new/cli/build-update.php', "--from={$work}/old", "--to={$work}/new", "--base-version={$baseVersion}",
            '--version=0.0.0-upgrade', "--output={$work}/package", "--secret-key={$work}/update-secret.key"], $shop);
        $updateEnv = ['BAREBONES_UPDATE_PUBLIC_KEY' => trim((string)file_get_contents($work . '/update-public.key'))];
        releaseUpgradeRun(['php', 'cli/update.php', 'baseline', "--version={$baseVersion}"], $shop, $updateEnv);

        $apply = ['php', 'cli/update.php', 'apply', "--package={$work}/package"];
        // Every run logs what the updater loaded before the swap (when there is a migration to mark the swap with: without
        // one no migration runs under it either), so a release the fixture does not cover yet fails here, not on shops.
        $newMigrations = array_values(array_diff(releaseUpgradeMigrations($work . '/new'), releaseUpgradeMigrations($work . '/old')));
        if ($record !== null && $newMigrations === []) throw new RuntimeException('--record-preloaded needs a target with a migration the release does not have');
        if ($newMigrations !== []) {
            $updateEnv += ['BBE_UPGRADE_PROBE_LOG' => $work . '/preloaded.log', 'BBE_UPGRADE_PROBE_MARKER' => $shop . '/migrations/' . $newMigrations[0] . '.php'];
            array_splice($apply, 1, 0, ['-d', 'auto_prepend_file=' . __DIR__ . '/tests/release-upgrade-probe.php']);
        }
        [$code, $output] = releaseUpgradeRun($apply, $shop, $updateEnv, false);
        if ($code !== 0 || !str_contains($output, 'Updated to 0.0.0-upgrade')) {
            throw new RuntimeException("the {$tag} updater did not install the package (exit {$code}):\n" . releaseUpgradeTail($output, 25));
        }

        // This release's code on the updated shop.
        [, $migrate] = releaseUpgradeRun(['php', 'cli/migrate.php'], $shop);
        if (!str_contains($migrate, 'Nothing to apply')) throw new RuntimeException("migrations left after the update:\n" . releaseUpgradeTail($migrate, 10));
        [, $doctorJson] = releaseUpgradeRun(['php', 'cli/setup.php', 'doctor', '--json'], $shop, [], false);
        $doctor = json_decode($doctorJson, true);
        if (!is_array($doctor) || !isset($doctor['checks'])) throw new RuntimeException("setup doctor after the update gave no report:\n" . releaseUpgradeTail($doctorJson, 10));
        // Expected on this fixture: the example seller data, and the mock gateway v2.1.0's E2E seed switches on.
        $failed = array_values(array_filter($doctor['checks'], static fn(array $c): bool => ($c['status'] ?? '') === 'fail'
            && !str_starts_with((string)$c['name'], 'stores.legal.') && (string)$c['name'] !== 'payment.provider.mock'));
        if ($failed !== []) throw new RuntimeException('setup doctor fails after the update: ' . implode('; ', array_map(static fn(array $c): string => $c['name'] . ': ' . $c['message'], $failed)));
        if (releaseUpgradeCount($shop, 'orders') !== $ordersBefore || releaseUpgradeCount($shop, 'invoices') !== $invoicesBefore) {
            throw new RuntimeException('orders or invoices changed in the update');
        }
        $status = releaseUpgradeStorefront($shop);
        if ($status !== 200) throw new RuntimeException("the updated storefront answers {$status}");

        if ($newMigrations !== []) {
            $preloaded = releaseUpgradePreloaded($work, $shop);
            if ($record !== null) {
                $recorded[$baseVersion] = $preloaded;
            } else {
                $missing = array_diff(array_keys($preloaded['classes']), array_keys($fixtureReleases[$baseVersion]['classes'] ?? []));
                if (!isset($fixtureReleases[$baseVersion]) || $missing !== []) {
                    throw new RuntimeException("{$fixturePath} does not record that the {$baseVersion} updater ({$driver}) had "
                        . implode(', ', $missing ?: ['its classes']) . ' loaded, so the regression suite cannot check the migrations'
                        . " against them: php cli/test-release-upgrade.php --from={$tag} --driver={$driver} --record-preloaded={$fixturePath}");
                }
            }
        }
        echo "[PASS] {$label}: {$ordersBefore} order(s) and {$invoicesBefore} invoice(s) kept, no migration left, storefront 200\n";
    } catch (Throwable $e) {
        $failures++;
        echo "[FAIL] {$label}: " . $e->getMessage() . "\n";
    } finally {
        if ($mysqlDatabases !== []) {
            [$server, $names] = $mysqlDatabases;
            foreach ($names as $name) {
                try { $server->exec("DROP DATABASE IF EXISTS `{$name}`"); } catch (Throwable) {}
            }
        }
        if (!$keep) releaseUpgradeRemove($work);
        else echo "  kept {$work}\n";
    }
}

if ($record !== null && $failures === 0) {
    $fixture = is_file($record) ? (json_decode((string)file_get_contents($record), true) ?: []) : [];
    $fixture['_about'] = 'Application classes each release\'s updater has loaded before it swaps the files, with their API, and the'
        . ' migrations that release has. Written by php cli/test-release-upgrade.php --record-preloaded; read by cli/tests/UpdaterCompat.php.';
    // Per release the union over drivers: the MySQL path loads classes the SQLite one does not.
    foreach ($recorded as $version => $release) {
        $release['classes'] += $fixture['releases'][$version]['classes'] ?? [];
        ksort($release['classes']);
        $fixture['releases'][$version] = $release;
    }
    ksort($fixture['releases']);
    file_put_contents($record, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "Recorded the preloaded classes of " . implode(', ', array_keys($recorded)) . " in {$record}\n";
}
exit($failures === 0 ? 0 : 1);

/** @param list<string> $command  @param array<string, string> $env  @return array{0: int, 1: string} */
function releaseUpgradeRun(array $command, string $cwd, array $env = [], bool $mustSucceed = true): array
{
    // ['redirect', 1] (PHP 7.4+) puts stderr on stdout; the proc_open stub does not know that descriptor.
    /** @phpstan-ignore-next-line */
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $env + getenv());
    if (!is_resource($process)) throw new RuntimeException('cannot run ' . $command[0]);
    $output = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($process);
    if ($mustSucceed && $code !== 0) {
        throw new RuntimeException(implode(' ', array_slice($command, 0, 3)) . " exited {$code}:\n" . releaseUpgradeTail($output, 15));
    }
    return [$code, $output];
}

/** @return list<string> the vX.Y.Z tags from RELEASE_UPGRADE_FIRST on, oldest first */
function releaseUpgradeTags(string $repo): array
{
    [, $out] = releaseUpgradeRun(['git', 'tag', '--list', 'v[0-9]*.[0-9]*.[0-9]*'], $repo);
    $tags = array_values(array_filter(array_map('trim', explode("\n", $out)), static fn(string $t): bool =>
        preg_match('/^v\d+\.\d+\.\d+$/', $t) === 1 && version_compare(substr($t, 1), RELEASE_UPGRADE_FIRST, '>=')));
    usort($tags, static fn(string $a, string $b): int => version_compare(substr($a, 1), substr($b, 1)));
    if ($tags === []) throw new RuntimeException('no release tag v' . RELEASE_UPGRADE_FIRST . ' or later: fetch the tags (git fetch --tags, or actions/checkout with fetch-depth: 0)');
    return $tags;
}

function releaseUpgradeTail(string $output, int $lines): string
{
    return implode("\n", array_slice(explode("\n", rtrim($output)), -$lines));
}

/** An LF export of $ref, as the release owner builds packages from (CRLF text is refused by build-update). */
function releaseUpgradeExport(string $repo, string $ref, string $into): void
{
    releaseUpgradeRun(['sh', '-c', 'git -c core.autocrlf=false archive --format=tar "$0" | tar -x -C "$1"', $ref, $into], $repo);
}

/** vendor/bbf as a recursive clone of $ref has it (git archive leaves submodules out; the installer needs it). */
function releaseUpgradeExportBbf(string $repo, string $ref, string $into): void
{
    [, $tree] = releaseUpgradeRun(['git', 'ls-tree', $ref, 'vendor/bbf'], $repo);
    if (!preg_match('/\bcommit ([0-9a-f]{40})\b/', $tree, $m)) throw new RuntimeException("{$ref} has no vendor/bbf submodule");
    if (!is_dir($into)) mkdir($into, 0700, true);
    releaseUpgradeRun(['sh', '-c', 'git -C vendor/bbf archive --format=tar "$0" | tar -x -C "$1"', $m[1], $into], $repo);
}

/** @param array<string, string> $settings */
function releaseUpgradeConfig(string $file, array $settings): void
{
    $lines = '';
    foreach ($settings as $key => $value) $lines .= '    ' . var_export($key, true) . ' => ' . var_export($value, true) . ",\n";
    $config = (string)file_get_contents($file);
    $updated = preg_replace('/\];\s*$/', $lines . "];\n", $config, 1, $count);
    if ($count !== 1) throw new RuntimeException('config.php of the installed release does not end in "];"');
    file_put_contents($file, $updated);
}

/** @return list<string> */
function releaseUpgradeMigrations(string $tree): array
{
    $names = array_map(static fn(string $f): string => basename($f, '.php'), glob($tree . '/migrations/*.php') ?: []);
    sort($names);
    return $names;
}

function releaseUpgradeCount(string $shop, string $table): int
{
    [, $out] = releaseUpgradeRun(['php', '-r', 'require "src/autoload.php"; echo "count=", (int)Db::get()->query("SELECT COUNT(*) FROM ' . $table . '")->fetchColumn();'], $shop);
    return preg_match('/count=(\d+)/', $out, $m) ? (int)$m[1] : -1;
}

function releaseUpgradeStorefront(string $shop): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr((string)stream_socket_get_name($socket, false), strrpos((string)stream_socket_get_name($socket, false), ':') + 1);
    fclose($socket);
    $server = proc_open(['php', '-S', "127.0.0.1:{$port}", 'router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $shop);
    try {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            usleep(100000);
            $headers = @get_headers("http://127.0.0.1:{$port}/");
            if (is_array($headers) && preg_match('/^HTTP\/\S+ (\d{3})/', (string)$headers[0], $m)) return (int)$m[1];
        }
        return 0;
    } finally {
        proc_terminate($server);
        proc_close($server);
    }
}

/**
 * The classes the release's updater had loaded before the swap (the probe logged them, each with the file it found),
 * whose code there is the release's own, and their API as that release defines it.
 * @return array{migrations: list<string>, classes: array<string, array{methods: list<string>, constants: list<string>, props: list<string>}>}
 */
function releaseUpgradePreloaded(string $work, string $shop): array
{
    $classes = [];
    foreach (file($work . '/preloaded.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        [$class, $relative, $before] = array_pad(explode("\t", $line), 3, '');
        if ($before !== 'before' || !str_starts_with($relative, 'src/') || !is_file($work . '/old/' . $relative)) continue;
        $classes[$class] = true;
    }
    ksort($classes);
    $script = 'require $argv[1] . "/src/autoload.php"; $out = [];'
        . ' foreach (array_slice($argv, 2) as $c) { $r = new ReflectionClass($c);'
        . ' $m = array_values(array_map(fn($x) => $x->getName(), array_filter($r->getMethods(), fn($x) => !$x->isPrivate())));'
        . ' $p = array_values(array_map(fn($x) => $x->getName(), array_filter($r->getProperties(), fn($x) => !$x->isPrivate())));'
        . ' sort($m); sort($p); $k = array_keys($r->getConstants()); sort($k);'
        . ' $out[$c] = ["methods" => $m, "constants" => $k, "props" => $p]; } echo json_encode($out);';
    [, $json] = releaseUpgradeRun(['php', '-r', $script, $work . '/old', ...array_keys($classes)], $shop);
    $api = json_decode($json, true);
    if (!is_array($api)) throw new RuntimeException("cannot read the API of the preloaded classes:\n" . releaseUpgradeTail($json, 5));
    return ['migrations' => releaseUpgradeMigrations($work . '/old'), 'classes' => $api];
}

function releaseUpgradeRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) return;
    @chmod($path, 0700);
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') releaseUpgradeRemove($path . '/' . $entry);
    }
    @rmdir($path);
}
