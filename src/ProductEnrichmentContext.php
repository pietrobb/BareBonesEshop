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
        $listRow = $this->priceListRows[$productId] ?? null;
        if (class_exists('Pricing')) {
            $product = Pricing::applyPriceListContext($product, $listRow, $this->customerProfile);
        }
        // Sold in the store's currency by its price list: the recorded price history is the catalog currency's.
        $history = isset($product['catalog_price']) ? null : ($this->priceHistory[$productId] ?? null);
        if (isset($product['catalog_price'])) $basePrice = (int)$product['price'];
        $beforePromotions = $product;
        if (class_exists('Promotions')) {
            $product = Promotions::applyCatalogRules($product, $this->promotionRules, $this->customerProfile);
        }
        // A running price list that reduces the base price: when it began.
        $listStart = $listRow !== null && (int)$beforePromotions['price'] < $basePrice
            ? self::runStart($listRow['valid_from'], $listRow['list_created_at'] ?? null, $listRow['list_updated_at'] ?? null) : null;
        $promotionStart = $this->promotionStart($product);
        $endedLow = null;
        // A promotion on top of a price list that ran before it: the price list's price was charged before the promotion.
        if ($promotionStart !== null && $listStart !== null && $listStart[0] < $promotionStart[1]) {
            $endedLow = (int)$beforePromotions['price'];
        }
        $endedPriceLists = $this->endedPriceListPrices[$productId] ?? [];
        if ($this->endedPromotionRules !== [] || $endedPriceLists !== []) {
            [$start, $latestStart] = $promotionStart ?? $listStart ?? $this->priceChangeStart($basePrice, $history);
            $promotionLow = $this->endedPromotionRules === [] ? null
                : Promotions::lowestEndedPrice($beforePromotions, $this->endedPromotionRules, $this->customerProfile, $start, $latestStart);
            if ($promotionLow !== null) $endedLow = $endedLow === null ? $promotionLow : min($endedLow, $promotionLow);
            $since = gmdate('Y-m-d H:i:s', $start - PriceHistory::WINDOW_DAYS * 86400);
            $startAt = gmdate('Y-m-d H:i:s', $latestStart);
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
     * When the running promotions applied to the product began, as [earliest, latest] possible start (the latest
     * start among them), or null when none applies.
     * @return array{0: int, 1: int}|null
     */
    private function promotionStart(array $product): ?array
    {
        $applied = array_column($product['promotion_snapshot']['applied'] ?? [], 'code');
        if ($applied === []) return null;
        $start = null;
        foreach ($this->promotionRules as $rule) {
            if (!in_array((string)$rule['code'], $applied, true)) continue;
            $run = self::runStart($rule['valid_from'], $rule['created_at'] ?? null, $rule['updated_at'] ?? null);
            $start = $start === null ? $run : [max($start[0], $run[0]), max($start[1], $run[1])];
        }
        return $start ?? [time(), time()];
    }

    /**
     * When a running promotion or price list began, as [earliest, latest] possible start. With valid_from that is
     * its start. Without, it began when it was created or last saved by an admin (switched on, re-dated, repriced):
     * an admin change that restarted it is not told apart from one that did not, so both bounds stay possible.
     * @return array{0: int, 1: int}
     */
    private static function runStart(?string $validFrom, ?string $createdAt, ?string $updatedAt): array
    {
        $now = time();
        $at = static fn(?string $value): ?int => $value === null || $value === '' ? null : (strtotime($value . ' UTC') ?: null);
        $from = $at($validFrom);
        if ($from !== null) return [min($from, $now), min($from, $now)];
        $created = $at($createdAt) ?? $now;
        return [min($created, $now), min(max($created, $at($updatedAt) ?? $created), $now)];
    }

    /** No promotion or price list reduces the price: the reduction is the last change of the base price, else now. */
    private function priceChangeStart(int $basePrice, ?array $latest): array
    {
        $now = time();
        $start = $latest !== null && $latest['price'] === $basePrice
            ? max($now - PriceHistory::WINDOW_DAYS * 86400, strtotime($latest['changed_at'] . ' UTC') ?: $now) : $now;
        return [$start, $start];
    }
}
