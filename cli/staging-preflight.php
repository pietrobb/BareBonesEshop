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
    $privatePathOk = static function (string $path) use ($root): bool {
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
        return $candidate !== $app && !str_starts_with($candidate, $app . '/');
    };

    $add('info', 'mode', 'Preflight only: no deploy, no upload, no destructive action.');

    if (is_file($root . '/.env')) {
        $add('ok', '.env', '.env exists locally and is ignored by git.');
    } else {
        $add('warn', '.env', '.env not found.', 'Create it from .env.example or provide equivalent process environment variables on staging.');
    }

    if (!empty($cfg['admin_password'])) {
        $looksHashed = str_starts_with((string)$cfg['admin_password'], '$2y$')
            || str_starts_with((string)$cfg['admin_password'], '$argon2');
        $add($looksHashed ? 'ok' : 'fail', 'admin_password', $looksHashed ? 'Admin password is configured as a hash.' : 'Admin password is not a recognized password_hash() value.', 'Generate one with: php cli/hash-password.php');
    } else {
        $add('fail', 'admin_password', 'Admin password is empty.', 'Generate one with: php cli/hash-password.php and set config.php/deploy config.');
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

    foreach (['cache', 'storage', 'uploads'] as $dir) {
        $path = $root . '/' . $dir;
        $add(is_dir($path) && is_writable($path) ? 'ok' : 'fail', "dir:{$dir}", "{$dir}/ " . (is_dir($path) ? 'exists' : 'missing') . (is_writable($path) ? ' and writable' : ' or not writable'), "Create and make {$dir}/ writable by the web/PHP user.");
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
    foreach ($privatePaths as $key => $path) {
        $ok = $path !== '' && $privatePathOk($path);
        $add($ok ? 'ok' : 'fail', $key, $ok ? "{$key} is outside the application root." : "{$key} is missing, relative, or inside the application root.", "Configure an absolute private {$key} path outside the document root.");
    }

    $paymentAdapter = (string)($cfg['adapters']['payment'] ?? '');
    $add($paymentAdapter !== '' ? 'ok' : 'fail', 'payment_adapter', $paymentAdapter !== '' ? "Payment adapter: {$paymentAdapter}" : 'Payment adapter is empty.');
    if ($paymentAdapter === 'StripePayment') {
        $stripeSk = (string)($cfg['stripe_secret_key'] ?? '');
        $stripePk = (string)($cfg['stripe_publishable_key'] ?? '');
        $stripeWh = (string)($cfg['stripe_webhook_secret'] ?? '');
        $add(preflightSecretLooksFilled($stripeSk) ? 'ok' : 'fail', 'stripe_secret_key', preflightSecretLooksFilled($stripeSk) ? 'Stripe secret key configured.' : 'Stripe secret key missing/placeholder.', 'Use Stripe test secret key for staging.');
        $add(preflightSecretLooksFilled($stripePk) ? 'ok' : 'warn', 'stripe_publishable_key', preflightSecretLooksFilled($stripePk) ? 'Stripe publishable key configured.' : 'Stripe publishable key missing/placeholder.', 'Set Stripe test publishable key if checkout UI needs it.');
        $add(preflightSecretLooksFilled($stripeWh) ? 'ok' : 'fail', 'stripe_webhook_secret', preflightSecretLooksFilled($stripeWh) ? 'Stripe webhook secret configured.' : 'Stripe webhook secret missing/placeholder.', 'Set webhook secret for /api.php?action=webhook_stripe or generic payment webhook.');
    }
    foreach (['packeta', 'gls'] as $carrier) {
        $envKey = 'SHIP_' . strtoupper($carrier) . '_WEBHOOK_SECRET';
        $secret = preflightEnv($envKey) ?: (string)($cfg['shipping_webhook_secrets'][$carrier] ?? '');
        $filled = preflightSecretLooksFilled($secret);
        $add($filled ? 'ok' : 'fail', "shipping_webhook_secret:{$carrier}", $filled ? ucfirst($carrier) . ' webhook secret configured.' : ucfirst($carrier) . ' webhook secret missing/placeholder.', "Set {$envKey} or shipping_webhook_secrets.{$carrier}.");
    }

    $mailAdapter = strtolower(preflightEnv('MAIL_ADAPTER') ?: 'log');
    $add($mailAdapter === 'log' ? 'warn' : 'ok', 'mail_adapter', "MAIL_ADAPTER={$mailAdapter}", $mailAdapter === 'log' ? 'For staging email delivery tests, configure smtp or ses; log is safe but does not send.' : '');

    foreach (['stores.json', 'config/processors.json', 'config/data-retention.json', 'config/backup.json'] as $jsonFile) {
        [$ok, $data] = preflightFileJson($root . '/' . $jsonFile);
        $add($ok ? 'ok' : 'fail', $jsonFile, $ok ? "{$jsonFile} is valid JSON." : "{$jsonFile} missing or invalid JSON.");
        if ($jsonFile === 'stores.json' && $ok) {
            $encoded = strtolower(json_encode($data));
            $hasExample = str_contains($encoded, 'example.') || str_contains($encoded, '12345678') || str_contains($encoded, 'woodle');
            $add($hasExample ? 'warn' : 'ok', 'legal_placeholders', $hasExample ? 'Store legal/contact data appears to contain demo/example values.' : 'Store legal/contact data does not contain obvious demo markers.', 'Replace stores.json legal blocks with staging/real merchant values before production.');
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
