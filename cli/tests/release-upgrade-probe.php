<?php
// cli/test-release-upgrade.php --record-preloaded runs the installed release's updater with this prepended: every class
// autoloaded is logged with its file and whether the swap had happened yet (BBE_UPGRADE_PROBE_MARKER, a migration only
// the new release has, exists from then on). Classes loaded "before" keep the old release's code for the whole update.
(static function (): void {
    $log = (string)getenv('BBE_UPGRADE_PROBE_LOG');
    $marker = (string)getenv('BBE_UPGRADE_PROBE_MARKER');
    if ($log === '' || $marker === '') return;
    $root = (string)getcwd();
    spl_autoload_register(static function (string $class) use ($log, $marker, $root): void {
        $file = '';
        if (is_file($root . '/src/' . $class . '.php')) {
            $file = 'src/' . $class . '.php';
        } else {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $candidate) {
                if ($candidate->getFilename() === $class . '.php') {
                    $file = substr($candidate->getPathname(), strlen($root) + 1);
                    break;
                }
            }
        }
        clearstatcache(true, $marker);
        file_put_contents($log, $class . "\t" . $file . "\t" . (is_file($marker) ? 'after' : 'before') . "\n", FILE_APPEND);
    }, true, true);
})();
