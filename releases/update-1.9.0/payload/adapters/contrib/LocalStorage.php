<?php
/**
 * BareBones Shop — Local Storage Adapter
 * Stores files in uploads/ directory. Image resize via GD.
 */
class LocalStorage implements StorageAdapter {
    private string $uploadsDir;
    private string $uploadsPrefix;
    private string $baseUrl;

    private const VARIANTS = [
        'thumb'  => ['width' => 150,  'height' => 150],
        'medium' => ['width' => 600,  'height' => 600],
        'large'  => ['width' => 1200, 'height' => 1200],
        'full'   => null, // original
    ];

    public function __construct(array $cfg) {
        $uploadsDir = (string)($cfg['uploads_dir'] ?? dirname(__DIR__) . '/uploads');
        $this->baseUrl = $cfg['uploads_url'] ?? '/uploads';
        if (!is_dir($uploadsDir) && !@mkdir($uploadsDir, 0750, true) && !is_dir($uploadsDir)) {
            throw new \RuntimeException("Cannot create uploads directory: {$uploadsDir}");
        }

        $canonicalRoot = realpath($uploadsDir);
        if ($canonicalRoot === false || !is_dir($canonicalRoot)) {
            throw new \RuntimeException("Cannot resolve uploads directory: {$uploadsDir}");
        }
        $this->uploadsDir = $this->trimTrailingSeparators($canonicalRoot);
        $this->uploadsPrefix = str_ends_with($this->uploadsDir, DIRECTORY_SEPARATOR)
            ? $this->uploadsDir
            : $this->uploadsDir . DIRECTORY_SEPARATOR;
    }

    public function store(string $path, string $contents): string {
        $path = $this->validateRelativePath($path);
        $fullPath = $this->safePath($path);
        $this->createDirectory(dirname($fullPath));
        // Re-check after mkdir so a pre-existing or concurrently introduced symlink cannot escape the root.
        $fullPath = $this->safePath($path);
        if (file_put_contents($fullPath, $contents, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write uploaded file: {$path}");
        }
        return $this->baseUrl . '/' . $path;
    }

    public function delete(string $path): void {
        $path = $this->validateRelativePath($path);
        $fullPath = $this->safePath($path);

        // Delete all variants only after the original path has passed validation.
        foreach (self::VARIANTS as $variant => $dims) {
            $file = $this->variantPath($path, $variant);
            if ((file_exists($file) || is_link($file)) && !unlink($file)) {
                throw new \RuntimeException("Cannot delete image variant: {$path} ({$variant})");
            }
        }
        if ((file_exists($fullPath) || is_link($fullPath)) && !unlink($fullPath)) {
            throw new \RuntimeException("Cannot delete uploaded file: {$path}");
        }
    }

    public function url(string $path, string $variant = 'medium'): string {
        $path = $this->validateRelativePath($path);
        $this->safePath($path);
        if ($variant === 'full' || !isset(self::VARIANTS[$variant])) {
            return $this->baseUrl . '/' . $path;
        }
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $dir = pathinfo($path, PATHINFO_DIRNAME);
        $variantFile = ($dir !== '.' ? $dir . '/' : '') . $base . '-' . $variant . '.' . $ext;
        $this->safePath($variantFile);
        return $this->baseUrl . '/' . $variantFile;
    }

    public function resize(string $path, int $width, int $height): string {
        $path = $this->validateRelativePath($path);
        $srcPath = $this->safePath($path);
        if (!file_exists($srcPath)) {
            throw new \RuntimeException("Source file not found: {$path}");
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $src = match ($ext) {
            'jpg', 'jpeg' => imagecreatefromjpeg($srcPath),
            'png' => imagecreatefrompng($srcPath),
            'gif' => imagecreatefromgif($srcPath),
            'webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($srcPath) : null,
            default => null,
        };

        if (!$src) {
            throw new \RuntimeException("Unsupported image format: {$ext}");
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        // Fit within bounds, maintaining aspect ratio
        $ratio = min($width / $srcW, $height / $srcH);
        if ($ratio >= 1) {
            // Image already smaller, just copy original
            imagedestroy($src);
            return $this->url($path, 'full');
        }

        $dstW = (int)round($srcW * $ratio);
        $dstH = (int)round($srcH * $ratio);

        $dst = imagecreatetruecolor($dstW, $dstH);

        // Preserve transparency for PNG
        if ($ext === 'png') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($src);

        // Determine variant name from size
        $variantName = 'custom';
        foreach (self::VARIANTS as $name => $dims) {
            if ($dims && $dims['width'] === $width && $dims['height'] === $height) {
                $variantName = $name;
                break;
            }
        }

        $base = pathinfo($path, PATHINFO_FILENAME);
        $dir = pathinfo($path, PATHINFO_DIRNAME);
        $outFile = ($dir !== '.' ? $dir . '/' : '') . $base . '-' . $variantName . '.' . $ext;
        $outPath = $this->safePath($outFile);
        $this->createDirectory(dirname($outPath));
        $outPath = $this->safePath($outFile);

        $written = match ($ext) {
            'jpg', 'jpeg' => imagejpeg($dst, $outPath, 85),
            'png' => imagepng($dst, $outPath, 6),
            'gif' => imagegif($dst, $outPath),
            'webp' => imagewebp($dst, $outPath, 85),
            default => false,
        };
        if (!$written) {
            imagedestroy($dst);
            throw new \RuntimeException("Cannot write resized image for format: {$ext}");
        }

        // Also generate WebP version alongside original format (if not already WebP)
        if ($ext !== 'webp' && function_exists('imagewebp')) {
            $webpFile = ($dir !== '.' ? $dir . '/' : '') . $base . '-' . $variantName . '.webp';
            $webpPath = $this->safePath($webpFile);
            if (!imagewebp($dst, $webpPath, 80)) {
                imagedestroy($dst);
                throw new \RuntimeException("Cannot write WebP image variant: {$webpFile}");
            }
        }
        imagedestroy($dst);

        return $this->baseUrl . '/' . $outFile;
    }

    /** Generate all standard variants for an uploaded image */
    public function generateVariants(string $path): array {
        $urls = [];
        foreach (self::VARIANTS as $variant => $dims) {
            if ($dims === null) {
                $urls[$variant] = $this->url($path, 'full');
            } else {
                try {
                    $urls[$variant] = $this->resize($path, $dims['width'], $dims['height']);
                } catch (\Throwable $e) {
                    $urls[$variant] = $this->url($path, 'full');
                }
            }
        }
        return $urls;
    }

    /** Validate path stays within uploads directory (prevent path traversal). */
    private function safePath(string $path): string {
        $path = $this->validateRelativePath($path);
        $full = $this->uploadsPrefix . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $this->assertCanonicalContainment($full);
        return $full;
    }

    private function validateRelativePath(string $path): string {
        if ($path === '' || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new \RuntimeException("Invalid path: empty or control characters");
        }
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:/', $path)) {
            throw new \RuntimeException("Invalid path: must be relative");
        }
        if (str_contains($path, '/') && str_contains($path, '\\')) {
            throw new \RuntimeException("Invalid path: mixed separators");
        }

        $path = str_replace('\\', '/', $path);
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '.' || str_contains($segment, ':')) {
                throw new \RuntimeException("Invalid path: traversal or stream syntax detected");
            }
        }
        return $path;
    }

    private function assertCanonicalContainment(string $path): void {
        $probe = (file_exists($path) || is_link($path)) ? $path : dirname($path);
        while (!file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                throw new \RuntimeException("Invalid path: cannot resolve parent directory");
            }
            $probe = $parent;
        }

        $canonical = realpath($probe);
        if ($canonical === false) {
            throw new \RuntimeException("Invalid path: cannot resolve path");
        }
        $canonical = $this->trimTrailingSeparators($canonical);
        if (!$this->samePath($canonical, $this->uploadsDir)
            && !$this->pathStartsWith($canonical, $this->uploadsPrefix)) {
            throw new \RuntimeException("Invalid path: outside uploads directory");
        }
    }

    private function createDirectory(string $dir): void {
        $this->assertCanonicalContainment($dir);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create uploads directory: {$dir}");
        }
        $this->assertCanonicalContainment($dir);
    }

    private function samePath(string $left, string $right): bool {
        return DIRECTORY_SEPARATOR === '\\'
            ? strcasecmp($left, $right) === 0
            : $left === $right;
    }

    private function pathStartsWith(string $path, string $prefix): bool {
        return DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($path, $prefix, strlen($prefix)) === 0
            : str_starts_with($path, $prefix);
    }

    private function trimTrailingSeparators(string $path): string {
        if ($path === DIRECTORY_SEPARATOR || preg_match('/^[A-Za-z]:[\\\\\/]$/', $path)) {
            return $path;
        }
        return rtrim($path, "/\\");
    }

    private function variantPath(string $path, string $variant): string {
        $path = $this->validateRelativePath($path);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $dir = pathinfo($path, PATHINFO_DIRNAME);
        $variantFile = ($dir !== '.' ? $dir . '/' : '') . $base . '-' . $variant . '.' . $ext;
        return $this->safePath($variantFile);
    }
}
