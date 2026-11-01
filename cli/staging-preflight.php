<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — Staging Deploy Preflight
 *
 * Safe repo-side checklist for staging deployments. It does not deploy,
 * upload files, mutate production data, or require secrets in git.
 *
 * Usage:
 *   php cli/staging-preflight.php
 *   php cli/staging-preflight.php --json
 *   php cli/staging-preflight.php --strict
 */
require_once __DIR__ . '/../src/autoload.php';

function preflightEnv(string $key): string {
    $value = getenv($key);
    if ($value !== false) return trim((string)$value);
    return trim((string)($_ENV[$key] ?? ''));
}

function preflightSecretLooksFilled(string $value): bool {
    if (strlen($value) < 16 || trim($value) !== $value) return false;
    $lower = strtolower($value);
    foreach (['...', 'your_', 'example', 'changeme', 'placeholder'] as $needle) {
        if (str_contains($lower, $needle)) return false;
    }
    return true;
}

function preflightFileJson(string $path): array {
    if (!is_file($path)) return [false, null];
    $data = json_decode((string)file_get_contents($path), true);
    return [json_last_error() === JSON_ERROR_NONE, $data];
}

function runStagingPreflight(array $argvLocal = []): array {
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $cfg = require $root . '/config.php';
    $checks = [];
    $add = static function (string $level, string $name, string $message, string $remediation = '') use (&$checks): void {
        $checks[] = compact('level', 'name', 'message', 'remediation');
    };
    $webRoot = Setup::enclosingWebRoot($root, $cfg);
    $privatePathOk = static function (string $path) use ($root, $webRoot): bool {
        $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        if (!$absolute || preg_match('/[\x00-\x1F\x7F]/', $path)) return false;
        $probe = $path;
        $suffix = [];
        while (!file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) break;
            array_unshift($suffix, basename($probe));
            $probe = $parent;
        }
        $candidate = realpath($probe);
        if ($candidate === false) return false;
        foreach ($suffix as $segment) {
            if ($segment === '.' || $segment === '') continue;
            $candidate = $segment === '..' ? dirname($candidate) : $candidate . DIRECTORY_SEPARATOR . $segment;
        }
        $app = rtrim(str_replace('\\', '/', $root), '/');
        $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $app = strtolower($app);
            $candidate = strtolower($candidate);
        }
        $served = $webRoot === null ? '' : strtolower(rtrim(str_replace('\\', '/', $webRoot), '/'));
        if ($served !== '' && str_starts_with(strtolower($candidate) . '/', $served . '/')) return false;
        return $candidate !== $app && !str_starts_with($candidate, $app . '/');
    };

    $add('info', 'mode', 'Preflight only: no deploy, no upload, no destructive action.');

    if (is_file($root . '/.env')) {
        $add('ok', '.env', '.env exists locally and is ignored by git.');
    } else {
        $add('warn', '.env', '.env not found.', 'Create it from .env.example or provide equivalent process environment variables on staging.');
    }

    // Database admin accounts replace the legacy admin_password as soon as one exists.
    $adminTotal = AdminIdentity::available() ? AdminIdentity::accountCount() : 0;
    if ($adminTotal > 0) {
        $adminActive = AdminIdentity::activeCount();
        $adminLocked = AdminIdentity::undecryptableMfaAccounts();
        $adminOk = $adminActive > 0 && $adminLocked === [];
        $add($adminOk ? 'ok' : 'fail', 'admin_accounts', $adminOk ? "{$adminActive} active admin account(s); admin_identity_key opens their MFA secrets."
            : ($adminActive === 0 ? 'Every admin account is disabled.' : 'admin_identity_key cannot decrypt the MFA secret of: ' . implode(', ', $adminLocked)),
            'Restore admin_identity_key from the previous config.php (or BBE_ADMIN_IDENTITY_KEY), or php cli/admin-user.php mfa-enroll / status --status=active.');
    } elseif (!empty($cfg['admin_password'])) {
        $looksHashed = str_starts_with((string)$cfg['admin_password'], '$2y$')
            || str_starts_with((string)$cfg['admin_password'], '$argon2');
        $add($looksHashed ? 'ok' : 'fail', 'admin_password', $looksHashed ? 'Admin password is configured as a hash.' : 'Admin password is not a recognized password_hash() value.', 'Generate one with: php cli/hash-password.php');
    } else {
        $add('fail', 'admin_password', 'No admin account and no legacy admin_password: nobody can sign in.', 'BBE_ADMIN_PASSWORD=... php cli/admin-user.php create --username=NAME --role=owner');
    }

    $add(!empty($cfg['admin_email']) ? 'ok' : 'warn', 'admin_email', !empty($cfg['admin_email']) ? 'Admin notification email is configured.' : 'Admin notification email is empty.', 'Set admin_email for staging order notifications.');

    try {
        $appUrl = Security::appUrl($cfg);
        $add('ok', 'app_url', "Validated public app_url: {$appUrl}");
    } catch (\RuntimeException $e) {
        $add('fail', 'app_url', 'app_url is missing or invalid.', 'Set an absolute public HTTP(S) app_url; security-sensitive links never use the Host header.');
    }

    if (($cfg['db_driver'] ?? '') === 'sqlite') {
        $dbPath = (string)($cfg['db_sqlite_path'] ?? '');
        $dbDir = dirname($dbPath);
        $sqliteOk = $privatePathOk($dbPath) && is_dir($dbDir) && is_writable($dbDir);
        $add($sqliteOk ? 'ok' : 'fail', 'sqlite_path', "Private SQLite path: {$dbPath}", 'Use a writable absolute db_sqlite_path outside the application/document root.');
    } elseif (($cfg['db_driver'] ?? '') === 'mysql') {
        foreach (['db_host', 'db_name', 'db_user'] as $key) {
            $add(!empty($cfg[$key]) ? 'ok' : 'fail', $key, !empty($cfg[$key]) ? "{$key} configured." : "{$key} missing.");
        }
    } else {
        $add('fail', 'db_driver', 'Unknown db_driver.', 'Use sqlite or mysql.');
    }

    // The only directories of the application the PHP user writes (the Docker sample mounts just these writable).
    foreach (['cache' => (string)($cfg['cache_dir'] ?? $root . '/cache'), 'uploads' => (string)($cfg['uploads_dir'] ?? $root . '/uploads')] as $dir => $path) {
        $add(is_dir($path) && is_writable($path) ? 'ok' : 'fail', "dir:{$dir}", "{$dir}/ " . (is_dir($path) ? 'exists' : 'missing') . (is_writable($path) ? ' and writable' : ' or not writable'), "Create and make {$path} writable by the web/PHP user.");
    }

    $privatePaths = [
        'jobs_dir' => (string)($cfg['jobs_dir'] ?? ''),
        'private_cache_dir' => (string)($cfg['private_cache_dir'] ?? ''),
        'invoice_storage_dir' => (string)($cfg['invoice_storage_dir'] ?? ''),
        'backup_storage_dir' => (string)($cfg['backup_storage_dir'] ?? ''),
        'backup_log_file' => (string)($cfg['backup_log_file'] ?? ''),
        'metrics_log_file' => (string)($cfg['metrics_log_file'] ?? ''),
        'gdpr_salt_file' => (string)($cfg['gdpr_salt_file'] ?? ''),
        // Unset, both fall back to <private_runtime_dir>/... as BackupService and cli/update.php do.
        'backup_recovery_dir' => (string)($cfg['backup_recovery_dir'] ?? '')
            ?: ((string)($cfg['private_runtime_dir'] ?? '') !== '' ? rtrim((string)$cfg['private_runtime_dir'], '/\\') . '/restore-recovery' : ''),
        'update_storage_dir' => (string)($cfg['update_storage_dir']
            ?? ((string)($cfg['private_runtime_dir'] ?? '') !== '' ? rtrim((string)$cfg['private_runtime_dir'], '/\\') . '/updates' : '')),
    ];
    // Only shops selling digital goods configure it; when set, the paid files must not be reachable over HTTP.
    if ((string)($cfg['digital_assets_base_dir'] ?? '') !== '') $privatePaths['digital_assets_base_dir'] = (string)$cfg['digital_assets_base_dir'];
    foreach ($privatePaths as $key => $path) {
        $ok = $path !== '' && $privatePathOk($path);
        $add($ok ? 'ok' : 'fail', $key, $ok ? "{$key} is outside the application and web root." : "{$key} is missing, relative, or inside the application or web root" . ($webRoot !== null ? " {$webRoot}" : '') . '.', "Configure an absolute private {$key} path outside the document root.");
    }

    // Checkout charges through each active method's provider (payment_methods.provider), not provider_defaults.payment.
    try {
        foreach (PaymentReadiness::checks(Db::get(), $cfg, (array)(Bootstrap::stores()['stores'] ?? [])) as [$status, $name, $message, $remediation]) $add($status === 'pass' ? 'ok' : $status, $name, $message, $remediation);
    } catch (Throwable $e) {
        $add('fail', 'payment.methods', 'Payment methods could not be read from the database: ' . $e->getMessage(), 'Fix the database connection and run php cli/migrate.php.');
    }
    foreach (['packeta', 'gls'] as $carrier) {
        $envKey = 'SHIP_' . strtoupper($carrier) . '_WEBHOOK_SECRET';
        $secret = preflightEnv($envKey) ?: (string)($cfg['shipping_webhook_secrets'][$carrier] ?? '');
        $filled = preflightSecretLooksFilled($secret);
        // Unset = this carrier's webhooks are refused (fine when it is not used); a weak or placeholder secret is a real fault.
        $add($filled ? 'ok' : ($secret === '' ? 'warn' : 'fail'), "shipping_webhook_secret:{$carrier}", $filled ? ucfirst($carrier) . ' webhook secret configured.'
            : ($secret === '' ? ucfirst($carrier) . ' webhook secret not set: its tracking webhooks are refused.' : ucfirst($carrier) . ' webhook secret is a placeholder or shorter than 16 characters.'), "Set {$envKey} or shipping_webhook_secrets.{$carrier}.");
    }

    // Settings that silently change behaviour after this upgrade (CHANGELOG "Upgrade notes").
    $insecureWebhooks = UpgradeChecks::insecureHttpWebhooks($cfg);
    $add($insecureWebhooks === [] ? 'ok' : 'warn', 'webhook_http', $insecureWebhooks === [] ? 'No outbound webhook relies on plain http.' : 'Outbound webhooks over plain http are no longer delivered: ' . implode(', ', array_keys($insecureWebhooks)), 'Use https, or allow_insecure_http => true only for a receiver on a trusted local network.');
    $reminderWithoutConsent = UpgradeChecks::cartReminderWithoutConsent($cfg);
    $add($reminderWithoutConsent ? 'warn' : 'ok', 'cart_reminder_consent', $reminderWithoutConsent ? 'cart_reminder is enabled without an active checkout_consents.cart_reminder: no reminder is stored or sent.' : 'Cart reminder consent configuration is consistent.', "Add checkout_consents['cart_reminder'] (version, text, required => false) or disable cart_reminder.");
    $nginxFallback = UpgradeChecks::nginxPhpFallback($cfg);
    if ($nginxFallback !== null) {
        $add($nginxFallback ? 'ok' : 'warn', 'nginx_php_fallback', $nginxFallback ? 'nginx sends missing .php scripts to @redirect.' : 'nginx PHP location lacks "try_files $uri @redirect;": store-prefixed scripts (/cs/account.php, /cs/wishlist.php) answer 404.', 'Copy the location ~ \.php$ block from deploy/nginx.conf into operations.nginx_config and reload nginx.');
    }

    $mailAdapter = Setup::mailProvider($cfg);
    $add($mailAdapter === 'log' ? 'warn' : 'ok', 'mail_adapter', "Mail provider: {$mailAdapter}", $mailAdapter === 'log' ? 'For staging email delivery tests, configure smtp or ses; log is safe but does not send.' : '');

    foreach (['stores.json', 'config/processors.json', 'config/data-retention.json', 'config/backup.json'] as $jsonFile) {
        [$ok, $data] = preflightFileJson($jsonFile === 'stores.json' ? DataOverrides::storesPath($root, $cfg) : $root . '/' . $jsonFile);
        $add($ok ? 'ok' : 'fail', $jsonFile, $ok ? "{$jsonFile} is valid JSON." : "{$jsonFile} missing or invalid JSON.");
        if ($jsonFile === 'stores.json' && $ok) {
            $encoded = strtolower(json_encode($data));
            $hasExample = str_contains($encoded, 'example.') || str_contains($encoded, '12345678') || str_contains($encoded, 'woodle');
            $add($hasExample ? 'warn' : 'ok', 'legal_placeholders', $hasExample ? 'Store legal/contact data appears to contain demo/example values.' : 'Store legal/contact data does not contain obvious demo markers.', 'Replace stores.json legal blocks with staging/real merchant values before production.');
            $numbering = InvoiceService::numberingConflicts($data);
            $add($numbering === [] ? 'ok' : 'fail', 'invoice_numbering', $numbering === [] ? 'Every seller has its own invoice and credit-note number series.'
                : 'Invoice numbering conflicts (refused at issue time): ' . implode(' | ', array_map(static fn(string $series, string $message): string => "{$series}: {$message}", array_keys($numbering), $numbering)),
                'Give each store with its own legal block its own legal.invoice_prefix / legal.credit_note_prefix, or set legal.seller to the store whose series a storefront of the same company shares.');
        }
    }

    $migrationDir = $root . '/migrations';
    $migrationCount = is_dir($migrationDir) ? count(glob($migrationDir . '/*.php') ?: []) : 0;
    $add($migrationCount > 0 ? 'ok' : 'fail', 'migrations', "Migration files found: {$migrationCount}", 'Run php cli/migrate.php status/apply on staging.');
    require_once __DIR__ . '/theme-check.php'; $themeContract = runThemeCompatibility(); $add($themeContract['status'] === 'GO' ? 'ok' : 'fail', 'theme_checkout_contract', $themeContract['checks'] . ' checks; ' . implode("\n", $themeContract['failures']), 'Run php cli/theme-check.php on the candidate tree with the installed themes and store block overrides before deploying.');
    $recommended = [
        'php cli/migrate.php status',
        'php cli/migrate.php',
        'php cli/cache-warm.php',
        'php cli/test.php',
        'php cli/gdpr-gc.php --dry-run',
    ];

    $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
    foreach ($checks as $check) {
        $counts[$check['level']] = ($counts[$check['level']] ?? 0) + 1;
    }

    return [
        'status' => $counts['fail'] > 0 ? 'NO-GO' : 'GO',
        'counts' => $counts,
        'checks' => $checks,
        'recommended_commands' => $recommended,
    ];
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $args = $argv ?? [];
    $json = in_array('--json', $args, true);
    $strict = in_array('--strict', $args, true);
    $result = runStagingPreflight($args);

    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo "BareBones Shop — Staging Deploy Preflight\n";
        echo "=========================================\n\n";
        foreach ($result['checks'] as $check) {
            $label = strtoupper($check['level']);
            echo "[{$label}] {$check['name']}: {$check['message']}\n";
            if ($check['remediation'] !== '') echo "      -> {$check['remediation']}\n";
        }
        echo "\nRecommended staging commands:\n";
        foreach ($result['recommended_commands'] as $cmd) echo "  - {$cmd}\n";
        echo "\nResult: {$result['status']} ({$result['counts']['ok']} ok, {$result['counts']['warn']} warn, {$result['counts']['fail']} fail)\n";
    }

    exit(($strict && $result['status'] !== 'GO') ? 1 : 0);
}
