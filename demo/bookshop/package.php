<?php
declare(strict_types=1);

/**
 * Builds the "upload and sell" bookshop package (#239): the shop at a git ref plus vendor/bbf, a ready SQLite database
 * with the demo catalog, the eBook files and an English single-store stores.json in demo/bookshop/dist/ (web-denied),
 * the covers in uploads/, and no config.php — so the first visit opens install.php.
 *
 * Usage: php demo/bookshop/package.php --out=DIR [--ref=HEAD] [--name=bareboneseshop-bookshop] [--shop-name="BareBones Books"] [--version=X.Y.Z]
 *
 * Built from a release tag (vX.Y.Z) the package records that release in .barebones-version.json, so a shop installed
 * from it is upgraded by the signed updater like any other installation. Entries carry Unix modes 0755/0644.
 *
 * Needs git, the vendor/bbf submodule checked out, and PHP with pdo_sqlite and zip. The files come from git archive
 * (LF, only tracked files), never from the working tree.
 */
require_once __DIR__ . '/../../cli/cli-only.php';
require_once __DIR__ . '/../../src/autoload.php';

$options = getopt('', ['out:', 'ref:', 'name:', 'shop-name:', 'version:']);
$repo = dirname(__DIR__, 2);
$out = rtrim((string)($options['out'] ?? ''), '/\\');
$ref = (string)($options['ref'] ?? 'HEAD');
$name = (string)($options['name'] ?? 'bareboneseshop-bookshop');
$shopName = (string)($options['shop-name'] ?? 'BareBones Books');
if ($out === '' || !is_dir($out) || preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1 || preg_match('/^[A-Za-z0-9._\/-]+$/', $ref) !== 1
    || (isset($options['version']) && preg_match('/^[0-9][A-Za-z0-9._+-]{0,63}$/', (string)$options['version']) !== 1)) {
    fwrite(STDERR, "Usage: php demo/bookshop/package.php --out=DIR [--ref=HEAD] [--name=bareboneseshop-bookshop] [--shop-name=\"BareBones Books\"] [--version=X.Y.Z]\n");
    exit(64);
}

$run = static function (array $command, string $cwd): string {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) throw new RuntimeException('Cannot start ' . implode(' ', $command));
    fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException(implode(' ', $command) . " failed:\n" . $stdout . $stderr);
    return $stdout;
};
$extract = static function (string $zipFile, string $target): void {
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true || !$zip->extractTo($target)) throw new RuntimeException("Cannot extract {$zipFile}");
    $zip->close();
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
    rmdir($path);
};

$work = sys_get_temp_dir() . '/bbe-bookshop-' . bin2hex(random_bytes(4));
$shop = $work . '/bookshop';
$private = $work . '/data';
$started = microtime(true);
try {
    mkdir($shop, 0755, true);
    mkdir($private, 0750, true);
    echo "[1/5] export {$ref} and vendor/bbf\n";
    $run(['git', '-c', 'core.autocrlf=false', 'archive', '--format=zip', '-o', $work . '/app.zip', $ref], $repo);
    $extract($work . '/app.zip', $shop);
    $bbfCommit = trim($run(['git', 'rev-parse', $ref . ':vendor/bbf'], $repo));
    $run(['git', '-c', 'core.autocrlf=false', 'archive', '--format=zip', '-o', $work . '/bbf.zip', $bbfCommit], $repo . '/vendor/bbf');
    $extract($work . '/bbf.zip', $shop . '/vendor/bbf');

    echo "[2/5] temporary config and the English single-store stores.json\n";
    Setup::createConfig($shop, '', '', ['driver' => 'sqlite'], $private);
    $stores = json_decode((string)file_get_contents($shop . '/' . DataOverrides::STORES_EXAMPLE), true, 512, JSON_THROW_ON_ERROR);
    $default = (string)$stores['default_store'];
    $store = array_merge($stores['stores'][$default], ['name' => $shopName, 'theme' => 'bookshop', 'default_locale' => 'en', 'languages' => ['en'], 'currency' => 'eur']);
    $stores['stores'] = [$default => $store];
    $storesJson = json_encode($stores, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    mkdir($private . '/bareboneseshop-private', 0750, true);
    file_put_contents($private . '/bareboneseshop-private/stores.json', $storesJson);

    echo "[3/5] schema, demo catalog, covers and eBooks\n";
    foreach ([['cli/setup.php', 'apply', '--json'], ['cli/migrate.php', '--skip-backup'], ['cli/schema-build.php'], ['demo/bookshop/build.php', '--shop-name=' . $shopName]] as $script) {
        $run(array_merge([PHP_BINARY], $script), $shop);
    }

    echo "[4/5] starter data in demo/bookshop/dist\n";
    $config = require $shop . '/config.php';
    $db = new PDO('sqlite:' . $config['db_sqlite_path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $db->exec('PRAGMA journal_mode=DELETE');
    $db->exec('VACUUM');
    $db = null;
    $dist = $shop . '/demo/bookshop/dist';
    mkdir($dist . '/downloads', 0755, true);
    copy($config['db_sqlite_path'], $dist . '/shop.sqlite');
    file_put_contents($dist . '/stores.json', $storesJson);
    $downloads = rtrim((string)$config['digital_assets_base_dir'], '/');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($downloads, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($files as $file) {
        $target = $dist . '/downloads/' . str_replace('\\', '/', substr($file->getPathname(), strlen($downloads) + 1));
        $file->isDir() ? @mkdir($target, 0755, true) : copy($file->getPathname(), $target);
    }
    file_put_contents($dist . '/starter.json', json_encode(['name' => $shopName, 'database' => 'shop.sqlite', 'downloads' => 'downloads', 'stores' => 'stores.json',
        'ref' => trim($run(['git', 'rev-parse', $ref], $repo))], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    unlink($shop . '/config.php');
    // R15: the installed release, as `php cli/update.php baseline` records it, so the signed updater can upgrade the
    // shop to the next release (it refuses an installation of unknown version). Built from a release tag only.
    $tag = trim((string)@shell_exec('git -C ' . escapeshellarg($repo) . ' describe --tags --exact-match ' . escapeshellarg($ref) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null')));
    $version = (string)($options['version'] ?? (preg_match('/^v(\d+\.\d+\.\d+[A-Za-z0-9.+-]*)$/', $tag, $m) ? $m[1] : ''));
    if ($version !== '') {
        file_put_contents($shop . '/.barebones-version.json', json_encode(['version' => $version, 'baselined_at' => gmdate('c'), 'source' => 'bookshop-package'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        echo "      release {$version} recorded for the updater\n";
    } else {
        echo "      not a release tag: no version recorded (the updater needs `php cli/update.php baseline --version=...`)\n";
    }
    foreach (['cache', 'logs', 'jobs'] as $runtime) {
        foreach (glob($shop . '/' . $runtime . '/*') ?: [] as $leftover) if (basename($leftover) !== '.htaccess' && basename($leftover) !== '.gitkeep') $remove($leftover);
    }

    echo "[5/5] zip\n";
    $zipFile = $out . '/' . $name . '.zip';
    if (is_file($zipFile)) unlink($zipFile);
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException("Cannot create {$zipFile}");
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($shop, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    // The top folder gets its own entry too, or unzip creates it under the host's umask (0777 with umask 000).
    $zip->addEmptyDir($name);
    $zip->setExternalAttributesName($name . '/', ZipArchive::OPSYS_UNIX, 040755 << 16);
    foreach ($entries as $entry) {
        $local = $name . '/' . str_replace('\\', '/', substr($entry->getPathname(), strlen($shop) + 1));
        $entry->isDir() ? $zip->addEmptyDir($local) : $zip->addFile($entry->getPathname(), $local);
        // R15: an entry without Unix modes unpacks as 0777 on many hosts (anyone on a shared host could rewrite the
        // PHP files). Folders 0755, files 0644; the installer makes the private data folder writable for PHP itself.
        $zip->setExternalAttributesName($entry->isDir() ? $local . '/' : $local, ZipArchive::OPSYS_UNIX, ($entry->isDir() ? 040755 : 0100644) << 16);
    }
    if (!$zip->close()) throw new RuntimeException("Cannot write {$zipFile}");
    printf("Done: %s (%.1f MB, %d s)\n", $zipFile, filesize($zipFile) / 1048576, (int)round(microtime(true) - $started));
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $remove($work);
}
