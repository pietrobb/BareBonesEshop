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
}
