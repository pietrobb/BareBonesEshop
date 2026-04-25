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

    public function __construct(string $root)
    {
        $this->root = rtrim($root, "/\\");
        if (!is_dir($this->root)) {
            mkdir($this->root, 0775, true);
        }
    }

    public function put(string $localPath, string $remotePath, array $meta): void
    {
        $abs = $this->localPath($remotePath);
        $dir = dirname($abs);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (!@copy($localPath, $abs)) {
            throw new RuntimeException("LocalBackupStorage: copy failed: {$localPath} -> {$abs}");
        }
        $metaJson = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($metaJson === false) {
            throw new RuntimeException('LocalBackupStorage: meta json_encode failed');
        }
        if (file_put_contents($abs . '.meta.json', $metaJson) === false) {
            throw new RuntimeException("LocalBackupStorage: meta write failed: {$abs}.meta.json");
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
        @unlink($abs);
        @unlink($abs . '.meta.json');
        @unlink($abs . '.gz');
    }

    public function list(string $prefix = ''): array
    {
        $base = $this->root;
        if ($prefix !== '') {
            $base .= DIRECTORY_SEPARATOR . trim($prefix, "/\\");
        }
        if (!is_dir($base)) return [];

        $rows = [];
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            $name = $file->getFilename();
            if (!str_starts_with($name, 'eshop-')) continue;
            // Skip aux files (.meta.json / .gz) — list only the .db (or .db.gz when raw .db absent).
            if (str_ends_with($name, '.meta.json')) continue;
            if (str_ends_with($name, '.gz')) {
                // include only if no raw .db sibling
                $rawSibling = substr($file->getPathname(), 0, -3);
                if (is_file($rawSibling)) continue;
            }
            $abs = $file->getPathname();
            $rel = ltrim(str_replace($this->root, '', $abs), "/\\");
            $rel = str_replace('\\', '/', $rel);

            $metaPath = $abs . '.meta.json';
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
        return $this->root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($remotePath, "/\\"));
    }

    public function root(): string
    {
        return $this->root;
    }
}
