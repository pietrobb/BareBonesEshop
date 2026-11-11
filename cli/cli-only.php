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
 * application root can do everything the step does. Root stays root only for a tree that root owns and in which no other
 * account can change anything (cli_not_root_only()); otherwise the step is refused. cli/test.php keeps root (BBE_CLI_KEEP_ROOT) for the
 * scenarios that test the root paths.
 * R28 (finding 1): this file is itself in the tree; an account that can write the tree can change it and root runs the
 * copy. It is a second line of defence only: root must never run PHP of such a tree (deploy/bbe-step.sh switches outside).
 */
function cli_drop_root(string $root): void
{
    $euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    if ($euid === null && is_readable('/proc/self/status') && preg_match('/^Uid:\s+\d+\s+(\d+)/m', (string)@file_get_contents('/proc/self/status'), $m) === 1) {
        $euid = (int)$m[1];
    }
    if ($euid !== 0) return;
    $refuse = static function (string $why, bool $owner = true): never {
        fwrite(STDERR, "REFUSED: this step runs as root, but {$why}." . ($owner ? ' Run it as the owner of the application root'
            . ' (e.g. sudo -u <owner> php ' . ($_SERVER['argv'][0] ?? 'cli/<step>.php') . ' …).' : '') . "\n");
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
        // R28 (low): a tree of group root (www-data:root) handed the step gid 0 (it read /etc/sudoers): take the owner's
        // primary group instead, and refuse when that is root's too.
        if ($gid === 0) $gid = is_array($user) ? (int)$user['gid'] : 0;
        if ($gid === 0) $refuse("the application root and its owner ({$name}) have group root, which the step would keep; chgrp the application root");
        // initgroups first: root's supplementary groups (0 among them) must not stay with the step.
        if (!@posix_initgroups($name, $gid) || !@posix_setgid($gid) || !@posix_setuid($uid)) {
            $refuse("it cannot switch to the owner of the application root ({$name}, uid {$uid}, gid {$gid})");
        }
        $groups = posix_getgroups();
        if (posix_getuid() !== $uid || posix_geteuid() !== $uid || @posix_seteuid(0) || posix_getgid() === 0 || posix_getegid() === 0
            || in_array(0, is_array($groups) ? $groups : [0], true)) {
            $refuse('root rights could not be given up for good');
        }
        if (is_array($user)) putenv('HOME=' . $user['dir']); // mysqldump & co. read the owner's ~/.my.cnf, not root's
        fwrite(STDERR, "NOTICE: started as root; this step runs as {$name} (uid {$uid}, gid {$gid}), the owner of the application root.\n");
        return;
    }
    $why = cli_not_root_only($root);
    // R29: say how to make a tree root owns root's alone: a release extracted by root keeps the archive's 0664/0775.
    if ($why !== null) $refuse($why . ', and root would run code another account can change. For a tree only root may change:'
        . " chmod -R go-w {$root} (extract a release as root with tar --no-same-permissions); otherwise chown it to the account the step runs as", false);
}

/**
 * Why root may not run the code of a root-owned application root, or null. Capability review (run 390): checking the app
 * root, src/ and cli/ left config.php (the web installer writes it), adapters/, vendor/, lang/ and every file in them to
 * whoever owned them, and root ran them. Root keeps its rights only when no other account can change anything it may
 * load: every directory above the app root, and every entry in it (lstat, links not followed), belongs to root and only
 * root can write it (no group or other write bit); a link must stay inside the tree, whose walk checks its target.
 * Used for the update state directory too (Updater, R28 finding 5): root installs its snapshots' files.
 */
function cli_not_root_only(string $root): ?string
{
    // R28 (low): group write counts even for group root. A POSIX ACL (setfacl u:www-data:rwx) shows only as the group
    // bits (its mask) of a root:root entry, and passed as "root's group"; root's group may have other members anyway.
    $foreign = static fn(array $s): bool => (int)$s['uid'] !== 0 || ((int)$s['mode'] & 0022) !== 0;
    $describe = static fn(string $path, array $s): string => sprintf('%s (owner %d, group %d, mode %04o) is writable by another account',
        $path, $s['uid'], $s['gid'], (int)$s['mode'] & 07777);
    $real = realpath($root);
    if ($real === false) return "the application root {$root} cannot be resolved";
    // R29 (review round 29, finding 1): 2.1.3 made the step lock 0660 root:root in a tree of group root (umask 0007), and
    // its group write refused every later root step. Root never loads the lock (MaintenanceMode only flocks it and refuses
    // a link, a hard link or foreign content), so a root-owned regular file there with group write is no way into root.
    $stepLock = static fn(string $path, array $s): bool => ($path === $real . '/.maintenance.lock' || $path === $real . '/.maintenance.lock.held')
        && (int)$s['uid'] === 0 && ((int)$s['mode'] & 0170002) === 0100000 && (int)$s['nlink'] === 1;
    // Above the app root: a directory another account can write could swap the whole tree, unless its sticky bit keeps
    // others from renaming root's entries.
    for ($dir = dirname($real); ; $dir = dirname($dir)) {
        $s = @stat($dir);
        if (!is_array($s)) return "{$dir} cannot be read";
        if ((int)$s['uid'] !== 0 || ($foreign($s) && ((int)$s['mode'] & 01000) === 0)) return $describe($dir, $s);
        if ($dir === dirname($dir)) break;
    }
    $pending = [$real];
    while ($pending !== []) {
        $path = array_pop($pending);
        $s = @lstat($path);
        if (!is_array($s)) return "{$path} cannot be read";
        if (is_link($path)) {
            $target = realpath($path);
            if ((int)$s['uid'] !== 0 || $target === false || ($target !== $real && !str_starts_with($target, $real . '/'))) {
                return "{$path} is a symbolic link out of the application root (" . ($target === false ? 'dangling' : $target) . ')';
            }
            continue;
        }
        if ($foreign($s) && !$stepLock($path, $s)) return $describe($path, $s);
        if (is_dir($path)) {
            $entries = @scandir($path);
            if ($entries === false) return "{$path} cannot be listed";
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') $pending[] = $path . '/' . $entry;
            }
        }
    }
    return null;
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
