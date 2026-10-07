<?php
require_once __DIR__ . '/cli-only.php';

/**
 * Core drift guard — detects hand edits of core files made directly on a server.
 *
 * Usage:
 *   php cli/drift-guard.php record [--reason=TEXT]     accept current core files as the baseline
 *   php cli/drift-guard.php check  [--json] [--notify] compare core files with the baseline
 *
 * Exit codes: 0 clean, 2 drift found, 3 no baseline recorded yet, 1 error.
 * Daily cron example: 15 6 * * * php /path/cli/drift-guard.php check --notify
 * The baseline is re-recorded automatically after `cli/update.php apply|rollback`.
 * Rule: a fix goes to BareBones first and reaches the server only through an update.
 */
require_once __DIR__ . '/../src/autoload.php';

$args = array_slice($argv ?? [], 1);
$command = $args[0] ?? 'help';
$options = [];
foreach (array_slice($args, 1) as $argument) {
    if (in_array($argument, ['--json', '--notify'], true)) {
        $options[substr($argument, 2)] = true;
    } elseif (str_starts_with($argument, '--reason=')) {
        $options['reason'] = substr($argument, 9);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(1);
    }
}

try {
    $config = require dirname(__DIR__) . '/config.php';
    $guard = driftGuardFromConfig(is_array($config) ? $config : []);

    if ($command === 'record') {
        $result = $guard->record((string)($options['reason'] ?? 'manual'), driftGuardVersion());
        echo "Core baseline recorded: {$result['files']} file(s) -> {$result['manifest']}\n";
        exit(0);
    }
    if ($command !== 'check') {
        echo "Usage: php cli/drift-guard.php record [--reason=TEXT] | check [--json] [--notify]\n";
        exit($command === 'help' ? 0 : 1);
    }

    $report = $guard->check();
    if (!empty($options['json'])) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo "Core drift guard: {$report['status']}\n";
        foreach (['modified', 'added', 'missing'] as $kind) {
            foreach ($report[$kind] as $path) echo "  {$kind}: {$path}\n";
        }
        if ($report['status'] === 'no_manifest') echo "  No baseline yet. Run: php cli/drift-guard.php record\n";
    }
    if ($report['status'] === 'drift' && !empty($options['notify'])) {
        $sent = driftGuardNotify($config, $report);
        if (empty($options['json'])) echo "  Alert e-mails sent: {$sent}\n";
    }
    exit(match ($report['status']) { 'clean' => 0, 'drift' => 2, default => 3 });
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

function driftGuardFromConfig(array $config): CoreIntegrity
{
    $root = dirname(__DIR__);
    $privateRoot = (string)($config['private_runtime_dir'] ?? dirname($root) . '/bareboneseshop-private');
    $stateDirectory = (string)($config['update_storage_dir'] ?? rtrim($privateRoot, '/\\') . '/updates');
    return new CoreIntegrity($root, $stateDirectory, (array)($config['drift_guard'] ?? []));
}

function driftGuardVersion(): ?string
{
    $raw = @file_get_contents(dirname(__DIR__) . '/.barebones-version.json');
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : null;
}

function driftGuardNotify(array $config, array $report): int
{
    $guardCfg = (array)($config['drift_guard'] ?? []);
    $recipients = (array)($guardCfg['recipients'] ?? []);
    if (!$recipients && !empty($config['admin_email'])) $recipients = [(string)$config['admin_email']];
    $host = (string)(parse_url((string)($config['app_url'] ?? ''), PHP_URL_HOST) ?: gethostname());
    $files = [];
    foreach (['modified', 'added', 'missing'] as $kind) {
        foreach ($report[$kind] as $path) $files[] = ['kind' => $kind, 'path' => $path];
    }
    $sent = 0;
    foreach (array_unique(array_filter($recipients, static fn($email): bool => (bool)filter_var($email, FILTER_VALIDATE_EMAIL))) as $recipient) {
        $result = MailService::send('core_drift_alert', $recipient, '', [
            'host' => $host,
            'count' => count($files),
            'recorded_at' => (string)($report['recorded_at'] ?? ''),
            'files' => array_slice($files, 0, 200),
        ]);
        if (($result['status'] ?? '') === 'sent') $sent++;
    }
    return $sent;
}
