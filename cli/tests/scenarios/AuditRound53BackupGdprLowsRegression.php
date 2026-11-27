<?php

// Review round 53 (backup, logs and GDPR, low):
//  - cli/restore.php --quiet onto a server without db_name created the database and restored into it without asking;
//  - --list printed 12 characters of the SHA-256 and nothing of the files archive, so the runbook's own SHA-256 list
//    could not be compared;
//  - the backup metadata (writable by whoever writes the backup storage) decided whether a source is one file or a
//    directory: a forged "directory" turned the missing GDPR salt file into a directory;
//  - the copies set aside took the mode of the directory above (0644 config.php copies in a 0755 tree), not 0600/0700;
//  - cli/staging-preflight.php recommended "php cli/gdpr-gc.php --dry-run", which stopped with "Unknown arg";
//  - the checkout error log kept the IBAN, phone and name of MySQL and gateway messages;
//  - the GDPR erasure kept an API replay response naming the person's order but no longer the e-mail.
echo "\n--- Audit round 53 (low): restore --quiet asks, full SHA-256 list, source kinds, private copies, gdpr-gc --dry-run, log PII, API replay erasure ---\n";
$r53gRoot = sys_get_temp_dir() . '/bbe-r53g-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($r53gRoot, 0755, true);
chmod($r53gRoot, 0755);
$r53gRm = static function (string $path) use (&$r53gRm): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $r53gRm($path . '/' . $entry);
    }
    @rmdir($path);
};
$r53gPhp = static function (array $args, string $stdin = '', ?array $env = null): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};

// 1. Backup files: source kinds from this shop, private copies set aside, the files archive in list().
$r53gShop = $r53gRoot . '/shop';
$r53gData = $r53gRoot . '/data';
foreach (['/themes/default/assets', '/uploads'] as $r53gSub) @mkdir($r53gShop . $r53gSub, 0755, true);
@mkdir($r53gData . '/secrets', 0755, true);
file_put_contents($r53gShop . '/config.php', "<?php return ['db_password' => 'r53g-live'];");
file_put_contents($r53gShop . '/themes/default/assets/shell.php', '<?php echo 1;');
file_put_contents($r53gData . '/secrets/gdpr_salt', 'r53g-salt');
$r53gSalt = $r53gData . '/secrets/gdpr_salt';
$r53gSources = BackupService::defaultFileSources(['private_runtime_dir' => $r53gData, 'jobs_dir' => $r53gData . '/jobs',
    'invoice_storage_dir' => $r53gData . '/invoices', 'uploads_dir' => $r53gShop . '/uploads', 'gdpr_salt_file' => $r53gSalt,
    'digital_assets_base_dir' => $r53gData . '/downloads'], $r53gShop);
$r53gPdo = new PDO('sqlite:' . $r53gRoot . '/files.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r53gPdo->exec('CREATE TABLE x (id INTEGER PRIMARY KEY)');
try {
    BackupService::init($r53gPdo, ['enabled' => true, 'storage' => 'local', 'compression' => false, 'auto_gc_on_backup' => false,
        'retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'minimum_kept' => 5], 'storage_dir' => $r53gRoot . '/backups', 'files' => $r53gSources], $r53gShop);
    $r53gMeta = BackupService::create(['label' => 'manual']);
    $r53gPath = (string)$r53gMeta['remote_path'];
    $r53gArchive = $r53gRoot . '/backups/' . $r53gPath . '.files.tar';

    $r53gRow = null;
    foreach (BackupService::list() as $r53gR) if (($r53gR['path'] ?? '') === $r53gPath) $r53gRow = $r53gR;
    assert_true(is_array($r53gRow) && ($r53gRow['files_sha256'] ?? '') === hash_file('sha256', $r53gArchive) && ($r53gRow['files_size'] ?? 0) === filesize($r53gArchive)
        && ($r53gRow['meta']['sha256'] ?? '') === hash_file('sha256', $r53gRoot . '/backups/' . $r53gPath),
        'R53 backup list: each backup carries its files archive\'s SHA-256 and size, and both SHA-256 values are those sha256sum prints');
    $r53gRestoreCli = (string)file_get_contents(TEST_CLI_DIR . '/restore.php');
    assert_true(str_contains($r53gRestoreCli, "\$r['files_sha256']") && !str_contains($r53gRestoreCli, "sha256'] ?? ''), 0, 12)"),
        'R53 backup list: cli/restore.php --list prints the full SHA-256 and the files archive\'s (was: 12 characters, files=yes)');

    // The metadata of the files archive is forged: the GDPR salt "is a directory".
    $r53gMetaFile = $r53gArchive . '.meta.json';
    $r53gForged = json_decode((string)file_get_contents($r53gMetaFile), true);
    $r53gForged['sources']['gdpr_salt'] = 'directory';
    file_put_contents($r53gMetaFile, json_encode($r53gForged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    unlink($r53gSalt);
    file_put_contents($r53gShop . '/config.php', "<?php return ['db_password' => 'r53g-new-server'];");
    unlink($r53gShop . '/themes/default/assets/shell.php');
    $r53gRestored = null;
    try { $r53gRestored = BackupService::restoreFiles($r53gPath); } catch (Throwable $e) { $r53gRestored = $e->getMessage(); }
    clearstatcache();
    // R61: a salt the live shop lacks is set aside (as that file), not written in place.
    $r53gSaltCopy = (is_array($r53gRestored) ? (string)($r53gRestored['aside_dir'] ?? '') : '') . '/' . basename($r53gSalt);
    assert_true(!file_exists($r53gSalt) && is_file($r53gSaltCopy) && (string)@file_get_contents($r53gSaltCopy) === 'r53g-salt',
        'R53 backup kinds: the GDPR salt is restored as the file this shop configures, whatever the metadata says (was: a directory at its path; '
        . (is_array($r53gRestored) ? 'restored' : (string)$r53gRestored) . ')');
    if (PHP_OS_FAMILY !== 'Windows') {
        $r53gAside = is_array($r53gRestored) ? (string)($r53gRestored['aside_dir'] ?? '') : '';
        $r53gModes = [];
        foreach (['', '/config.php', '/themes', '/themes/default/assets', '/themes/default/assets/shell.php'] as $r53gRel) {
            $r53gModes[$r53gRel] = file_exists($r53gAside . $r53gRel) ? sprintf('%o', fileperms($r53gAside . $r53gRel) & 0777) : 'missing';
        }
        assert_equals(['' => '700', '/config.php' => '600', '/themes' => '700', '/themes/default/assets' => '700', '/themes/default/assets/shell.php' => '600'], $r53gModes,
            'R53 backup copies: everything set aside (config.php, quarantined scripts) is 0600 in 0700 directories (was: modelled on the 0755 directory above)');
    }
} catch (Throwable $e) {
    assert_true(false, 'R53 backup files (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    BackupService::reset();
    $r53gPdo = null;
    gc_collect_cycles();
}

// 2. gdpr-gc.php takes the --dry-run that cli/staging-preflight.php recommends (it stopped at "Unknown arg").
[$r53gCode, $r53gOut] = $r53gPhp([TEST_CLI_DIR . '/gdpr-gc.php', '--dry-run', '--policy=r53g-no-such-policy']);
assert_true($r53gCode === 1 && str_contains($r53gOut, 'Unknown policy') && !str_contains($r53gOut, 'Unknown arg'),
    "R53 gdpr-gc: --dry-run is accepted (exit {$r53gCode}: " . trim($r53gOut) . ')');
[$r53gCode, $r53gOut] = $r53gPhp([TEST_CLI_DIR . '/gdpr-gc.php', '--dry-run', '--apply']);
assert_true($r53gCode === 1 && str_contains($r53gOut, 'exclude each other'), "R53 gdpr-gc: --dry-run with --apply is refused (exit {$r53gCode}: " . trim($r53gOut) . ')');
assert_true(str_contains((string)file_get_contents(TEST_CLI_DIR . '/staging-preflight.php'), "'php cli/gdpr-gc.php --dry-run'"),
    'R53 gdpr-gc: the command cli/staging-preflight.php recommends is the one tested');

// 3. The PHP error log (the checkout's "Checkout failed" line) masks the IBAN, phone and name of database and gateway messages.
$r53gLog = $r53gRoot . '/php-error.log';
$r53gPrevLog = ini_set('error_log', $r53gLog);
try {
    Logger::phpLog("Checkout failed [r53g] PDOException @Orders.php:42: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'Ján Novák-default' for key 'r53g'"
        . " | Incorrect string value: 'Ján Novák' for column `customer_name` at row 1"
        . ' | ThePay 400: {"payer":{"name":"Ján Novák","phone":"0905 123 456","iban":"SK31 1200 0000 1987 4263 7541"},"order_id":7,"amount":1290}'
        . ' | GoPay refund: account SK3112000000198742637541, contact +421 905 123 456, customer_name=Ján Novák'
        . ' | [24-Oct-2026 02:00:00 UTC] order 2026000123 total 12.90 EUR, EAN 8588000000001');
} finally {
    ini_set('error_log', (string)$r53gPrevLog);
}
$r53gLogged = (string)@file_get_contents($r53gLog);
assert_true(!preg_match('/Novák|905 123 456|0905|1987 4263|198742637541/u', $r53gLogged),
    "R53 log: names, phone numbers and IBANs of the message are masked ({$r53gLogged})");
assert_true(str_contains($r53gLogged, '"order_id":7') && str_contains($r53gLogged, '"amount":1290') && str_contains($r53gLogged, '02:00:00 UTC')
    && str_contains($r53gLogged, 'order 2026000123 total 12.90 EUR') && str_contains($r53gLogged, '8588000000001') && str_contains($r53gLogged, "for key 'r53g'"),
    "R53 log: order ids, amounts, times and product codes stay ({$r53gLogged})");

// 4. The GDPR erasure deletes the API replay responses naming the person's order, also without the current e-mail.
$r53gDb = Db::get();
$r53gEmail = 'r53g-erase-' . bin2hex(random_bytes(3)) . '@example.test';
$r53gNumber = 'R53G-' . bin2hex(random_bytes(3));
try {
    $r53gDb->prepare("INSERT INTO customers (email, password_hash, name, phone) VALUES (?, 'x', 'R53G Person', '+421900005353')")->execute([$r53gEmail]);
    $r53gCustomer = (int)$r53gDb->lastInsertId();
    $r53gDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_id, customer_name, customer_email, shipping_addr,
            payment_method, subtotal, total, currency, store_id, locale) VALUES (?, 'delivered', 'paid', ?, 'R53G Person', ?, '{}', 'card', 1000, 1000, 'eur', 'default', 'sk')")
        ->execute([$r53gNumber, $r53gCustomer, $r53gEmail]);
    $r53gOrder = (int)$r53gDb->lastInsertId();
    $r53gDb->exec("INSERT INTO api_clients (key_id, token_hash, store_id, name, scopes) VALUES ('r53g-" . bin2hex(random_bytes(3)) . "', 'h', 'default', 'R53G ERP', '[]')");
    $r53gClient = (int)$r53gDb->lastInsertId();
    $r53gIdem = $r53gDb->prepare("INSERT INTO api_idempotency (store_id, client_id, idempotency_key, request_hash, response_status, response_body) VALUES ('default', ?, ?, 'h', 200, ?)");
    $r53gBody = static fn(string $number, string $email): string => json_encode(['data' => ['id' => 1, 'number' => $number, 'customer' => ['name' => 'R53G Person', 'email' => $email]]],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // Stored before the order's e-mail was corrected: names the order, not the address the erasure knows.
    $r53gIdem->execute([$r53gClient, 'r53g-old-email', $r53gBody($r53gNumber, 'r53g-typo@example.test')]);
    $r53gIdem->execute([$r53gClient, 'r53g-current', $r53gBody($r53gNumber, $r53gEmail)]);
    $r53gIdem->execute([$r53gClient, 'r53g-other', $r53gBody($r53gNumber . '9', 'r53g-other@example.test')]);
    GdprService::anonymizeCustomer($r53gCustomer, 'admin');
    $r53gLeft = $r53gDb->prepare('SELECT idempotency_key FROM api_idempotency WHERE client_id = ? ORDER BY idempotency_key');
    $r53gLeft->execute([$r53gClient]);
    assert_equals(['r53g-other'], $r53gLeft->fetchAll(PDO::FETCH_COLUMN),
        'R53 GDPR: the API replay responses of the erased customer\'s order go, also one naming an older e-mail; another order\'s stays');
} catch (Throwable $e) {
    assert_true(false, 'R53 GDPR API replay (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    if (isset($r53gClient)) {
        $r53gDb->exec("DELETE FROM api_idempotency WHERE client_id = {$r53gClient}");
        $r53gDb->exec("DELETE FROM api_clients WHERE id = {$r53gClient}");
    }
}

// 5. cli/restore.php --quiet onto a MySQL server without db_name still asks (it created the database unasked).
if (getenv('TEST_MYSQL_DSN')) {
    $r53gMissing = 'bbe_r53g_missing_' . bin2hex(random_bytes(4));
    $r53gSuite = sys_get_temp_dir() . '/' . AppConfig::SUITE_DIR_PREFIX . 'r53g-' . bin2hex(random_bytes(4));
    mkdir($r53gSuite, 0700);
    preg_match('/host=([^;]+)/', (string)getenv('TEST_MYSQL_DSN'), $r53gHost);
    preg_match('/port=(\d+)/', (string)getenv('TEST_MYSQL_DSN'), $r53gPort);
    $r53gConfig = (array)require AppConfig::path(dirname(TEST_CLI_DIR));
    file_put_contents($r53gSuite . '/' . AppConfig::SUITE_FILE, '<?php return ' . var_export(['db_driver' => 'mysql', 'db_host' => $r53gHost[1] ?? '127.0.0.1',
        'db_port' => (int)($r53gPort[1] ?? 3306), 'db_name' => $r53gMissing, 'db_charset' => 'utf8mb4',
        'db_user' => (string)getenv('TEST_MYSQL_USER'), 'db_password' => (string)getenv('TEST_MYSQL_PASS')] + $r53gConfig, true) . ';');
    $r53gServer = new PDO(preg_replace('/;?dbname=[^;]*/', '', (string)getenv('TEST_MYSQL_DSN')), (string)getenv('TEST_MYSQL_USER'), (string)getenv('TEST_MYSQL_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r53gExists = static fn(): int => (int)$r53gServer->query('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $r53gServer->quote($r53gMissing))->fetchColumn();
    $r53gEnv = ['BBE_SUITE_DATA_DIR' => $r53gSuite, 'BBE_SUITE_APP_ROOT' => (string)realpath(dirname(TEST_CLI_DIR))] + getenv();
    try {
        [$r53gCode, $r53gOut] = $r53gPhp([TEST_CLI_DIR . '/restore.php', '2026/11/22/eshop-r53g.sql', '--i-stopped-writers', '--quiet'], '', $r53gEnv);
        assert_true($r53gCode === 1 && str_contains($r53gOut, 'Aborted') && $r53gExists() === 0,
            "R53 restore: --quiet without --yes asks before it creates db_name, and no answer creates nothing (exit {$r53gCode}: " . substr(trim($r53gOut), 0, 300) . ')');
    } finally {
        $r53gServer->exec('DROP DATABASE IF EXISTS `' . $r53gMissing . '`');
        @unlink($r53gSuite . '/' . AppConfig::SUITE_FILE);
        @rmdir($r53gSuite);
    }
} else {
    print "  [SKIP] R53 restore --quiet into a missing db_name (set TEST_MYSQL_DSN)\n";
}
$r53gRm($r53gRoot);
