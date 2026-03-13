<?php
/**
 * BareBones Shop — Storage Adapter Interface
 * Default: LocalStorage (uploads/ directory + GD resize)
 * Optional: S3Storage, CloudflareR2Storage, FtpStorage
 */
interface StorageAdapter {
    /** Store a file, returns public URL */
    public function store(string $path, string $contents): string;

    /** Delete a stored file */
    public function delete(string $path): void;

    /** Get public URL for a file variant (thumb/medium/full) */
    public function url(string $path, string $variant = 'medium'): string;

    /** Resize an image to given dimensions, returns URL of resized version */
    public function resize(string $path, int $width, int $height): string;
}
