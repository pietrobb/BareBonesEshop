<?php

// Review round 53 (medium 1): a restore wrote the files of the backup's themes/ and uploads/ that were missing in place,
// whatever they were: a forged backup's themes/default/assets/shell.php ran over HTTP after cli/restore.php --files-only,
// and no web server rule denied PHP under themes/ (on Apache a restored uploads/.htaccess could also replace the rules).
echo "\n--- Audit round 53: no script or server config file restored into the web root, no PHP under themes/ ---\n";
$r53rRoot = sys_get_temp_dir() . '/bbe-r53r-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($r53rRoot, 0700, true);
$r53rRm = static function (string $path) use (&$r53rRm): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $r53rRm($path . '/' . $entry);
    }
    @rmdir($path);
};

// 1. The restore puts scripts and dotfiles of themes/ and uploads/ in the private recovery directory, never in place.
$r53rShop = $r53rRoot . '/shop';
$r53rData = $r53rRoot . '/data';
foreach (['/themes/default/assets', '/uploads/full', '/uploads/.hidden'] as $r53rSub) @mkdir($r53rShop . $r53rSub, 0700, true);
@mkdir($r53rData, 0700, true);
file_put_contents($r53rShop . '/config.php', "<?php return [];");
$r53rRunnable = [
    'themes/default/assets/shell.php'  => '<?php system($_GET["c"]);',
    'themes/default/.user.ini'         => 'auto_prepend_file=/tmp/x',
    'uploads/.htaccess'                => "RewriteEngine On\nAddHandler application/x-httpd-php .jpg",
    'uploads/full/photo.php.jpg'       => '<?php echo 1;',
    'uploads/full/run.PHTML'           => '<?php echo 1;',
    'uploads/.hidden/plain.jpg'        => 'jpeg',
];
$r53rPlain = [
    'themes/default/assets/shop.css'   => 'body{}',
    'themes/default/assets/shop.js'    => 'void 0;',
    'uploads/full/photo.jpg'           => 'jpeg',
    'uploads/full/php-manual.pdf'      => '%PDF',
];
foreach ($r53rRunnable + $r53rPlain as $r53rRel => $r53rBody) file_put_contents($r53rShop . '/' . $r53rRel, $r53rBody);
file_put_contents($r53rShop . '/uploads/full/.gitkeep', '');
$r53rSources = BackupService::defaultFileSources(['private_runtime_dir' => $r53rData, 'jobs_dir' => $r53rData . '/jobs',
    'invoice_storage_dir' => $r53rData . '/invoices', 'uploads_dir' => $r53rShop . '/uploads', 'gdpr_salt_file' => $r53rData . '/gdpr_salt',
    'digital_assets_base_dir' => $r53rData . '/downloads'], $r53rShop);
$r53rPdo = new PDO('sqlite:' . $r53rRoot . '/files.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r53rPdo->exec('CREATE TABLE x (id INTEGER PRIMARY KEY)');
try {
    BackupService::init($r53rPdo, ['enabled' => true, 'storage' => 'local', 'compression' => false, 'auto_gc_on_backup' => false,
        'retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'minimum_kept' => 5], 'storage_dir' => $r53rRoot . '/backups', 'files' => $r53rSources], $r53rShop);
    // The archive holds what an attacker with write access to the backup storage would put in: the restore cannot tell.
    $r53rMeta = BackupService::create(['label' => 'manual']);
    foreach (array_keys($r53rRunnable + $r53rPlain) as $r53rRel) unlink($r53rShop . '/' . $r53rRel);
    $r53rFiles = BackupService::restoreFiles((string)$r53rMeta['remote_path']);
    $r53rAside = (string)($r53rFiles['aside_dir'] ?? '');
    clearstatcache();
    foreach ($r53rRunnable as $r53rRel => $r53rBody) {
        assert_true(!file_exists($r53rShop . '/' . $r53rRel), "R53 restore: {$r53rRel} is not restored into the web root");
        assert_equals($r53rBody, @file_get_contents($r53rAside . '/' . $r53rRel), "R53 restore: {$r53rRel} is kept in the private recovery directory");
    }
    foreach ($r53rPlain as $r53rRel => $r53rBody) {
        // R54: a theme file the live themes lack is set aside like a changed one; uploads are restored in place.
        $r53rWhere = str_starts_with($r53rRel, 'themes/') ? $r53rAside : $r53rShop;
        assert_equals($r53rBody, @file_get_contents($r53rWhere . '/' . $r53rRel), "R53 restore: {$r53rRel} is restored " . ($r53rWhere === $r53rShop ? 'in place' : 'aside'));
    }
    $r53rQuarantined = (array)($r53rFiles['quarantined'] ?? []);
    sort($r53rQuarantined);
    $r53rExpected = array_keys($r53rRunnable);
    sort($r53rExpected);
    assert_equals($r53rExpected, $r53rQuarantined, 'R53 restore: the files kept out of the web root are reported');
    assert_true(is_file($r53rShop . '/uploads/full/.gitkeep') && !file_exists($r53rAside . '/uploads/full/.gitkeep'),
        'R53 restore: a dotfile identical to the live one is just unchanged');
    $r53rSetAside = (array)($r53rFiles['set_aside'] ?? null);
    sort($r53rSetAside);
    assert_equals(['themes/default/assets/shop.css', 'themes/default/assets/shop.js'], $r53rSetAside,
        'R53/R54 restore: only the missing theme files are reported as set aside');
    assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/cli/restore.php'), "\$files['quarantined']"),
        'R53 restore: cli/restore.php warns about the files kept out of the web root');
} catch (Throwable $e) {
    assert_true(false, 'R53 restore (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    BackupService::reset();
    $r53rPdo = null;
    gc_collect_cycles();
}

// 2. PHP under themes/ is denied by the bundled rules: .htaccess (router.php applies it) and nginx.conf.
$r53rRepo = dirname(TEST_CLI_DIR);
$r53rWeb = $r53rRoot . '/web';
@mkdir($r53rWeb . '/themes/default/assets', 0777, true);
copy($r53rRepo . '/router.php', $r53rWeb . '/router.php');
copy($r53rRepo . '/.htaccess', $r53rWeb . '/.htaccess');
file_put_contents($r53rWeb . '/index.php', '<?php echo "shop";');
file_put_contents($r53rWeb . '/themes/default/assets/shell.php', '<?php echo "R53-RAN";');
file_put_contents($r53rWeb . '/themes/default/assets/shell.PHP', '<?php echo "R53-RAN";');
file_put_contents($r53rWeb . '/themes/default/assets/shell.php.css', '<?php echo "R53-RAN";');
file_put_contents($r53rWeb . '/themes/default/assets/shop.css', 'body{}');
$r53rProbe = stream_socket_server('tcp://127.0.0.1:0');
$r53rPort = (int)substr(strrchr((string)stream_socket_get_name($r53rProbe, false), ':'), 1);
fclose($r53rProbe);
$r53rServer = test_spawn_server([PHP_BINARY, '-S', "127.0.0.1:{$r53rPort}", 'router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $r53rRoot . '/server.log', 'a'], 2 => ['file', $r53rRoot . '/server.log', 'a']], $r53rPipes, $r53rWeb);
try {
    for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $r53rPort) === false; $i++) usleep(100000);
    foreach (['/themes/default/assets/shell.php', '/themes/default/assets/shell.PHP', '/THEMES/default/assets/shell.php', '/themes/default/assets/shell.php.css',
        '/themes//default/./assets/shell.php', '/themes/default/assets/shell%2ephp'] as $r53rPath) {
        $r53rBody = @file_get_contents("http://127.0.0.1:{$r53rPort}{$r53rPath}", false, stream_context_create(['http' => ['ignore_errors' => true]]));
        $r53rStatus = (string)($http_response_header[0] ?? 'no answer');
        assert_true(str_contains($r53rStatus, '403') && !str_contains((string)$r53rBody, 'R53-RAN'), "R53 web: {$r53rPath} answers 403 and is not run ({$r53rStatus})");
    }
    $r53rCss = @file_get_contents("http://127.0.0.1:{$r53rPort}/themes/default/assets/shop.css");
    assert_equals('body{}', $r53rCss, 'R53 web: theme assets are still served');
} finally {
    if (is_resource($r53rServer)) { proc_terminate($r53rServer); proc_close($r53rServer); }
}
$r53rNginx = (string)file_get_contents($r53rRepo . '/deploy/nginx.conf');
$r53rThemePhp = strpos($r53rNginx, 'location ~* ^/themes/.*\.(?:php(?:[0-9]+|s)?|phtml?|phar)(?:[./]|$) { return 403; }');
assert_true($r53rThemePhp !== false && $r53rThemePhp < (int)strpos($r53rNginx, 'location ~ \.php$'),
    'R53 web: nginx denies PHP under /themes/ before its PHP location');
$r53rRm($r53rRoot);
