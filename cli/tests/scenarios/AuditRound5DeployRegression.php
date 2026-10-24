<?php
/**
 * Review round 5 (2026-10-18), installation and deployment:
 * - installed into a subdirectory of a web root (public_html/shop), the template's ../bareboneseshop-private
 *   landed inside public_html (database downloadable) and doctor still said "ready";
 * - a second install over an existing database (lost config.php, new checkout over the old private data)
 *   generated a new admin_identity_key: no admin could open their MFA secret, nothing warned;
 * - doctor and staging-preflight answered from config shape, not reality: preflight was NO-GO for every
 *   correct install (legacy admin_password empty although DB accounts replace it, unused carriers'
 *   webhook secrets, a storage/ directory nothing uses), and mail was read from the wrong source;
 * - the Docker sample had no production php.ini, its README install failed at step one (www-data cannot
 *   write config.php into the host-owned checkout) and uploaded images were 0750 for nginx (another user).
 */
echo "\n--- Round 5 deploy: subdirectory install, re-install keeps admins, real doctor/preflight, Docker ---\n";

$r5dSrc = dirname(TEST_CLI_DIR);
$r5dBase = test_fixture_path('r5deploy');
$r5dNorm = static fn(string $path): string => rtrim(str_replace('\\', '/', $path), '/');
$r5dInside = static fn(string $path, string $dir): bool => str_starts_with(strtolower($r5dNorm($path)) . '/', strtolower($r5dNorm($dir)) . '/');

// Web root detection.
$r5dWebRoot = static fn(string $root, array $cfg = []): ?string => method_exists('Setup', 'enclosingWebRoot') ? Setup::enclosingWebRoot($root, $cfg) : 'missing';
assert_equals('/home/u/public_html', $r5dWebRoot('/home/u/public_html/shop'), 'R5 deploy: public_html/shop is a subdirectory install');
assert_equals('/var/www/html', $r5dWebRoot('/var/www/html/eshop'), 'R5 deploy: /var/www/html/eshop is a subdirectory install');
assert_equals(null, $r5dWebRoot('/var/www/html'), 'R5 deploy: /var/www/html itself is the document root (Docker sample); /var/www is not served');
assert_equals(null, $r5dWebRoot('/srv/shop'), 'R5 deploy: an application root without a served parent has no enclosing web root');
assert_equals(null, $r5dWebRoot('/home/u/public_html/shop', ['operations' => ['document_root' => '/home/u/public_html/shop']]), 'R5 deploy: operations.document_root declares the application root as the document root');
assert_equals('/srv/site', $r5dWebRoot('/srv/site/shop', ['operations' => ['document_root' => '/srv/site']]), 'R5 deploy: operations.document_root names a served parent');

$r5dRemoveTree = static function (string $path): void {
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
};

try {
    // Doctor on the current checkout's config with the private paths moved into a served parent.
    $r5dShopOnly = $r5dBase . '/plain/public_html/shop';
    mkdir($r5dShopOnly, 0777, true);
    $r5dServed = $r5dBase . '/plain/public_html/bareboneseshop-private';
    $r5dCfg = array_replace(require $r5dSrc . '/config.php', [
        'db_driver' => 'sqlite', // the SQLite file check applies to SQLite installs; CI's config.php may be MySQL
        'private_runtime_dir' => $r5dServed, 'db_sqlite_path' => $r5dServed . '/db/shop.sqlite', 'private_cache_dir' => $r5dServed . '/cache',
        'jobs_dir' => $r5dServed . '/jobs', 'invoice_storage_dir' => $r5dServed . '/invoices', 'backup_storage_dir' => $r5dServed . '/backups',
        'backup_log_file' => $r5dServed . '/logs/backup.log', 'structured_log_file' => $r5dServed . '/logs/application.jsonl', 'extensions' => [],
        'digital_assets_base_dir' => $r5dBase . '/plain/public_html/private_downloads', 'metrics_log_file' => $r5dServed . '/logs/metrics.jsonl',
        'gdpr_salt_file' => $r5dServed . '/secrets/gdpr_salt', 'update_storage_dir' => $r5dServed . '/updates', 'backup_recovery_dir' => $r5dServed . '/restore-recovery',
    ]);
    foreach (['db', 'cache', 'jobs', 'invoices', 'backups', 'logs'] as $r5dDir) mkdir($r5dServed . '/' . $r5dDir, 0777, true);
    $r5dChecks = static function (array $cfg, string $root): array {
        $byName = [];
        foreach (Setup::doctor($cfg, $root, Db::get())['checks'] as $check) $byName[$check['name']] = $check;
        return $byName;
    };
    $r5dDoctor = $r5dChecks($r5dCfg, $r5dShopOnly);
    assert_true(($r5dDoctor['runtime.private_runtime_dir']['status'] ?? '') === 'fail' && str_contains($r5dDoctor['runtime.private_runtime_dir']['message'] ?? '', 'web root'),
        'R5 deploy: doctor fails a private runtime directory inside the web root of a subdirectory install (' . ($r5dDoctor['runtime.private_runtime_dir']['message'] ?? '') . ')');
    assert_equals('fail', $r5dDoctor['runtime.db_dir']['status'] ?? null, 'R5 deploy: doctor fails a SQLite database inside the web root');
    foreach (['digital_assets_base_dir', 'metrics_log_file', 'gdpr_salt_file', 'update_storage_dir', 'backup_recovery_dir'] as $r5dKey) {
        assert_true(($r5dDoctor['runtime.' . $r5dKey]['status'] ?? '') === 'fail' && str_contains($r5dDoctor['runtime.' . $r5dKey]['message'] ?? '', 'web root'),
            "R5 deploy: doctor fails {$r5dKey} inside the web root (paid downloads, salts and logs are private too)");
    }
    $r5dUnset = $r5dChecks(array_diff_key($r5dCfg, ['digital_assets_base_dir' => 1]), $r5dShopOnly);
    assert_true(!isset($r5dUnset['runtime.digital_assets_base_dir']), 'R5 deploy: a shop without digital goods gets no downloads-folder check');
    $r5dDeclared = $r5dChecks(array_replace_recursive($r5dCfg, ['operations' => ['document_root' => $r5dShopOnly]]), $r5dShopOnly);
    assert_equals('pass', $r5dDeclared['runtime.private_runtime_dir']['status'] ?? null, 'R5 deploy: the same paths pass when operations.document_root says the application root is served');

    // Full install from a clone-equivalent copy into public_html/shop.
    $r5dShop = $r5dBase . '/home/public_html/shop';
    $r5dCopy = static function (string $from, string $to, string $relative = '') use (&$r5dCopy): void {
        $skip = ['.git', 'node_modules', 'releases', 'tests', 'docs', 'demo', 'parity-report', 'playwright-report',
            'test-results', '.lighthouseci', 'config.php', 'admin-roles.json', '.env', 'cache', 'db', 'logs'];
        if (!is_dir($to)) mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ($relative === '' && in_array($entry, $skip, true))) continue;
            $top = explode('/', $relative . $entry)[0];
            if ($relative === 'import/' && str_starts_with($entry, 'template-')) continue;
            $path = $from . '/' . $entry;
            if (is_dir($path)) $r5dCopy($path, $to . '/' . $entry, $relative . $entry . '/');
            elseif (!in_array($top, ['jobs', 'storage', 'uploads'], true) || $entry === '.gitkeep') copy($path, $to . '/' . $entry);
        }
    };
    $r5dCopy($r5dSrc, $r5dShop);
    $r5dEnv = getenv();
    foreach (array_keys($r5dEnv) as $r5dName) if (str_starts_with($r5dName, 'TEST_') || str_starts_with($r5dName, 'BBE_') || $r5dName === 'MAIL_ADAPTER') unset($r5dEnv[$r5dName]);
    $r5dPhp = static function (array $args, array $extraEnv = []) use ($r5dShop, $r5dBase, $r5dEnv): array {
        $errFile = $r5dBase . '/stderr-' . bin2hex(random_bytes(4)) . '.log';
        $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, $r5dShop, $extraEnv + $r5dEnv);
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);
        $err = (string)@file_get_contents($errFile);
        @unlink($errFile);
        return ['code' => $code, 'out' => $out, 'err' => $err];
    };
    $r5dInstall = $r5dPhp(['cli/setup.php', 'install', '--json', '--app-url=https://shop.example/shop', '--admin-username=owner'], ['BBE_ADMIN_PASSWORD' => 'R5-deploy-Passw0rd!']);
    $r5dResult = json_decode($r5dInstall['out'], true);
    assert_equals(0, $r5dInstall['code'], 'R5 deploy: install into public_html/shop succeeds' . ($r5dInstall['code'] !== 0 ? ': ' . substr($r5dInstall['out'] . $r5dInstall['err'], -500) : ''));
    $r5dConfig = is_file($r5dShop . '/config.php') ? require $r5dShop . '/config.php' : [];
    foreach (['private_runtime_dir', 'db_sqlite_path', 'jobs_dir', 'gdpr_salt_file', 'digital_assets_base_dir'] as $r5dKey) {
        $r5dPath = (string)($r5dConfig[$r5dKey] ?? '');
        assert_true($r5dPath !== '' && !$r5dInside($r5dPath, $r5dBase . '/home/public_html') && $r5dInside($r5dPath, $r5dBase . '/home/bareboneseshop-data/shop'),
            "R5 deploy: install keeps {$r5dKey} above public_html ({$r5dPath})");
    }
    assert_true(is_file($r5dBase . '/home/bareboneseshop-data/shop/bareboneseshop-private/db/shop.sqlite') && !is_dir($r5dBase . '/home/public_html/bareboneseshop-private'),
        'R5 deploy: the database is created above the web root, nothing private inside public_html');
    $r5dFails = array_values(array_column(array_filter($r5dResult['doctor']['checks'] ?? [], static fn(array $c): bool => $c['status'] === 'fail'), 'name'));
    // R9: the shipped stores.json is example data, so doctor fails exactly the seller identities until the merchant enters them.
    $r5dStores = json_decode((string)file_get_contents($r5dShop . '/stores.json'), true);
    $r5dExampleSellers = array_values(array_map(static fn(string $id): string => 'stores.legal.' . $id, array_keys(array_filter($r5dStores['stores'] ?? [],
        static fn(array $s): bool => is_array($s['legal'] ?? null) && !isset($s['legal']['seller'])))));
    assert_true($r5dExampleSellers !== [], 'R5 deploy: the shipped stores.json has example seller identities');
    // R9: the sample card method is active and charges through Stripe, which has no keys yet, so checkout cannot take it.
    assert_equals(array_merge($r5dExampleSellers, ['payment.provider.stripe']), $r5dFails, 'R5 deploy: doctor after the subdirectory install fails only the example seller identities and the keyless Stripe card method');
    $r5dDoctorNames = array_column($r5dResult['doctor']['checks'] ?? [], 'status', 'name');
    assert_true(($r5dDoctorNames['admin.accounts'] ?? '') === 'pass' && ($r5dDoctorNames['admin.mfa_key'] ?? '') === 'pass', 'R5 deploy: doctor checks that the owner can sign in (account active, MFA key opens the secret)');

    // Staging preflight answers from the real install.
    $r5dPreflight = json_decode($r5dPhp(['cli/staging-preflight.php', '--json'])['out'], true);
    $r5dLevels = array_column($r5dPreflight['checks'] ?? [], 'level', 'name');
    assert_equals('ok', $r5dLevels['admin_accounts'] ?? null, 'R5 deploy: preflight accepts the DB owner account');
    assert_true(!isset($r5dLevels['admin_password']), 'R5 deploy: preflight does not demand the legacy admin_password once a DB account exists');
    assert_true(($r5dLevels['shipping_webhook_secret:packeta'] ?? '') === 'warn' && ($r5dLevels['shipping_webhook_secret:gls'] ?? '') === 'warn', 'R5 deploy: preflight warns (not NO-GO) about unset webhook secrets of carriers the shop may not use');
    assert_true(!isset($r5dLevels['dir:storage']) && ($r5dLevels['dir:cache'] ?? '') === 'ok' && ($r5dLevels['dir:uploads'] ?? '') === 'ok', 'R5 deploy: preflight checks the directories PHP writes (cache, uploads), not an unused storage/');
    assert_true(($r5dLevels['sqlite_path'] ?? '') === 'ok' && ($r5dLevels['jobs_dir'] ?? '') === 'ok', 'R5 deploy: preflight accepts private paths above the web root');
    assert_true(($r5dLevels['payment.provider.stripe'] ?? '') === 'fail' && !isset($r5dLevels['payment_adapter']),
        'R5 deploy: preflight is NO-GO for the active Stripe card method without keys, judged by the methods checkout offers, not provider_defaults (' . json_encode($r5dLevels['payment.provider.stripe'] ?? null) . ')');
    $r5dMail = $r5dPhp(['cli/staging-preflight.php', '--json'], ['MAIL_ADAPTER' => 'smtp']);
    $r5dMailCheck = array_values(array_filter(json_decode($r5dMail['out'], true)['checks'] ?? [], static fn(array $c): bool => $c['name'] === 'mail_adapter'))[0] ?? [];
    assert_true(($r5dMailCheck['level'] ?? '') === 'ok' && str_contains($r5dMailCheck['message'] ?? '', 'smtp'), 'R5 deploy: preflight reports the mail provider MailService uses (MAIL_ADAPTER)');
    $r5dDoctorMail = json_decode($r5dPhp(['cli/setup.php', 'doctor', '--json'], ['MAIL_ADAPTER' => 'smtp'])['out'], true);
    assert_equals('pass', array_column($r5dDoctorMail['checks'] ?? [], 'status', 'name')['provider.mail'] ?? null, 'R5 deploy: doctor reads MAIL_ADAPTER before provider_defaults.mail, like MailService');

    // Old template: only the paid downloads still sit in public_html/private_downloads, every other path was moved.
    $r5dLeak = $r5dBase . '/home/public_html/private_downloads';
    mkdir($r5dLeak, 0777, true);
    copy($r5dShop . '/config.php', $r5dShop . '/config.r5orig.php');
    file_put_contents($r5dShop . '/config.php', "<?php\n\$c = require __DIR__ . '/config.r5orig.php';\n\$c['digital_assets_base_dir'] = " . var_export($r5dLeak, true) . ";\nreturn \$c;\n");
    $r5dLeakDoctor = json_decode($r5dPhp(['cli/setup.php', 'doctor', '--json'])['out'], true);
    $r5dLeakStatus = array_column($r5dLeakDoctor['checks'] ?? [], 'status', 'name');
    assert_true(($r5dLeakStatus['runtime.digital_assets_base_dir'] ?? '') === 'fail' && ($r5dLeakDoctor['status'] ?? '') !== 'ready',
        'R5 deploy: doctor is not ready while the paid downloads are inside public_html (' . ($r5dLeakDoctor['status'] ?? '?') . ')');
    $r5dLeakPre = json_decode($r5dPhp(['cli/staging-preflight.php', '--json'])['out'], true);
    assert_equals('fail', array_column($r5dLeakPre['checks'] ?? [], 'level', 'name')['digital_assets_base_dir'] ?? null, 'R5 deploy: preflight is NO-GO while the paid downloads are inside public_html');
    rename($r5dShop . '/config.r5orig.php', $r5dShop . '/config.php');
    $r5dCleanPre = json_decode($r5dPhp(['cli/staging-preflight.php', '--json'])['out'], true);
    assert_equals('ok', array_column($r5dCleanPre['checks'] ?? [], 'level', 'name')['digital_assets_base_dir'] ?? null, 'R5 deploy: preflight accepts the downloads folder the installer put above the web root');

    // Lost config.php over the existing database: install must not lock the owner out.
    $r5dOldConfig = (string)file_get_contents($r5dShop . '/config.php');
    unlink($r5dShop . '/config.php');
    $r5dAgain = $r5dPhp(['cli/setup.php', 'install', '--json']);
    assert_true($r5dAgain['code'] !== 0 && str_contains($r5dAgain['out'] . $r5dAgain['err'], 'previous admin_identity_key'), 'R5 deploy: install over a database whose admins need the previous key fails with that reason' . ($r5dAgain['code'] === 0 ? ' (exit 0)' : ''));
    assert_true(!is_file($r5dShop . '/config.php'), 'R5 deploy: the regenerated config.php with the wrong key is removed again');
    file_put_contents($r5dShop . '/config.php', $r5dOldConfig);
    $r5dRestored = json_decode($r5dPhp(['cli/setup.php', 'doctor', '--json'])['out'], true);
    assert_equals('pass', array_column($r5dRestored['checks'] ?? [], 'status', 'name')['admin.mfa_key'] ?? null, 'R5 deploy: with the previous config.php restored the owner signs in again');
    $r5dWrongKey = json_decode($r5dPhp(['cli/setup.php', 'doctor', '--json'], ['BBE_ADMIN_IDENTITY_KEY' => base64_encode(random_bytes(32))])['out'], true);
    $r5dWrongCheck = array_values(array_filter($r5dWrongKey['checks'] ?? [], static fn(array $c): bool => $c['name'] === 'admin.mfa_key'))[0] ?? [];
    assert_true(($r5dWrongCheck['status'] ?? '') === 'fail' && str_contains($r5dWrongCheck['message'] ?? '', 'owner') && ($r5dWrongKey['status'] ?? '') !== 'ready', 'R5 deploy: doctor is not ready while the MFA key cannot open an admin\'s secret');
    $r5dWrongPreflight = json_decode($r5dPhp(['cli/staging-preflight.php', '--json'], ['BBE_ADMIN_IDENTITY_KEY' => base64_encode(random_bytes(32))])['out'], true);
    assert_equals('fail', array_column($r5dWrongPreflight['checks'] ?? [], 'level', 'name')['admin_accounts'] ?? null, 'R5 deploy: preflight is NO-GO while the MFA key cannot open an admin\'s secret');

    // Uploaded images: directories readable by a web server running as another user.
    if (DIRECTORY_SEPARATOR === '/' && (umask() & 0055) === 0) {
        $r5dUploads = $r5dBase . '/uploads';
        (new LocalStorage(['uploads_dir' => $r5dUploads]))->store('r5/nested/pixel.png', 'png');
        assert_equals(0755, fileperms($r5dUploads . '/r5/nested') & 0777, 'R5 deploy: upload directories are 0755 so nginx (another user) can serve the images');
        assert_equals(0755, fileperms($r5dUploads) & 0777, 'R5 deploy: the uploads root created by LocalStorage is 0755');
    }
} finally {
    $r5dRemoveTree($r5dBase);
}

// Docker sample: production php.ini and an installer that works from a host-owned checkout.
$r5dDockerfile = (string)file_get_contents($r5dSrc . '/deploy/Dockerfile');
$r5dIni = (string)@file_get_contents($r5dSrc . '/deploy/php.ini');
$r5dCompose = str_replace("\r\n", "\n", (string)file_get_contents($r5dSrc . '/deploy/docker-compose.yml'));
$r5dSetupSh = (string)@file_get_contents($r5dSrc . '/deploy/docker-setup.sh');
$r5dCi = (string)file_get_contents($r5dSrc . '/.github/workflows/ci.yml');
assert_true(str_contains($r5dDockerfile, 'php.ini-production') && preg_match('/^display_errors\s*=\s*Off/mi', $r5dIni) === 1 && preg_match('/^expose_php\s*=\s*Off/mi', $r5dIni) === 1 && str_contains($r5dDockerfile, 'COPY php.ini'),
    'R5 deploy: the Docker image uses the production php.ini and never displays errors');
$r5dSetupBlock = preg_match('/^  setup:\n(.*?)(?=^  [a-z]|^\S)/ms', $r5dCompose, $r5dMatch) ? $r5dMatch[1] : '';
assert_true(str_contains($r5dSetupBlock, 'user: "0:0"') && str_contains($r5dSetupBlock, 'deploy/docker-setup.sh'), 'R5 deploy: the opt-in setup service can create config.php in a host-owned checkout');
assert_true(str_contains($r5dSetupSh, 'chown -R www-data:www-data cache uploads import logs /var/www/bareboneseshop-private') && str_contains($r5dSetupSh, ':www-data config.php') && str_contains($r5dSetupSh, 'exit "$status"'),
    'R5 deploy: docker-setup.sh hands the writable directories to www-data, config.php to its group, and keeps the exit code');
$r5dCiDocker = substr($r5dCi, (int)strpos($r5dCi, 'docker-image:'), 4000);
assert_true(str_contains($r5dCiDocker, 'setup php cli/setup.php install') && str_contains($r5dCiDocker, '/uploads/ci/probe/pixel.png') && str_contains($r5dCiDocker, 'submodules: true'),
    'R5 deploy: CI runs the README Docker install and fetches an uploaded image through nginx');
