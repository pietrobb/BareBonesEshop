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
        private array $priceHistory = [],
        private array $endedPromotionRules = [],
        private array $endedPriceListPrices = []
    ) {}

    public static function forProducts(array $products, ?array $categoryMap = null, ?int $customerId = null): self
    {
        $at = gmdate('Y-m-d H:i:s');
        $profile = class_exists('Customers') ? Customers::commerceProfile($customerId) : null;
        [$rules, $ended] = class_exists('Promotions') ? Promotions::catalogRulesWithEnded($at) : [[], []];
        [$priceListRows, $endedPriceLists] = class_exists('Pricing') ? Pricing::priceListRowsWithEnded($products, $profile, 1, $at) : [[], []];

        return new self(
            Products::categoryIdsMany($products),
            $categoryMap ?? Products::categoryMap(),
            $profile,
            $priceListRows,
            $rules,
            PriceHistory::latestMany(array_map(static fn(array $product): int => (int)($product['id'] ?? 0), $products)),
            $ended,
            $endedPriceLists
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
        // Sold in the store's currency by its price list: the recorded price history is the catalog currency's.
        $history = isset($product['catalog_price']) ? null : ($this->priceHistory[$productId] ?? null);
        if (isset($product['catalog_price'])) $basePrice = (int)$product['price'];
        $beforePromotions = $product;
        if (class_exists('Promotions')) {
            $product = Promotions::applyCatalogRules($product, $this->promotionRules, $this->customerProfile);
        }
        $endedLow = null;
        $endedPriceLists = $this->endedPriceListPrices[$productId] ?? [];
        if ($this->endedPromotionRules !== [] || $endedPriceLists !== []) {
            $start = $this->reductionStart($product, $basePrice, $history);
            $endedLow = $this->endedPromotionRules === [] ? null
                : Promotions::lowestEndedPrice($beforePromotions, $this->endedPromotionRules, $this->customerProfile, $start);
            $since = gmdate('Y-m-d H:i:s', $start - PriceHistory::WINDOW_DAYS * 86400);
            $startAt = gmdate('Y-m-d H:i:s', $start);
            foreach ($endedPriceLists as $row) {
                if ($row['valid_to'] < $since || ($row['valid_from'] !== null && (string)$row['valid_from'] >= $startAt) || $row['price'] >= $basePrice) continue;
                $endedLow = $endedLow === null ? $row['price'] : min($endedLow, $row['price']);
            }
        }
        // Omnibus: the struck-through price is at most the lowest price of the 30 days before the reduction,
        // including prices charged by catalog promotions and price lists that have ended since.
        return PriceHistory::capCompare($product, $basePrice, $history, null, $endedLow);
    }

    /**
     * When the reduction shown now began: the latest start of the running promotions applied (now if one has no
     * start), else the last change of the base price, else now.
     */
    private function reductionStart(array $product, int $basePrice, ?array $latest): int
    {
        $now = time();
        $applied = array_column($product['promotion_snapshot']['applied'] ?? [], 'code');
        if ($applied !== []) {
            $start = 0;
            foreach ($this->promotionRules as $rule) {
                if (!in_array((string)$rule['code'], $applied, true)) continue;
                $from = $rule['valid_from'] === null ? false : strtotime($rule['valid_from'] . ' UTC');
                $start = max($start, $from === false ? $now : $from);
            }
            return $start ?: $now;
        }
        if ($latest !== null && $latest['price'] === $basePrice) {
            return max($now - PriceHistory::WINDOW_DAYS * 86400, strtotime($latest['changed_at'] . ' UTC') ?: $now);
        }
        return $now;
    }
}
