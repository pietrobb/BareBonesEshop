<?php
/**
 * LocalBackupStorage — ukladá zálohy do lokálneho adresára (default: storage/backups).
 *
 * Layout:
 *   <root>/YYYY/MM/DD/eshop-YYYYMMDD-HHMMSS.db
 *   <root>/YYYY/MM/DD/eshop-YYYYMMDD-HHMMSS.db.meta.json
 *   <root>/YYYY/MM/DD/eshop-YYYYMMDD-HHMMSS.db.gz   (optional)
 */
class LocalBackupStorage implements BackupStorage
{
    private string $root;
    private string $rootPrefix;

    public function __construct(string $root)
    {
        if ($root === '' || preg_match('/[\x00-\x1F\x7F]/', $root)) {
            throw new RuntimeException('LocalBackupStorage: invalid root');
        }
        if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
            throw new RuntimeException("LocalBackupStorage: mkdir failed: {$root}");
        }

        $canonicalRoot = realpath($root);
        if ($canonicalRoot === false || !is_dir($canonicalRoot)) {
            throw new RuntimeException("LocalBackupStorage: cannot resolve root: {$root}");
        }
        $this->root = $this->trimTrailingSeparators($canonicalRoot);
        $this->rootPrefix = str_ends_with($this->root, DIRECTORY_SEPARATOR)
            ? $this->root
            : $this->root . DIRECTORY_SEPARATOR;
    }

    public function put(string $localPath, string $remotePath, array $meta): void
    {
        $abs = $this->localPath($remotePath);
        $dir = dirname($abs);
        $this->createDirectory($dir);
        // Re-resolve after mkdir to catch symlinked parents before copying.
        $abs = $this->localPath($remotePath);
        if (!@copy($localPath, $abs)) {
            throw new RuntimeException("LocalBackupStorage: copy failed: {$localPath} -> {$abs}");
        }
        $metaJson = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($metaJson === false) {
            throw new RuntimeException('LocalBackupStorage: meta json_encode failed');
        }
        $metaPath = $this->containedDerivedPath($abs . '.meta.json');
        if (file_put_contents($metaPath, $metaJson) === false) {
            throw new RuntimeException("LocalBackupStorage: meta write failed: {$metaPath}");
        }
    }

    public function get(string $remotePath, string $localPath): void
    {
        $abs = $this->localPath($remotePath);
        if (!is_file($abs)) {
            throw new RuntimeException("LocalBackupStorage: not found: {$abs}");
        }
        if (!@copy($abs, $localPath)) {
            throw new RuntimeException("LocalBackupStorage: get copy failed: {$abs} -> {$localPath}");
        }
    }

    public function delete(string $remotePath): void
    {
        $abs = $this->localPath($remotePath);
        foreach ([$abs, $abs . '.meta.json', $abs . '.gz'] as $candidate) {
            $candidate = $this->containedDerivedPath($candidate);
            if ((file_exists($candidate) || is_link($candidate)) && !unlink($candidate)) {
                throw new RuntimeException("LocalBackupStorage: delete failed: {$candidate}");
            }
        }
    }

    public function list(string $prefix = ''): array
    {
        $base = $prefix === '' ? $this->root : $this->localPath($prefix);
        $this->assertCanonicalContainment($base);
        if (!is_dir($base)) return [];

        $rows = [];
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            $abs = $file->getPathname();
            $this->assertCanonicalContainment($abs);
            $name = $file->getFilename();
            if (!str_starts_with($name, 'eshop-')) continue;
            // Skip aux files (.meta.json / .gz) — list only the .db (or .db.gz when raw .db absent).
            if (str_ends_with($name, '.meta.json')) continue;
            if (str_ends_with($name, '.gz')) {
                // include only if no raw .db sibling
                $rawSibling = substr($file->getPathname(), 0, -3);
                if (is_file($rawSibling)) continue;
            }
            $rel = str_replace('\\', '/', substr($abs, strlen($this->rootPrefix)));

            $metaPath = $this->containedDerivedPath($abs . '.meta.json');
            $meta = is_file($metaPath) ? (json_decode((string)file_get_contents($metaPath), true) ?: []) : [];
            $rows[] = [
                'path'  => $rel,
                'meta'  => $meta,
                'mtime' => $file->getMTime(),
                'size'  => $file->getSize(),
            ];
        }
        usort($rows, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $rows;
    }

    public function localPath(string $remotePath): string
    {
        $remotePath = $this->validateRelativePath($remotePath);
        $path = $this->rootPrefix . str_replace('/', DIRECTORY_SEPARATOR, $remotePath);
        $this->assertCanonicalContainment($path);
        return $path;
    }

    private function validateRelativePath(string $path): string
    {
        if ($path === '' || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new RuntimeException('LocalBackupStorage: invalid empty/control path');
        }
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new RuntimeException('LocalBackupStorage: path must be relative');
        }
        if (str_contains($path, '/') && str_contains($path, '\\')) {
            throw new RuntimeException('LocalBackupStorage: mixed path separators');
        }
        $path = str_replace('\\', '/', $path);
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..' || str_contains($segment, ':')) {
                throw new RuntimeException('LocalBackupStorage: traversal or stream syntax detected');
            }
        }
        return $path;
    }

    private function containedDerivedPath(string $path): string
    {
        $this->assertCanonicalContainment($path);
        return $path;
    }

    private function assertCanonicalContainment(string $path): void
    {
        $probe = (file_exists($path) || is_link($path)) ? $path : dirname($path);
        while (!file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                throw new RuntimeException('LocalBackupStorage: cannot resolve parent path');
            }
            $probe = $parent;
        }
        $canonical = realpath($probe);
        if ($canonical === false) {
            throw new RuntimeException("LocalBackupStorage: cannot resolve path: {$probe}");
        }
        $canonical = $this->trimTrailingSeparators($canonical);
        if (!$this->samePath($canonical, $this->root) && !$this->pathStartsWith($canonical, $this->rootPrefix)) {
            throw new RuntimeException('LocalBackupStorage: path escapes storage root');
        }
    }

    private function createDirectory(string $dir): void
    {
        $this->assertCanonicalContainment($dir);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("LocalBackupStorage: mkdir failed: {$dir}");
        }
        $this->assertCanonicalContainment($dir);
    }

    private function samePath(string $left, string $right): bool
    {
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($left, $right) === 0 : $left === $right;
    }

    private function pathStartsWith(string $path, string $prefix): bool
    {
        return DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($path, $prefix, strlen($prefix)) === 0
            : str_starts_with($path, $prefix);
    }

    private function trimTrailingSeparators(string $path): string
    {
        if ($path === DIRECTORY_SEPARATOR || preg_match('/^[A-Za-z]:[\\\\\/]$/', $path)) return $path;
        return rtrim($path, "/\\");
    }

    public function root(): string
    {
        return $this->root;
    }
}
