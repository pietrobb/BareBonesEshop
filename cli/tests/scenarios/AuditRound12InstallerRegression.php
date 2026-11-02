<?php

// Review round 12 (M4, M5): the web installer of the upload-and-sell package. M4: whoever opened install.php first
// became the owner — now the key from install-key.php (readable over FTP, never over HTTP) is required. M5: all
// protection of src/, cli/ and demo/*/dist (paid eBooks, the starter database) is .htaccess, which does nothing without
// mod_rewrite/AllowOverride — the installer now requests those paths from its own server and refuses when they are
// served, and deletes demo/<name>/dist once the starter is copied into the private folder.
echo "\n--- Audit round 12: web installer key, protection self-check, starter removal ---\n";

$r12iBase = test_fixture_path('r12-installer');
$r12iServers = [];
$r12iTree = static function (string $path) use (&$r12iTree): void {
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
};
try {
    // M4: the installation key.
    $r12iRoot = $r12iBase . '/key';
    mkdir($r12iRoot, 0777, true);
    $r12iKey = WebInstaller::installKey($r12iRoot);
    assert_true(is_string($r12iKey) && preg_match('/^[A-Z2-9]{5}(?:-[A-Z2-9]{5}){3}$/', $r12iKey) === 1 && is_file($r12iRoot . '/' . WebInstaller::KEY_FILE),
        'R12 installer: the first visit writes a random 20-character key into install-key.php');
    assert_equals($r12iKey, WebInstaller::installKey($r12iRoot), 'R12 installer: later visits read the same key');
    mkdir($r12iBase . '/other');
    assert_true(!in_array(WebInstaller::installKey($r12iBase . '/other'), [null, $r12iKey], true), 'R12 installer: every install gets its own key');
    assert_equals([true, true, false, false], [
        WebInstaller::keyMatches($r12iRoot, (string)$r12iKey),
        WebInstaller::keyMatches($r12iRoot, ' ' . strtolower(str_replace('-', ' ', (string)$r12iKey)) . ' '),
        WebInstaller::keyMatches($r12iRoot, ''),
        WebInstaller::keyMatches($r12iRoot, substr((string)$r12iKey, 0, -1) . (str_ends_with((string)$r12iKey, 'A') ? 'B' : 'A')),
    ], 'R12 installer: the key matches regardless of case and spacing; empty or different keys do not');
    if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        mkdir($r12iBase . '/readonly', 0555);
        assert_equals(null, WebInstaller::installKey($r12iBase . '/readonly'), 'R12 installer: no key (and no install) when PHP cannot write the shop folder');
        chmod($r12iBase . '/readonly', 0755);
    }

    // M5: protection self-check against a server that ignores .htaccess (plain php -S) and one that applies it (router.php).
    $r12iWeb = $r12iBase . '/web';
    foreach (['/src', '/demo/wi/dist/downloads'] as $dir) mkdir($r12iWeb . $dir, 0777, true);
    file_put_contents($r12iWeb . '/src/autoload.php', "<?php\n");
    file_put_contents($r12iWeb . '/demo/wi/dist/starter.json', '{"name":"WI"}');
    file_put_contents($r12iWeb . '/demo/wi/dist/shop.sqlite', 'SQLite format 3');
    file_put_contents($r12iWeb . '/demo/wi/dist/downloads/book.epub', 'EPUB');
    copy(dirname(TEST_CLI_DIR) . '/router.php', $r12iWeb . '/router.php');
    copy(dirname(TEST_CLI_DIR) . '/.htaccess', $r12iWeb . '/.htaccess');
    $r12iServe = static function (bool $router) use ($r12iWeb, $r12iBase, &$r12iServers): array {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr((string)stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $r12iServers[] = proc_open(array_merge([PHP_BINARY, '-S', "127.0.0.1:{$port}"], $router ? ['router.php'] : ['-t', $r12iWeb]),
            [0 => ['pipe', 'r'], 1 => ['file', $r12iBase . '/server.log', 'a'], 2 => ['file', $r12iBase . '/server.log', 'a']], $pipes, $r12iWeb);
        for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) usleep(100000);
        return ['HTTP_HOST' => "127.0.0.1:{$port}", 'SERVER_ADDR' => '127.0.0.1', 'SERVER_PORT' => (string)$port, 'SCRIPT_NAME' => '/install.php'];
    };
    $r12iExposed = WebInstaller::exposedPaths($r12iWeb, $r12iServe(false));
    assert_equals(['src/autoload.php', 'demo/wi/dist/starter.json'], $r12iExposed, 'R12 installer: a server that ignores .htaccess is caught serving src/ and the starter data');
    assert_equals([], WebInstaller::exposedPaths($r12iWeb, $r12iServe(true)), 'R12 installer: a server that applies the rules passes the self-check');
    $r12iClosed = stream_socket_server('tcp://127.0.0.1:0');
    $r12iClosedPort = (int)substr(strrchr((string)stream_socket_get_name($r12iClosed, false), ':'), 1);
    fclose($r12iClosed);
    // (The probe falls back to port 80 on 127.0.0.1, where the server listens behind a port mapping.)
    $r12iPort80 = @fsockopen('127.0.0.1', 80, $r12iErrno, $r12iError, 1.0);
    if ($r12iPort80 === false) {
        assert_equals(null, WebInstaller::exposedPaths($r12iWeb, ['HTTP_HOST' => 'shop.test', 'SERVER_ADDR' => '127.0.0.1', 'SERVER_PORT' => (string)$r12iClosedPort, 'SCRIPT_NAME' => '/install.php'], 1.0),
            'R12 installer: no connection to itself means "cannot tell", not "protected"');
    } else {
        fclose($r12iPort80);
    }
    assert_equals(null, WebInstaller::exposedPaths($r12iWeb, ['HTTP_HOST' => "evil.test\r\nX: y", 'SERVER_PORT' => '80']), 'R12 installer: a forged Host header is never sent');
    $r12iInstall = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Install.php');
    assert_true(str_contains($r12iInstall, "\$errors['protection']") && !str_contains($r12iInstall, 'until then anyone') && !str_contains($r12iInstall, "'Installation failed: ' . \$e->getMessage()"),
        'R12 installer: install.php refuses an exposed server and shows no exception text before anyone signed in');
    file_put_contents($r12iWeb . '/config.php', '<?php return [];');
    [$r12iNone, $r12iFailure] = WebInstaller::installLocked([], $r12iWeb, false);
    @unlink($r12iWeb . '/config.php');
    assert_true($r12iNone === null && preg_match('/^Installation failed \(reference [0-9a-f]{8}\)\./', (string)$r12iFailure) === 1 && !str_contains((string)$r12iFailure, 'another request'),
        'R12 installer: a failed install shows only a reference; the exception text goes to the PHP log');

    // M5: the starter source in the web space is deleted after seeding; nothing outside demo/<name>/dist is touched.
    $r12iStarter = WebInstaller::starter($r12iWeb);
    assert_true($r12iStarter === null, 'R12 installer fixture: a manifest without its database files is not a starter');
    file_put_contents($r12iWeb . '/demo/wi/dist/stores.json', '{}');
    $r12iStarter = (array)WebInstaller::starter($r12iWeb);
    assert_equals(null, WebInstaller::removeStarter($r12iStarter, $r12iWeb), 'R12 installer: removing the copied starter succeeds');
    assert_true(!is_dir($r12iWeb . '/demo/wi/dist') && is_dir($r12iWeb . '/demo/wi'), 'R12 installer: demo/<name>/dist (database, eBooks) is gone from the web space');
    mkdir($r12iBase . '/elsewhere/dist', 0777, true);
    file_put_contents($r12iBase . '/elsewhere/dist/shop.sqlite', 'keep');
    assert_equals($r12iBase . '/elsewhere/dist', WebInstaller::removeStarter(['database' => $r12iBase . '/elsewhere/dist/shop.sqlite'], $r12iWeb),
        'R12 installer: a starter outside demo/ is reported, not deleted');
    assert_true(is_file($r12iBase . '/elsewhere/dist/shop.sqlite'), 'R12 installer: files outside demo/<name>/dist stay');
} catch (Throwable $e) {
    assert_true(false, 'R12 installer (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    foreach ($r12iServers as $server) if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $r12iTree($r12iBase);
}
