<?php
/**
 * BareBones Shop — Cart
 * Session-based with localStorage restore endpoint.
 */
class Cart {
    /** Most units of one line: every add/update path (JSON API, storefront form, reorder, restore) is capped here. */
    public const MAX_QTY = 99;
    /** unavailable_reason of a line whose effective unit price is negative (e.g. a variant delta that outgrew its base price). */
    public const UNAVAILABLE_PRICE_INVALID = 'price_invalid';
    /** @var array<string, true> SKUs already reported in this request */
    private static array $reportedInvalidPrices = [];

    /** Catalog data error, not shopper input: log the SKU and raise one incident per SKU and request. */
    private static function reportInvalidPrice(string $sku): void {
        if (isset(self::$reportedInvalidPrices[$sku])) return;
        self::$reportedInvalidPrices[$sku] = true;
        Logger::safeWrite('error', 'cart.negative_unit_price', ['sku' => $sku]);
        IncidentMonitor::report('catalog.negative_price', 'cart', 'error', ['count' => 1]);
    }

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
        $_SESSION['cart'][$key]['qty'] = min(self::MAX_QTY, (int)$_SESSION['cart'][$key]['qty'] + max(0, $qty));
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
        $key = $variantId === null ? "p_{$productId}" : "v_{$variantId}";
        $requested = (int)(self::get()[$key]['qty'] ?? 0) + max(1, $qty);
        self::add($productId, $variantId, max(1, min(self::MAX_QTY, $qty)));
        $summary = self::enriched();
        $item = null;
        foreach ($summary['items'] as $candidate) {
            if ($candidate['key'] === $key) { $item = $candidate; break; }
        }
        // The line holds at most MAX_QTY and, without backorders, the stock: report what it really holds.
        $quantity = $item === null ? 0 : (int)$item['qty'];
        return ['ok' => true, 'error' => null, 'item' => $item, 'count' => (int)$summary['count'],
            'quantity' => $quantity, 'clamped' => $item !== null && $quantity < $requested];
    }

    /**
     * Put the lines of a past order back into the cart. Every line goes through addValidated, so the
     * current catalog decides: today's price (the cart never stores one), availability and variants.
     * Lines that can no longer be bought are skipped and reported by name.
     */
    public static function reorder(array $orderItems): array {
        $added = [];
        $skipped = [];
        foreach ($orderItems as $item) {
            $variantId = (int)($item['variant_id'] ?? 0);
            $result = self::addValidated((int)$item['product_id'], $variantId > 0 ? $variantId : null, (int)$item['quantity']);
            if ($result['ok']) $added[] = (string)$item['name'];
            else $skipped[] = (string)$item['name'];
        }
        return ['added' => $added, 'skipped' => $skipped];
    }

    public static function update(string $key, int $qty): void {
        if ($qty <= 0) {
            unset($_SESSION['cart'][$key]);
        } else {
            if (isset($_SESSION['cart'][$key])) {
                $_SESSION['cart'][$key]['qty'] = min(self::MAX_QTY, $qty);
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
                    availability, available_from, inventory_policy
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
                    v.length_mm, v.width_mm, v.height_mm, v.inventory_policy
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
            // Same policy resolution as VariantAvailability::isPurchasable and Inventory::reserveSku.
            $policy = (string)($product['inventory_policy'] ?? '') ?: Inventory::DENY;
            if ($availability === VariantAvailability::ACTIVE && $policy !== Inventory::PREORDER
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
                // A negative delta can outgrow a later-lowered base price. Such a line is not sold
                // (unavailable, incident raised below) instead of being clamped to a free item.
                $price = $variant['price_absolute'] !== null
                    ? (int)$variant['price_absolute']
                    : (int)$product['price'] + (int)$variant['price_delta'];
                $sku = $variant['sku'];
                $variantName = $variant['name'];
                $stock = (int)$variant['stock'];
                $availability = (string)($variant['availability'] ?? VariantAvailability::ACTIVE);
                $variantPolicy = (string)($variant['inventory_policy'] ?? '');
                if ($variantPolicy !== '' && $variantPolicy !== 'inherit') $policy = $variantPolicy;
                if ($availability === VariantAvailability::ACTIVE && $policy !== Inventory::PREORDER
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

            // Backorder/preorder lines keep the requested quantity; the shortfall is frozen at reservation.
            $allowsShortfall = in_array($policy, [Inventory::BACKORDER, Inventory::PREORDER], true);
            $qty = $isDigital ? 1 : ($allowsShortfall ? (int)$item['qty'] : min((int)$item['qty'], $stock));
            if ($isDigital && (int)$item['qty'] !== 1) $_SESSION['cart'][$key]['qty'] = 1;
            $available = $availability === VariantAvailability::ACTIVE && ($isDigital || $qty > 0);
            if (!$available) $qty = (int)$item['qty']; // preserve requested qty for display

            $unavailableReason = $available ? null : 'not_available';
            $pricingProduct = $product;
            $pricingProduct['price'] = $price;
            if ($price >= 0) {
                $customerId = (int)($_SESSION['customer_id'] ?? 0);
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
                // Price-list and promotion percentages are charged in units the currency shows (whole Kč/Ft).
                $price = Money::round((int)$pricingProduct['price'], (string)$currency);
            }
            if ($price < 0) {
                // Never sell at 0 because of a clamp: the line stays visible but is not purchasable,
                // keeps its negative price (Orders::create refuses it too) and an incident is raised.
                $available = false;
                $unavailableReason = self::UNAVAILABLE_PRICE_INVALID;
                self::reportInvalidPrice((string)$sku);
            }
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
                'inventory_policy' => $policy,
                'weight_g'         => $weightG,
                'length_mm'        => $lengthMm,
                'width_mm'         => $widthMm,
                'height_mm'        => $heightMm,
                'attrs'            => $pricingSnapshot === [] ? [] : ['_pricing' => $pricingSnapshot],
                'currency'         => $currency,
                'available'        => $available,
                'unavailable_reason' => $available ? null : $unavailableReason,
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
            'customer_country_verified' => (bool)($vatContext['customer_country_verified'] ?? true),
        ];
        $taxProvider = Adapters::tax((string)($vatContext['tax_provider'] ?? Adapters::defaultProviderCode('tax')));
        $scenario = $taxProvider::resolveScenario($vctx);
        $atDate   = $vatContext['at_date'] ?? null;
        $rateCountry = $scenario['rate_country'];
        $applies  = $scenario['applies_vat'];
        // Catalog prices include the seller's domestic VAT. A zero-rated sale
        // (reverse charge, export) is charged that price without the VAT.
        $sellerCountry = strtoupper((string)$vctx['seller_country']);
        $stripDomesticVat = self::chargesNetOfDomesticVat($scenario);

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

            // The product's own category (books, medicines, ...) picks the rate: a country may tax it apart from its class.
            $vatClass = strtolower((string)($item['vat_class'] ?? 'standard'));
            $resolved = method_exists($taxProvider, 'resolveClass') ? $taxProvider::resolveClass($vatClass) : $vatClass;
            $rate     = $applies ? $taxProvider::rate($rateCountry, $vatClass, $atDate) : 0.0;
            if ($stripDomesticVat) {
                $unitNet = Money::round($taxProvider::computeLine((int)$item['price'], 1, $taxProvider::rate($sellerCountry, $vatClass, $atDate), false)['net'], (string)$summary['currency']);
                $summary['subtotal'] -= (int)$item['total'] - $unitNet * (int)$item['qty'];
                $item['price'] = $unitNet;
                $item['total'] = $unitNet * (int)$item['qty'];
                $item['price_formatted'] = I18n::price($item['price'], $summary['currency']);
                $item['total_formatted'] = I18n::price($item['total'], $summary['currency']);
            }

            $line = $taxProvider::computeLine(
                (int)$item['price'],
                (int)$item['qty'],
                $rate,
                !$applies
            );

            $item['vat_class']  = $resolved;
            $item['vat_category'] = $vatClass;
            $item['vat_rate']   = $rate;
            $item['vat_amount'] = $line['vat_amount'];
            $item['net_amount'] = $line['net'];
            $vatTotal += $line['vat_amount'];
            $netTotal += $line['net'];
            $newItems[] = $item;
        }

        $summary['items']                    = $newItems;
        $summary['subtotal_formatted']       = I18n::price($summary['subtotal'], $summary['currency']);
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

    /** Reverse charge and export are zero-rated: the buyer pays the price without the seller's domestic VAT. */
    public static function chargesNetOfDomesticVat(array $scenario): bool {
        return in_array((string)($scenario['scenario'] ?? ''), ['reverse_charge', 'export'], true);
    }

    /** Domestic VAT-inclusive amount → the same amount without the seller's domestic VAT. */
    public static function netOfDomesticVat(int $gross, string $sellerCountry, string $class = 'standard', ?string $taxProviderCode = null): int {
        $taxProvider = Adapters::tax($taxProviderCode ?? Adapters::defaultProviderCode('tax'));
        return $taxProvider::computeLine($gross, 1, $taxProvider::rate(strtoupper($sellerCountry), $class), false)['net'];
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
            $qty = max(1, min(self::MAX_QTY, Security::int($item['qty'] ?? 1)));
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
     * Cart abandonment: save email and persist cart to DB for recovery — only with the shopper's
     * reminder consent (AbandonedCarts::capture()).
     * @param array{version?:string,text?:string}|null $reminderConsent accepted cart-reminder consent, null = none
     */
    public static function setEmail(string $email, ?array $reminderConsent = null): void {
        $_SESSION['cart_email'] = $email;
        $_SESSION['cart_email_at'] = time();

        try {
            AbandonedCarts::capture($email, null, $reminderConsent);
        } catch (\Throwable $e) {
            // Cart recovery tracking must never block checkout.
            error_log("Cart::setEmail persist failed: " . $e->getMessage());
        }
    }
}
