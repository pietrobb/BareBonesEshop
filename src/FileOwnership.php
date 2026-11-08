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
    /** @param resource $handle the staged file, still open */
    public static function handOver($handle, string $path, ?int $uid, ?int $gid): void
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
