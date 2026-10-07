<?php
/**
 * BareBones Shop — Cart
 * Session-based with localStorage restore endpoint.
 */
class Cart {
    public static function get(): array {
        $cart = $_SESSION['cart'] ?? [];
        $normalized = [];
        foreach ($cart as $key => $item) {
            if (is_int($item) && $item > 0 && (is_int($key) || ctype_digit((string)$key))) {
                $productId = (int)$key;
                if ($productId > 0) {
                    $normalized["p_{$productId}"] = ['product_id' => $productId, 'variant_id' => null, 'qty' => $item];
                }
                continue;
            }
            if (is_array($item)) $normalized[(string)$key] = $item;
        }
        return $normalized;
    }

    public static function add(int $productId, ?int $variantId, int $qty): void {
        $key = $variantId ? "v_{$variantId}" : "p_{$productId}";
        if (!isset($_SESSION['cart'][$key])) {
            $_SESSION['cart'][$key] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'qty'        => 0,
            ];
        }
        $_SESSION['cart'][$key]['qty'] += $qty;
    }

    /** Validate a storefront cart command against canonical server data. */
    public static function addValidated(int $productId, ?int $variantId, int $qty): array {
        $product = $productId > 0 ? Products::byId($productId) : null;
        if (!$product || ($product['status'] ?? '') !== 'active') return ['ok' => false, 'error' => 'product_invalid'];
        $variants = Products::variants($productId);
        if ($variants !== [] && $variantId === null) return ['ok' => false, 'error' => 'variant_required'];
        if ($variantId !== null) {
            $variant = null;
            foreach ($variants as $candidate) {
                if ((int)$candidate['id'] === $variantId) { $variant = $candidate; break; }
            }
            if ($variant === null) return ['ok' => false, 'error' => 'variant_invalid'];
        }
        if (!VariantAvailability::isPurchasable($productId, $variantId)) {
            return ['ok' => false, 'error' => 'product_unavailable'];
        }
        self::add($productId, $variantId, max(1, min(99, $qty)));
        $key = $variantId === null ? "p_{$productId}" : "v_{$variantId}";
        $summary = self::enriched();
        $item = null;
        foreach ($summary['items'] as $candidate) {
            if ($candidate['key'] === $key) { $item = $candidate; break; }
        }
        return ['ok' => true, 'error' => null, 'item' => $item, 'count' => (int)$summary['count']];
    }

    public static function update(string $key, int $qty): void {
        if ($qty <= 0) {
            unset($_SESSION['cart'][$key]);
        } else {
            if (isset($_SESSION['cart'][$key])) {
                $_SESSION['cart'][$key]['qty'] = $qty;
            }
        }
    }

    /**
     * Enriched cart — real-time prices and stock from DB (batch queries).
     *
     * If $vatContext is provided, each line gets vat_class/vat_rate/vat_amount/net_amount,
     * and the summary gets vat_scenario / vat_total / net_total / reverse_charge_note_key.
     *
     * $vatContext keys:
     *   - seller_country (string, ISO2)        — required for VAT calc
     *   - customer_country (string, ISO2)      — required
     *   - customer_is_business (bool)          — defaults false
     *   - customer_vat_id (string|null)        — required when reverse-charge expected
     *   - customer_vat_validated (bool)        — VIES result; false ⇒ treat as B2C
     *   - oss_enabled (bool)                   — defaults false (per-store)
     *   - at_date (string|null)                — Y-m-d, defaults today
     */
    public static function enriched(?array $vatContext = null): array {
        $cart = self::get();
        if (empty($cart)) return ['items' => [], 'subtotal' => 0, 'count' => 0];

        $db = Db::get();

        // Collect IDs for batch queries
        $productIds = [];
        $variantIds = [];
        foreach ($cart as $item) {
            $productIds[] = $item['product_id'];
            if (!empty($item['variant_id'])) {
                $variantIds[] = $item['variant_id'];
            }
        }
        $productIds = array_unique($productIds);

        // Batch fetch products (incl. vat_class for per-line VAT calculation)
        $products = [];
        if (!empty($productIds)) {
            $ph = implode(',', array_fill(0, count($productIds), '?'));
            $stmt = $db->prepare("SELECT id, name, slug, images, price, compare_price, sku, stock, currency,
                    vat_class, product_type, category_id, brand_id, weight_g, length_mm, width_mm, height_mm,
                    availability, available_from
                FROM products WHERE id IN ({$ph}) AND store_id = ? AND status = 'active'");
            $stmt->execute([...array_values($productIds), StoreContext::current()->catalogId()]);
            while ($row = $stmt->fetch()) $products[(int)$row['id']] = $row;
        }

        // Batch fetch variants
        $variants = [];
        if (!empty($variantIds)) {
            $ph = implode(',', array_fill(0, count($variantIds), '?'));
            $stmt = $db->prepare("SELECT v.id, v.product_id, v.sku, v.name, v.stock, v.price_delta,
                    v.price_absolute, v.availability, v.available_from, v.media, v.weight_g,
                    v.length_mm, v.width_mm, v.height_mm
                FROM variants v
                JOIN products p ON p.id = v.product_id AND p.store_id = ?
                WHERE v.id IN ({$ph})");
            $stmt->execute([StoreContext::current()->catalogId(), ...array_values($variantIds)]);
            while ($row = $stmt->fetch()) $variants[(int)$row['id']] = $row;
        }

        $items = [];
        $subtotal = 0;
        $cartCurrency = null; // enforced currency for entire cart

        foreach ($cart as $key => $item) {
            $product = $products[$item['product_id']] ?? null;
            if (!$product) { unset($_SESSION['cart'][$key]); continue; }
            $isDigital = VariantAvailability::isDigitalType((string)($product['product_type'] ?? ''));
            $availability = (string)($product['availability'] ?? VariantAvailability::ACTIVE);
            if ($availability === VariantAvailability::ACTIVE
                && !empty($product['available_from'])
                && strtotime((string)$product['available_from']) > time()) {
                $availability = VariantAvailability::COMING_SOON;
            }
            $weightG = (int)($product['weight_g'] ?? 0);
            $lengthMm = (int)($product['length_mm'] ?? 0);
            $widthMm = (int)($product['width_mm'] ?? 0);
            $heightMm = (int)($product['height_mm'] ?? 0);

            if (!empty($item['variant_id'])) {
                $variant = $variants[$item['variant_id']] ?? null;
                if (!$variant) { unset($_SESSION['cart'][$key]); continue; }
                $price = $variant['price_absolute'] !== null
                    ? (int)$variant['price_absolute']
                    : (int)$product['price'] + (int)$variant['price_delta'];
                $sku = $variant['sku'];
                $variantName = $variant['name'];
                $stock = (int)$variant['stock'];
                $availability = (string)($variant['availability'] ?? VariantAvailability::ACTIVE);
                if ($availability === VariantAvailability::ACTIVE
                    && !empty($variant['available_from'])
                    && strtotime((string)$variant['available_from']) > time()) {
                    $availability = VariantAvailability::COMING_SOON;
                }
                $weightG = (int)($variant['weight_g'] ?? 0) > 0 ? (int)$variant['weight_g'] : $weightG;
                $lengthMm = (int)($variant['length_mm'] ?? 0) > 0 ? (int)$variant['length_mm'] : $lengthMm;
                $widthMm = (int)($variant['width_mm'] ?? 0) > 0 ? (int)$variant['width_mm'] : $widthMm;
                $heightMm = (int)($variant['height_mm'] ?? 0) > 0 ? (int)$variant['height_mm'] : $heightMm;
            } else {
                $price = (int)$product['price'];
                $sku = $product['sku'];
                $variantName = '';
                $stock = (int)$product['stock'];
            }

            $currency = $product['currency'] ?? 'eur';

            // Enforce single currency across the entire cart
            if ($cartCurrency === null) {
                $cartCurrency = $currency;
            }
            $currencyMismatch = ($currency !== $cartCurrency);

            $qty = $isDigital ? 1 : min((int)$item['qty'], $stock);
            if ($isDigital && (int)$item['qty'] !== 1) $_SESSION['cart'][$key]['qty'] = 1;
            $available = $availability === VariantAvailability::ACTIVE && ($isDigital || $qty > 0);
            if (!$available) $qty = (int)$item['qty']; // preserve requested qty for display

            $customerId = (int)($_SESSION['customer_id'] ?? 0);
            $pricingProduct = $product;
            $pricingProduct['price'] = $price;
            $pricingProduct = Pricing::applyPriceList(
                $pricingProduct,
                !empty($item['variant_id']) ? (int)$item['variant_id'] : null,
                max(1, $qty),
                $customerId > 0 ? $customerId : null
            );
            $pricingProduct = Promotions::applyToProduct(
                $pricingProduct,
                max(1, $qty),
                $customerId > 0 ? $customerId : null
            );
            $price = (int)$pricingProduct['price'];
            $pricingSnapshot = array_filter([
                'price_list' => $pricingProduct['price_list_snapshot'] ?? null,
                'promotions' => $pricingProduct['promotion_snapshot'] ?? null,
            ], static fn(mixed $value): bool => $value !== null);

            $total = $available ? $price * $qty : 0;
            // Only add to subtotal when currency matches — prevents mixed-currency totals
            if (!$currencyMismatch) {
                $subtotal += $total;
            }

            $images = json_decode($product['images'] ?? '[]', true);

            $items[] = [
                'key'              => $key,
                'product_id'       => $item['product_id'],
                'variant_id'       => $item['variant_id'],
                'sku'              => $sku,
                'name'             => $product['name'],
                'variant_name'     => $variantName,
                'slug'             => $product['slug'],
                'image'            => $images[0] ?? null,
                'price'            => $price,
                'price_formatted'  => I18n::price($price, $currency),
                'qty'              => $qty,
                'total'            => $total,
                'total_formatted'  => I18n::price($total, $currency),
                'stock'            => $stock,
                'product_type'     => $product['product_type'] ?? 'simple',
                'availability'     => $availability,
                'weight_g'         => $weightG,
                'length_mm'        => $lengthMm,
                'width_mm'         => $widthMm,
                'height_mm'        => $heightMm,
                'attrs'            => $pricingSnapshot === [] ? [] : ['_pricing' => $pricingSnapshot],
                'currency'         => $currency,
                'available'        => $available,
                'currency_mismatch' => $currencyMismatch,
                'vat_class'        => $product['vat_class'] ?? 'standard',
            ];
        }

        $currency = $cartCurrency ?? 'eur';
        $summary = [
            'items'              => $items,
            'subtotal'           => $subtotal,
            'subtotal_formatted' => I18n::price($subtotal, $currency),
            'currency'           => $currency,
            'count'              => array_sum(array_column($items, 'qty')),
        ];

        // Optional VAT enrichment (per-line + per-cart totals)
        if ($vatContext !== null) {
            $summary = self::applyVat($summary, $vatContext);
        }

        return $summary;
    }

    /**
     * Compute per-line VAT against an enriched cart summary using VatService.
     * Returns the summary with vat fields added to each item AND to the summary itself.
     * Pure function on top of enriched(); does not touch DB or session.
     */
    public static function applyVat(array $summary, array $vatContext): array {
        if (!class_exists('VatService')) {
            return $summary;
        }

        // Normalize context keys → VatService canonical
        $vctx = [
            'seller_country'        => $vatContext['seller_country']        ?? '',
            'customer_country'      => $vatContext['customer_country']      ?? '',
            'seller_vat_registered' => array_key_exists('seller_vat_registered', $vatContext) ? (bool)$vatContext['seller_vat_registered'] : true,
            'seller_oss_enabled'    => (bool)($vatContext['oss_enabled']           ?? $vatContext['seller_oss_enabled']    ?? false),
            'customer_vat_id_valid' => (bool)($vatContext['customer_vat_validated'] ?? $vatContext['customer_vat_id_valid'] ?? false),
            'is_b2b'                => (bool)($vatContext['customer_is_business']  ?? $vatContext['is_b2b']                ?? false),
        ];
        $taxProvider = Adapters::tax((string)($vatContext['tax_provider'] ?? Adapters::defaultProviderCode('tax')));
        $scenario = $taxProvider::resolveScenario($vctx);
        $atDate   = $vatContext['at_date'] ?? null;
        $rateCountry = $scenario['rate_country'];
        $applies  = $scenario['applies_vat'];

        $vatTotal = 0;
        $netTotal = 0;
        $newItems = [];

        foreach ($summary['items'] as $item) {
            // Currency-mismatch lines are excluded from totals (existing rule)
            if (!empty($item['currency_mismatch']) || empty($item['available'])) {
                $item['vat_rate']   = 0.0;
                $item['vat_amount'] = 0;
                $item['net_amount'] = $item['total'];
                $newItems[] = $item;
                continue;
            }

            $vatClass = $item['vat_class'] ?? 'standard';
            $resolved = method_exists($taxProvider, 'resolveClass') ? $taxProvider::resolveClass($vatClass) : $vatClass;
            $rate     = $applies ? $taxProvider::rate($rateCountry, $resolved, $atDate) : 0.0;

            $line = $taxProvider::computeLine(
                (int)$item['price'],
                (int)$item['qty'],
                $rate,
                !$applies
            );

            $item['vat_class']  = $resolved;
            $item['vat_rate']   = $rate;
            $item['vat_amount'] = $line['vat_amount'];
            $item['net_amount'] = $line['net'];
            $vatTotal += $line['vat_amount'];
            $netTotal += $line['net'];
            $newItems[] = $item;
        }

        $summary['items']                    = $newItems;
        $summary['vat_scenario']             = $scenario['scenario'];
        $summary['vat_rate_country']         = $rateCountry;
        $summary['vat_applies']              = $applies;
        $summary['vat_total']                = $vatTotal;
        $summary['net_total']                = $netTotal;
        $summary['reverse_charge_note_key']  = $scenario['note_key'];
        $summary['vat_total_formatted']      = I18n::price($vatTotal, $summary['currency']);
        $summary['net_total_formatted']      = I18n::price($netTotal, $summary['currency']);

        return $summary;
    }

    public static function clear(): void {
        unset($_SESSION['cart']);
    }

    /**
     * Restore cart from localStorage data (when session expired)
     */
    public static function restore(array $items): void {
        foreach ($items as $item) {
            $productId = Security::int($item['product_id'] ?? 0);
            $variantId = isset($item['variant_id']) && $item['variant_id']
                ? Security::int($item['variant_id']) : null;
            $qty = max(1, min(99, Security::int($item['qty'] ?? 1)));
            if ($productId > 0 && VariantAvailability::isPurchasable($productId, $variantId)) {
                self::add($productId, $variantId, $qty);
            }
        }
    }

    /**
     * Remove or reduce unavailable items from cart (after OOS checkout failure)
     */
    public static function removeUnavailable(array $outOfStockNames): void {
        $cart = self::get();
        foreach ($cart as $key => $item) {
            $product = Products::byId((int)($item['product_id'] ?? 0));
            if (!$product || $product['status'] !== 'active') {
                unset($_SESSION['cart'][$key]);
                continue;
            }
            $stock = (int)$product['stock'];
            if (!empty($item['variant_id'])) {
                $variants = Products::variants((int)$item['product_id']);
                foreach ($variants as $v) {
                    if ((int)$v['id'] === (int)$item['variant_id']) {
                        $stock = (int)$v['stock'];
                        break;
                    }
                }
            }
            if ($stock <= 0) {
                unset($_SESSION['cart'][$key]);
            } elseif ($item['qty'] > $stock) {
                $_SESSION['cart'][$key]['qty'] = $stock;
            }
        }
    }

    /**
     * Cart abandonment: save email and persist cart to DB for recovery
     */
    public static function setEmail(string $email): void {
        $_SESSION['cart_email'] = $email;
        $_SESSION['cart_email_at'] = time();

        try {
            AbandonedCarts::capture($email);
        } catch (\Throwable $e) {
            // Cart recovery tracking must never block checkout.
            error_log("Cart::setEmail persist failed: " . $e->getMessage());
        }
    }
}
