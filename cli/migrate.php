<?php
/**
 * BareBones Shop — Database Migration CLI
 *
 * Usage:
 *   php cli/migrate.php                 Apply all pending migrations
 *   php cli/migrate.php status          Show applied / pending / missing
 *   php cli/migrate.php create <name>   Generate stub migration file
 *   php cli/migrate.php baseline        Mark all current files as applied (no-run)
 *
 * Forward-only. No down() / rollback — write a new migration to revert.
 */
require_once __DIR__ . '/../src/autoload.php';

$argvLocal = $argv ?? [];
$cmd       = $argvLocal[1] ?? 'apply';

$migrator = new Migrator();

$logger = static function (string $msg): void {
    echo $msg . "\n";
};

try {
    switch ($cmd) {
        case 'apply':
            echo "Applying pending migrations...\n";
            $applied = $migrator->apply($logger);
            $n = count($applied);
            echo $n === 0 ? "Nothing to apply.\n" : "Applied {$n} migration(s).\n";
            break;

        case 'status':
            $s = $migrator->status();
            echo "Applied:\n";
            if (!$s['applied']) {
                echo "  (none)\n";
            }
            foreach ($s['applied'] as $name => $info) {
                $flag = $info['checksum_ok'] === true  ? 'ok'
                      : ($info['checksum_ok'] === null ? 'missing' : 'CHECKSUM-FAIL');
                echo "  [{$flag}] {$name}  ({$info['applied_at']})\n";
            }
            echo "\nPending:\n";
            if (!$s['pending']) {
                echo "  (none)\n";
            }
            foreach ($s['pending'] as $name) {
                echo "  - {$name}\n";
            }
            if (!empty($s['missing'])) {
                echo "\nMissing files for applied migrations:\n";
                foreach ($s['missing'] as $name) {
                    echo "  ! {$name}\n";
                }
            }
            break;

        case 'create':
            $name = $argvLocal[2] ?? '';
            if ($name === '') {
                fwrite(STDERR, "Usage: php cli/migrate.php create <name>\n");
                exit(2);
            }
            $path = $migrator->create($name);
            echo "Created: {$path}\n";
            break;

        case 'baseline':
            echo "Baselining existing migrations...\n";
            $marked = $migrator->baseline($logger);
            $n = count($marked);
            echo $n === 0 ? "Nothing to baseline.\n" : "Marked {$n} migration(s) as applied.\n";
            break;

        case '-h':
        case '--help':
        case 'help':
            echo "Usage:\n";
            echo "  php cli/migrate.php                 Apply all pending migrations\n";
            echo "  php cli/migrate.php status          Show applied / pending / missing\n";
            echo "  php cli/migrate.php create <name>   Generate stub migration file\n";
            echo "  php cli/migrate.php baseline        Mark current files as applied (no run)\n";
            break;

        default:
            fwrite(STDERR, "Unknown command: {$cmd}\n");
            fwrite(STDERR, "Try: php cli/migrate.php help\n");
            exit(2);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
