<?php
final class StorefrontAutomotiveExtension
{
    public static function register(): void
    {
        if (!class_exists(TecDoc::class) || StorefrontViewModelRegistry::has('product', 'automotive.product')) return;
        StorefrontViewModelRegistry::register('product', 'automotive.product', static function(array $context): array {
            $source = $context['source'];
            $productId = (int)$source['id'];
            try {
                $oeNumbers = TecDoc::oeNumbers($productId);
                $vehicles = TecDoc::vehiclesForProduct($productId, 100);
            } catch (PDOException $exception) {
                return [];
            }
            if ($oeNumbers === [] && $vehicles === [] && (string)($source['product_type'] ?? '') !== 'auto_part') return [];
            return [
                'enabled' => true,
                'oe_numbers' => array_map(static fn(array $oe): array => [
                    'brand' => (string)$oe['oem_brand'], 'number' => (string)$oe['oem_number'],
                    'url' => Template::storeUrl('/?page=oe&q=' . rawurlencode((string)$oe['oem_number'])),
                ], $oeNumbers),
                'vehicles' => array_map(static fn(array $vehicle): array => [
                    'manufacturer' => (string)$vehicle['manufacturer'], 'model' => (string)$vehicle['model'],
                    'type' => (string)$vehicle['type_name'], 'ktype' => (int)$vehicle['ktype'],
                ], $vehicles),
            ];
        });
    }
}
