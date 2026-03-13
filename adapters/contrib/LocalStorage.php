<?php
/**
 * BareBones Shop — Local Storage Adapter
 * Stores files in uploads/ directory. Image resize via GD.
 */
class LocalStorage implements StorageAdapter {
    private string $uploadsDir;
    private string $baseUrl;

    private const VARIANTS = [
        'thumb'  => ['width' => 150,  'height' => 150],
        'medium' => ['width' => 600,  'height' => 600],
        'large'  => ['width' => 1200, 'height' => 1200],
        'full'   => null, // original
    ];

    public function __construct(array $cfg) {
        $this->uploadsDir = ($cfg['uploads_dir'] ?? dirname(__DIR__) . '/uploads');
        $this->baseUrl = $cfg['uploads_url'] ?? '/uploads';
        if (!is_dir($this->uploadsDir)) {
            mkdir($this->uploadsDir, 0750, true);
        }
    }

    public function store(string $path, string $contents): string {
        $fullPath = $this->safePath($path);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        file_put_contents($fullPath, $contents, LOCK_EX);
        return $this->baseUrl . '/' . $path;
    }

    public function delete(string $path): void {
        // Delete all variants
        foreach (self::VARIANTS as $variant => $dims) {
            $file = $this->variantPath($path, $variant);
            if (file_exists($file)) @unlink($file);
        }
        // Delete original
        $fullPath = $this->safePath($path);
        if (file_exists($fullPath)) @unlink($fullPath);
    }

    public function url(string $path, string $variant = 'medium'): string {
        if ($variant === 'full' || !isset(self::VARIANTS[$variant])) {
            return $this->baseUrl . '/' . $path;
        }
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $dir = pathinfo($path, PATHINFO_DIRNAME);
        $variantFile = ($dir !== '.' ? $dir . '/' : '') . $base . '-' . $variant . '.' . $ext;
        return $this->baseUrl . '/' . $variantFile;
    }

    public function resize(string $path, int $width, int $height): string {
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
        $outPath = $this->uploadsDir . '/' . $outFile;
        $outDir = dirname($outPath);
        if (!is_dir($outDir)) mkdir($outDir, 0750, true);

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
            $webpPath = $this->uploadsDir . '/' . $webpFile;
            @imagewebp($dst, $webpPath, 80);
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

    /** Validate path stays within uploads directory (prevent path traversal) */
    private function safePath(string $path): string {
        // Reject absolute paths
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            throw new \RuntimeException("Invalid path: must be relative");
        }
        // Reject any segment that is . or .. (covers all traversal patterns)
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new \RuntimeException("Invalid path: traversal detected");
            }
        }

        $full = $this->uploadsDir . '/' . $path;

        // After directory exists, verify canonical path stays inside uploads
        $realDir = realpath(dirname($full));
        $realUploads = realpath($this->uploadsDir);
        if ($realDir !== false && $realUploads !== false
            && !str_starts_with($realDir, $realUploads)) {
            throw new \RuntimeException("Invalid path: outside uploads directory");
        }

        return $full;
    }

    private function variantPath(string $path, string $variant): string {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $dir = pathinfo($path, PATHINFO_DIRNAME);
        $variantFile = ($dir !== '.' ? $dir . '/' : '') . $base . '-' . $variant . '.' . $ext;
        return $this->uploadsDir . '/' . $variantFile;
    }
}
