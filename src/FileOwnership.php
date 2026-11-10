<?php
/**
 * R23 (G5): the updater and restore run as root and give a staged file the owner of the file it replaces, in a
 * directory the web user can write to. lchown on the name ignores a symlink swapped in, but not a hard link: with
 * fs.protected_hardlinks=0 the web user can link /etc/shadow under the staged name between the inode check and the
 * call, and root then handed /etc/shadow over. The owner is now set through the open descriptor (/proc/self/fd/N
 * resolves to the open inode, whatever the name points to since); without /proc only protected hard links make the
 * name safe, otherwise the hand-over is refused.
 */
final class FileOwnership
{
    /**
     * @param resource $handle the staged file, still open
     * @param int|null $mode R25: also set these permission bits — through the descriptor only (chmod follows a symlink and
     *                       PHP has no fchmod); without /proc they stay as the file was created
     */
    public static function handOver($handle, string $path, ?int $uid, ?int $gid, ?int $mode = null): void
    {
        $descriptor = self::descriptorPath($handle);
        if ($descriptor === null && !self::hardlinksProtected()) {
            throw new RuntimeException("Cannot set the owner of {$path} safely: /proc/self/fd is unavailable and fs.protected_hardlinks is off");
        }
        $owner = $descriptor !== null ? 'chown' : 'lchown';
        $group = $descriptor !== null ? 'chgrp' : 'lchgrp';
        $target = $descriptor ?? $path;
        if ($uid !== null && !@$owner($target, $uid)) {
            throw new RuntimeException("Cannot set the owner of {$path}");
        }
        if ($gid !== null && !@$group($target, $gid)) {
            throw new RuntimeException("Cannot set the group of {$path}");
        }
        if ($mode !== null && $descriptor !== null && !@chmod($descriptor, $mode)) {
            throw new RuntimeException("Cannot set the permissions of {$path}");
        }
    }

    /**
     * R27 (capability review run 397): why this process cannot give a new file replacing $path that file's owner $uid and
     * group $gid, or null. A step started as root runs as the owner of the application root (cli/cli-only.php), and a
     * user can neither give a file away nor give it a group it is not in: `sudo php cli/restore.php` left the database of
     * www-data to the deploy user, unreadable for the shop, and reported success.
     */
    public static function cannotKeep(string $path, int $uid, int $gid): ?string
    {
        if (!function_exists('posix_geteuid')) return null;
        $euid = posix_geteuid();
        $groups = posix_getgroups();
        if ($euid === 0 || ($uid === $euid && ($gid === posix_getegid() || in_array($gid, is_array($groups) ? $groups : [], true)))) {
            return null;
        }
        $user = static fn(int $id): string => (string)(posix_getpwuid($id)['name'] ?? $id);
        $group = static fn(int $id): string => (string)(posix_getgrgid($id)['name'] ?? $id);
        [$owner, $me] = [$user($uid), $user($euid)];
        return "{$path} belongs to {$owner}:{$group($gid)}, but this step runs as {$me}:{$group(posix_getegid())} and cannot give"
            . " its replacement that owner and group ({$owner} would be left with a file it cannot use); run the step as {$owner}"
            . " (sudo -u {$owner} php …), or hand {$path} to {$me} for the step and back to {$owner} afterwards";
    }

    /** @param resource $handle */
    private static function descriptorPath($handle): ?string
    {
        $open = fstat($handle);
        $descriptors = is_array($open) ? @scandir('/proc/self/fd') : false;
        if (!is_array($descriptors)) return null;
        foreach ($descriptors as $fd) {
            if (!ctype_digit($fd)) continue;
            $stat = @stat('/proc/self/fd/' . $fd);
            if (is_array($stat) && $stat['ino'] === $open['ino'] && $stat['dev'] === $open['dev']) {
                return '/proc/self/fd/' . $fd;
            }
        }
        return null;
    }

    private static function hardlinksProtected(): bool
    {
        return trim((string)@file_get_contents('/proc/sys/fs/protected_hardlinks')) === '1';
    }
}
