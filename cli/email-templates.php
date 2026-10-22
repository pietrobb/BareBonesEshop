<?php
require_once __DIR__ . '/cli-only.php';

/**
 * Move e-mail templates a v2.0.0 shop customised in templates/emails/ into store overrides (admin → E-mail templates).
 *
 * Usage:
 *   php cli/email-templates.php import --custom=DIR --base=DIR [--store=ID] [--dry-run] [--json]
 *     --custom  copy of the shop's templates/emails/ taken before the update
 *     --base    templates/emails/ of the untouched release the shop ran (e.g. from git archive v2.0.0)
 * Exit 0 when nothing was refused, 1 otherwise.
 */
require_once __DIR__ . '/../src/autoload.php';

$argv = $_SERVER['argv'] ?? [];
$command = strtolower((string)($argv[1] ?? ''));
$options = cli_long_options($argv, ['custom:', 'base:', 'store:', 'dry-run', 'json']);

try {
    if ($command !== 'import' || !isset($options['custom'], $options['base'])) {
        throw new InvalidArgumentException('Usage: php cli/email-templates.php import --custom=DIR --base=DIR [--store=ID] [--dry-run] [--json]');
    }
    foreach (['custom', 'base'] as $name) {
        if (!is_dir((string)$options[$name])) throw new InvalidArgumentException("--{$name} must be a templates/emails directory");
    }
    if (realpath((string)$options['custom']) === realpath(dirname(__DIR__) . '/templates/emails')) {
        throw new InvalidArgumentException('--custom must be a copy taken before the update, not the installed templates/emails');
    }
    Bootstrap::initStoreContext(null, null, (string)($options['store'] ?? 'default'));
    $_SESSION['admin_user'] = 'cli:email-templates';
    $rows = EmailTemplates::importCustomised((string)$options['custom'], (string)$options['base'], array_key_exists('dry-run', $options));
    if (array_key_exists('json', $options)) {
        echo json_encode(['store' => StoreContext::current()->id(), 'templates' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    } else {
        foreach ($rows as $row) {
            echo '[' . strtoupper($row['status']) . "] {$row['template']} / {$row['locale']} (" . implode(', ', $row['parts']) . ')'
                . ($row['message'] !== '' ? ": {$row['message']}" : '') . "\n";
        }
        echo count($rows) . " customised template(s).\n";
    }
    exit(in_array('refused', array_column($rows, 'status'), true) ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(2);
}
