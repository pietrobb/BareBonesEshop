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
            $existing = $dir;
            while (!file_exists($existing) && !is_link($existing) && dirname($existing) !== $existing) $existing = dirname($existing);
            $real = realpath($existing);
            clearstatcache();
            $s = $real === false ? false : @stat($real);
            if (!is_array($s) || ((int)$s['mode'] & 0170000) !== 0040000) return "the private directory {$dir} cannot be resolved to a directory";
            $uid = (int)$s['uid'];
            $foreign = [];
            for ($d = $real; ; $d = dirname($d)) {
                $l = @lstat($d);
                if (!is_array($l)) return "{$d} cannot be read";
                $sticky = $d !== $real && ((int)$l['mode'] & 01000) !== 0;
                if ((int)$l['uid'] !== 0 || (((int)$l['mode'] & 0022) !== 0 && !$sticky)) $foreign[] = [$d, (int)$l['uid']];
                if ($d === dirname($d)) break;
            }
            // A link on the configured path is followed by whoever opens it: it must be root's or the owner's.
            for ($d = $existing; $d !== dirname($d); $d = dirname($d)) {
                $l = @lstat($d);
                if (is_link($d) && is_array($l) && !in_array((int)$l['uid'], [0, $uid], true)) {
                    return "{$d} on the way to the private directory is a link of another account (uid {$l['uid']})";
                }
            }
            if ($foreign === []) continue;
            if ($uid === 0) {
                return "the private directory {$real} belongs to root, but {$foreign[0][0]} can be changed by another account;"
                    . ' give the private directory to the web server user (chown) or make it and every directory above it root\'s alone';
            }
            foreach ($foreign as [$d, $u]) {
                if ($u !== 0 && $u !== $uid) return "{$d} above the private directory {$real} belongs to another account (uid {$u}) than the directory (uid {$uid})";
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
