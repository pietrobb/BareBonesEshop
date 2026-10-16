<?php
/**
 * BareBones Shop — Storage capability: pre-generates the standard image variants
 * (thumb/medium/full) for image_resize worker jobs. Default: LocalStorage.
 * Storage adapters without it simply serve the original image.
 */
interface ImageVariantStorageAdapter extends StorageAdapter {
    /** @return array<string,string> variant name => public URL */
    public function generateVariants(string $path): array;
}
