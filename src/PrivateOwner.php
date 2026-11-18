<?php
declare(strict_types=1);

/**
 * R42 (review round 16, findings 1, 2 and 7): root worked in the private runtime directory, which the web server user
 * owns. Four rounds patched each path root opened there (the SQLite database, its -wal/-shm, a backup's directories) and
 * the next one stayed open: the migrations lock followed a link the web user put there and created /etc/nologin, and the
 * database directory swapped for a link between root's check and its open made root connect to a database only root may
 * read (and migrate.php write schema_migrations into it). A check of a path before root opens it cannot hold against
 * the account that owns the directory. Instead, root never touches the private directory as root: the kernel checks
 * every open, create and link there with the rights of the directory's owner.
 *
 * - Every step started as root in a tree root owns alone (cli/cli-only.php) switches for good to the owner of the private
 *   directories, before it loads src/, unless they are root's alone as well.
 * - cli/update.php, cli/migrate.php and cli/restore.php write the tree (an update's files, the maintenance window in the
 *   application root). They keep root for it, and do all they do in the private directory (the SQLite database, its
 *   snapshot and rollback, the migrations and their lock, backups, the log) through run(): with the effective user,
 *   group and groups of the owner, root's back afterwards. Db::get() refuses root outside run().
 */
final class PrivateOwner
{
    /** @var array{uid: int, gid: int, name: string, dir: string}|null owner recorded for a step that keeps root */
    private static ?array $owner = null;

    /**
     * The private directories a step works in, from config.php: the private runtime directory, the SQLite database's,
     * and those that default to below the runtime directory. The update directory is not one: it must be root's alone
     * when root updates (Updater).
     * @return list<string>
     */
    public static function directories(array $config, string $root): array
    {
        $dirs = [rtrim((string)($config['private_runtime_dir'] ?? dirname($root) . '/bareboneseshop-private'), '/')];
        if (strtolower(trim((string)($config['db_driver'] ?? ''))) === 'sqlite' && is_string($config['db_sqlite_path'] ?? null)) {
            $dirs[] = dirname((string)$config['db_sqlite_path']);
        }
        foreach (['jobs_dir', 'private_cache_dir', 'invoice_storage_dir', 'backup_storage_dir', 'backup_recovery_dir', 'uploads_dir', 'digital_assets_base_dir'] as $key) {
            if (is_string($config[$key] ?? null)) $dirs[] = rtrim((string)$config[$key], '/');
        }
        return array_values(array_unique(array_filter($dirs, static fn(string $dir): bool => str_starts_with($dir, '/') && !str_contains($dir, "\0"))));
    }

    /**
     * Whose the private directories $dirs are, for a step root runs: null when they are root's alone (no other account
     * can change them or any directory above them), the one account that owns them, or why root can run as neither.
     * A directory not created yet counts by the nearest one above it that exists.
     * @param list<string> $dirs
     * @return array{uid: int, gid: int, name: string, dir: string}|string|null
     */
    public static function find(array $dirs): array|string|null
    {
        $owners = [];
        foreach ($dirs as $dir) {
            $walk = self::walk($dir);
            if (is_string($walk)) return $walk;
            ['stat' => $s, 'path' => $real, 'foreign' => $foreign, 'links' => $links] = $walk;
            $uid = (int)$s['uid'];
            // The private directory itself: root's must be root's alone; another owner's is the owner's to write.
            if ($uid !== 0) $foreign[] = [$real, $uid];
            elseif (((int)$s['mode'] & 0022) !== 0) $foreign[] = [$real, 0];
            // A link on the configured path is followed by whoever opens it: it must be root's or the owner's.
            foreach ($links as [$d, $u]) {
                if ($u !== 0 && $u !== $uid) return "{$d} on the way to the private directory is a link of another account (uid {$u})";
            }
            if ($foreign === []) continue;
            if ($uid === 0) {
                return "the private directory {$real} belongs to root, but {$foreign[0][0]} can be changed by another account;"
                    . ' give the private directory to the web server user (chown) or make it and every directory above it root\'s alone';
            }
            foreach ($foreign as [$d, $u]) {
                if ($u === -1) return "{$d} on the way to the private directory {$real} can be changed by any account (group or other write without the sticky bit)";
                if ($u !== 0 && $u !== $uid) return "{$d} on the way to the private directory {$real} can be changed by another account (uid {$u}) than the directory's (uid {$uid})";
            }
            $owners[$uid] ??= ['dir' => $real, 'gid' => (int)$s['gid']];
        }
        if ($owners === []) return null;
        if (count($owners) > 1) {
            return 'the private directories belong to different accounts (' . implode(', ', array_map(
                static fn(int $uid, array $o): string => "{$o['dir']}: uid {$uid}", array_keys($owners), $owners)) . ')';
        }
        $uid = (int)array_key_first($owners);
        $user = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
        if (!is_array($user)) return "the owner (uid {$uid}) of the private directory {$owners[$uid]['dir']} has no account in the user database";
        // As for the application root (R28): never group root; the owner's primary group instead.
        $gid = $owners[$uid]['gid'] !== 0 ? $owners[$uid]['gid'] : (int)$user['gid'];
        if ($gid === 0) return "the private directory {$owners[$uid]['dir']} and its owner ({$user['name']}) have group root; chgrp the private directory";
        return ['uid' => $uid, 'gid' => $gid, 'name' => (string)$user['name'], 'dir' => $owners[$uid]['dir']];
    }

    /**
     * R44 (review round 16, finding 1): the owner came from realpath() and stat(), which follow links, and the links on
     * the way from a later, separate lstat() walk. Where the web user can change a directory on the way (a parent it
     * owns, or /tmp), it swapped the private directory and a link to root's (or another account's) directory between
     * the two: find() said "root's alone" and migrate.php stayed root (or switched to the wrong account). Now one walk
     * decides both, directory by directory, the way openat() would: each step lstat()s the next name in the directory
     * the process stands in, chdir()s into it and stat()s "." — the directory it now stands in, whatever the name points
     * to by then — and refuses when that is not the inode lstat() saw. What it reports is the inodes it stood in.
     *
     * @return array{stat: array<int|string, int>, path: string, foreign: list<array{0: string, 1: int}>, links: list<array{0: string, 1: int}>}|string
     *   foreign: who besides root can change a name on the way ([directory, uid]; uid -1: any account), links: [link, its owner]
     */
    private static function walk(string $dir): array|string
    {
        // A thread-safe build keeps a virtual working directory and opens relative names by their full path again.
        if (PHP_ZTS) return 'root steps need a PHP build without thread safety (ZTS) to check the private directory';
        $cwd = getcwd();
        $foreign = [];
        $links = [];
        try {
            $here = self::enter('/');
            if ($here === false) return 'the root directory cannot be read';
            $path = '/';
            $names = explode('/', $dir);
            $hops = 0;
            while ($names !== []) {
                $name = array_shift($names);
                if ($name === '' || $name === '.') continue;
                $entry = ($path === '/' ? '' : $path) . '/' . $name;
                clearstatcache();
                $l = @lstat($name);
                if (!is_array($l)) break; // not created yet: the nearest directory above it counts
                // Who can rename or replace this name: the directory's owner; with group or other write anyone, unless
                // the sticky bit limits that to the owner of the entry.
                if ((int)$here['uid'] !== 0) $foreign[] = [$path, (int)$here['uid']];
                if (((int)$here['mode'] & 0022) !== 0) {
                    if (((int)$here['mode'] & 01000) === 0) $foreign[] = [$path, -1];
                    elseif ((int)$l['uid'] !== 0) $foreign[] = [$entry, (int)$l['uid']];
                }
                if (((int)$l['mode'] & 0170000) === 0120000) {
                    if (!array_filter($names, static fn(string $n): bool => $n !== '' && $n !== '.')) {
                        return "the private directory {$dir} is a link ({$entry}); configure the directory it points to";
                    }
                    $target = @readlink($name);
                    if (!is_string($target) || ++$hops > 40) return "the link {$entry} on the way to the private directory {$dir} cannot be followed";
                    $links[] = [$entry, (int)$l['uid']];
                    if (str_starts_with($target, '/')) {
                        $here = self::enter('/');
                        if ($here === false) return 'the root directory cannot be read';
                        $path = '/';
                    }
                    $names = array_merge(explode('/', $target), $names);
                    continue;
                }
                if (((int)$l['mode'] & 0170000) !== 0040000) return "the private directory {$dir} cannot be resolved to a directory ({$entry} is not one)";
                $s = self::enter($name);
                if ($s === false || $s['dev'] !== $l['dev'] || $s['ino'] !== $l['ino']) {
                    return "{$entry} on the way to the private directory changed while it was checked";
                }
                $here = $s;
                $path = $name === '..' ? dirname($path) : $entry;
            }
            return ['stat' => $here, 'path' => $path, 'foreign' => $foreign, 'links' => $links];
        } finally {
            if (is_string($cwd)) @chdir($cwd);
            clearstatcache();
        }
    }

    /**
     * chdir() into $name and stat() the directory the process then stands in.
     * @phpstan-impure
     * @return array<int|string, int>|false
     */
    private static function enter(string $name): array|false
    {
        clearstatcache();
        return @chdir($name) ? @stat('.') : false;
    }

    /** cli/update.php keeps root for the tree; run() works in the private directory as $owner. */
    public static function record(?array $owner): void
    {
        self::$owner = $owner;
        if ($owner === null || self::$atShutdown) return;
        self::$atShutdown = true;
        // A connection closed as root deletes its -wal and -shm by name in the private directory. Objects are destroyed
        // after the shutdown functions: the last one (registered from a shutdown function) hands over to the owner first.
        register_shutdown_function(static function (): void {
            register_shutdown_function(static function (): void {
                if (self::rootOutside()) {
                    try {
                        self::become(self::$owner);
                    } catch (Throwable $e) {
                        // Root must not close the connection in the private directory: end the process before it can.
                        fwrite(STDERR, $e->getMessage() . "; stopping without closing the database\n");
                        posix_kill(posix_getpid(), 9);
                    }
                }
            });
        });
    }

    private static bool $atShutdown = false;

    /** @return array{uid: int, gid: int, name: string, dir: string}|null */
    public static function recorded(): ?array
    {
        return self::$owner;
    }

    /** True while root works with root's rights although the private directory has another owner (outside run()). */
    public static function rootOutside(): bool
    {
        return self::$owner !== null && function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /**
     * $fn with the effective user, group and groups of the private directory's owner when root recorded one; directly
     * otherwise (not root, or already inside run()).
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function run(callable $fn): mixed
    {
        if (!self::rootOutside()) return $fn();
        self::become(self::$owner);
        try {
            return $fn();
        } finally {
            self::become(null);
        }
    }

    /**
     * $fn as root again from inside run() (the tree, the update directory); directly otherwise.
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function asRoot(callable $fn): mixed
    {
        if (self::$owner === null || !function_exists('posix_geteuid') || posix_geteuid() === 0 || posix_getuid() !== 0) return $fn();
        self::become(null);
        try {
            return $fn();
        } finally {
            self::become(self::$owner);
        }
    }

    /** @param array{uid: int, gid: int, name: string, dir: string}|null $who null: root */
    private static function become(?array $who): void
    {
        if ($who === null) {
            $root = posix_getpwuid(0);
            $ok = @posix_seteuid(0) && @posix_setegid(0) && @posix_initgroups(is_array($root) ? (string)$root['name'] : 'root', 0);
            if (!$ok || posix_geteuid() !== 0) throw new RuntimeException('Cannot take back root after working in the private directory');
            return;
        }
        // Groups and group first: once the effective user is not root they cannot change.
        $ok = @posix_initgroups($who['name'], $who['gid']) && @posix_setegid($who['gid']) && @posix_seteuid($who['uid']);
        $groups = posix_getgroups();
        if (!$ok || posix_geteuid() !== $who['uid'] || posix_getegid() !== $who['gid'] || in_array(0, is_array($groups) ? $groups : [0], true)) {
            if (posix_geteuid() !== 0) @posix_seteuid(0);
            @posix_setegid(0);
            throw new RuntimeException("Cannot work in the private directory as its owner ({$who['name']}, uid {$who['uid']}, gid {$who['gid']})");
        }
    }
}
