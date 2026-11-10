<?php

// BBE_WEB_WORKER: src/WebWorker.php runs cli/worker.php after a response on hosting without cron.
if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true) && !defined('BBE_WEB_WORKER')) {
    http_response_code(403);
    exit(1);
}

/**
 * R27 (review round 27, finding 1): a CLI step started as root runs as the owner of the application root instead, before
 * any code of src/ is loaded. Root steps worked in a tree the web user can write: it planted a symbolic link where root
 * chmodded a temporary file (DataOverrides::atomicWrite) and gave itself /etc/shadow, or replaced src/ and root ran its
 * code. Patching each file root touched left the next one open, so root keeps no rights a step needs: the owner of the
 * application root can do everything the step does. Root stays root only for a tree that root owns and no other account
 * can write (app root, src/, cli/); otherwise the step is refused. cli/test.php keeps root (BBE_CLI_KEEP_ROOT) for the
 * scenarios that test the root paths.
 */
function cli_drop_root(string $root): void
{
    $euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    if ($euid === null && is_readable('/proc/self/status') && preg_match('/^Uid:\s+\d+\s+(\d+)/m', (string)@file_get_contents('/proc/self/status'), $m) === 1) {
        $euid = (int)$m[1];
    }
    if ($euid !== 0) return;
    $refuse = static function (string $why): never {
        fwrite(STDERR, "REFUSED: this step runs as root, but {$why}. Run it as the owner of the application root"
            . " (e.g. sudo -u <owner> php " . ($_SERVER['argv'][0] ?? 'cli/<step>.php') . " …).\n");
        exit(1);
    };
    if (!function_exists('posix_setuid')) $refuse('PHP has no posix extension to switch to the owner of the application root');
    clearstatcache();
    $app = @stat($root);
    if (!is_array($app)) $refuse("the application root {$root} cannot be read");
    [$uid, $gid] = [(int)$app['uid'], (int)$app['gid']];
    if ($uid !== 0) {
        $user = posix_getpwuid($uid);
        $name = is_array($user) ? (string)$user['name'] : (string)$uid;
        // initgroups first: root's supplementary groups (0 among them) must not stay with the step.
        if (!@posix_initgroups($name, $gid) || !@posix_setgid($gid) || !@posix_setuid($uid)) {
            $refuse("it cannot switch to the owner of the application root ({$name}, uid {$uid}, gid {$gid})");
        }
        $groups = posix_getgroups();
        if (posix_getuid() !== $uid || posix_geteuid() !== $uid || @posix_seteuid(0) || ($gid !== 0 && in_array(0, is_array($groups) ? $groups : [0], true))) {
            $refuse('root rights could not be given up for good');
        }
        if (is_array($user)) putenv('HOME=' . $user['dir']); // mysqldump & co. read the owner's ~/.my.cnf, not root's
        fwrite(STDERR, "NOTICE: started as root; this step runs as {$name} (uid {$uid}, gid {$gid}), the owner of the application root.\n");
        return;
    }
    foreach (['', '/src', '/cli'] as $dir) {
        $stat = @stat($root . $dir);
        if (!is_array($stat)) continue;
        $mode = (int)$stat['mode'];
        if ((int)$stat['uid'] !== 0 || ($mode & 0002) !== 0 || (($mode & 0020) !== 0 && (int)$stat['gid'] !== 0)) {
            $refuse(sprintf('%s (owner %d, group %d, mode %04o) is writable by another account, and root would run the code it holds',
                $root . ($dir === '' ? '' : $dir), $stat['uid'], $stat['gid'], $mode & 07777));
        }
    }
}

if (PHP_OS_FAMILY !== 'Windows' && !defined('BBE_WEB_WORKER') && !defined('BBE_CLI_KEEP_ROOT')) cli_drop_root(dirname(__DIR__));

/**
 * getopt() for "script.php <command> --option=value": PHP's getopt() stops at the first
 * non-option argument, so options after a subcommand were silently dropped.
 * Spec as getopt long options: 'name:' required value, 'name::' optional (=value), 'name' flag.
 */
function cli_long_options(array $argv, array $spec): array
{
    $kinds = [];
    foreach ($spec as $entry) {
        $name = rtrim($entry, ':');
        $kinds[$name] = strlen($entry) - strlen($name);
    }
    $options = [];
    $args = array_values(array_slice($argv, 1));
    for ($i = 0; $i < count($args); $i++) {
        if ($args[$i] === '--') break;
        if (!preg_match('/^--([A-Za-z0-9][A-Za-z0-9_-]*)(?:=(.*))?$/s', $args[$i], $m) || !isset($kinds[$m[1]])) continue;
        $hasValue = array_key_exists(2, $m);
        if ($kinds[$m[1]] === 0) {
            if (!$hasValue) $options[$m[1]] = false;
        } elseif ($hasValue) {
            $options[$m[1]] = $m[2];
        } elseif ($kinds[$m[1]] === 1 && isset($args[$i + 1])) {
            $options[$m[1]] = $args[++$i];
        } elseif ($kinds[$m[1]] === 2) {
            $options[$m[1]] = false;
        }
    }
    return $options;
}
