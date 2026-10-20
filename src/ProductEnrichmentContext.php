<?php
/** Request-scoped data required to enrich a product collection without N+1 queries. */
final class ProductEnrichmentContext
{
    private function __construct(
        private array $categoryIds,
        private array $categoryMap,
        private ?array $customerProfile,
        private array $priceListRows,
        private array $promotionRules,
        private array $priceHistory = []
    ) {}

    public static function forProducts(array $products, ?array $categoryMap = null, ?int $customerId = null): self
    {
        $at = gmdate('Y-m-d H:i:s');
        $profile = class_exists('Customers') ? Customers::commerceProfile($customerId) : null;

        return new self(
            Products::categoryIdsMany($products),
            $categoryMap ?? Products::categoryMap(),
            $profile,
            class_exists('Pricing') ? Pricing::priceListRows($products, $profile, 1, $at) : [],
            class_exists('Promotions') ? Promotions::catalogRules($at) : [],
            PriceHistory::latestMany(array_map(static fn(array $product): int => (int)($product['id'] ?? 0), $products))
        );
    }

    public function categoryIds(int $productId): array
    {
        return $this->categoryIds[$productId] ?? [];
    }

    public function category(int $categoryId): ?array
    {
        return $this->categoryMap[$categoryId] ?? null;
    }

    public function applyCommerce(array $product): array
    {
        $productId = (int)($product['id'] ?? 0);
        $basePrice = (int)($product['price'] ?? 0);
        if (class_exists('Pricing')) {
            $product = Pricing::applyPriceListContext(
                $product,
                $this->priceListRows[$productId] ?? null,
                $this->customerProfile
            );
        }
        if (class_exists('Promotions')) {
            $product = Promotions::applyCatalogRules($product, $this->promotionRules, $this->customerProfile);
        }
        // Omnibus: the struck-through price is at most the lowest price of the 30 days before the reduction.
        return PriceHistory::capCompare($product, $basePrice, $this->priceHistory[$productId] ?? null);
    }
}
