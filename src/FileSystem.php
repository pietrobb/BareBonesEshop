<?php
/**
 * BareBones Shop — shared filesystem helpers.
 */
final class FileSystem
{
    /**
     * Delete a file or directory tree without ever following symlinks: a link (to a file or a
     * directory) is removed as a link, and only real directories are descended into. Best effort,
     * failures are silent; callers that need a guarantee check the path afterwards.
     */
    public static function removeTree(string $path): void
    {
        if (is_link($path)) {
            // Windows directory links/junctions are removed with rmdir, everything else with unlink.
            if (!@unlink($path)) @rmdir($path);
            return;
        }
        if (!is_dir($path)) {
            if (file_exists($path)) @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            self::removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }

    /**
     * R28 (review round 28, low): a non-blocking flock() that tells a lock another process holds (false) from a file
     * system without file locks (throws). Without locks in the private directory every step failed as if another one
     * ran ("Another update process is already running", "lock acquisition timed out"). $wouldBlock is not set on Windows.
     * @param resource $handle
     */
    public static function tryLock($handle, string $path, int $operation = LOCK_EX): bool
    {
        return flock($handle, $operation | LOCK_NB, $wouldBlock) || self::lockRefused($path, (int)$wouldBlock);
    }

    /** After a failed non-blocking flock(): false when another process holds the lock, else throws. */
    public static function lockRefused(string $path, int $wouldBlock): bool
    {
        if ($wouldBlock !== 1 && PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException(self::noFileLocks($path));
        }
        return false;
    }

    public static function noFileLocks(string $path): string
    {
        return "{$path} cannot be locked: its file system has no file locks (flock), so this step cannot tell whether"
            . ' another one runs. Keep the private directory (update_storage_dir, backups, the SQLite database) on a local'
            . ' file system or on a share with working locks.';
    }
}
