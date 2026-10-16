<?php
/** BareBones Shop — JSON API handler functions loaded by the thin router. */

function apiCategories(): array {
    $locale = I18n::locale();
    $cached = Cache::readJson(Cache::localePath($locale, 'categories.json'), 3600);
    if ($cached) return $cached;

    $tree = Categories::tree();
    // Translate & enrich
    $tree = array_map(function($cat) {
        $cat = I18n::category($cat);
        $cat = Categories::enrich($cat);
        if (!empty($cat['children'])) {
            $cat['children'] = array_map(function($child) {
                return Categories::enrich(I18n::category($child));
            }, $cat['children']);
        }
        return $cat;
    }, $tree);

    $result = ['categories' => $tree];
    Cache::writeJson(Cache::localePath($locale, 'categories.json'), $result);
    return $result;
}

function apiProducts(): array {
    $catSlug = Security::slug($_GET['cat'] ?? '');
    $page = max(1, Security::int($_GET['page'] ?? 1));
    $sort = Security::slug($_GET['sort'] ?? 'price_asc');
    $filtersJson = Security::str($_GET['filters'] ?? '{}', '{}');
    $filters = json_decode($filtersJson, true);
    if (!is_array($filters)) $filters = [];
    $priceMin = isset($_GET['price_min']) ? Security::int($_GET['price_min']) : null;
    $priceMax = isset($_GET['price_max']) ? Security::int($_GET['price_max']) : null;

    $cfg = require dirname(__DIR__, 2) . '/config.php';
    $perPage = $cfg['per_page'] ?? 24;

    // Get category
    $category = Categories::bySlug($catSlug);
    if (!$category) {
        return ['error' => 'Category not found', 'products' => [], 'total' => 0];
    }

    // Personalized price lists and promotions must never share a guest cache entry.
    $locale = I18n::locale();
    $cacheable = empty($_SESSION['customer_id']) && empty($filters) && $page === 1
        && !$priceMin && !$priceMax && $sort === 'price_asc';
    if ($cacheable) {
        $cached = Cache::readJson(Cache::localePath($locale, "cat/{$catSlug}/page-1.json"), 3600);
        if ($cached) return $cached;
    }

    $data = Products::byCategory($category['id'], $page, $perPage, $sort, $filters, $priceMin, $priceMax);

    $data['products'] = Products::enrichMany(array_map([I18n::class, 'product'], $data['products']));

    $data['category'] = Categories::enrich(I18n::category($category));

    if ($cacheable) {
        Cache::writeJson(Cache::localePath($locale, "cat/{$catSlug}/page-1.json"), $data);
    }

    return $data;
}

function apiProduct(): array {
    $slug = Security::slug($_GET['slug'] ?? '');
    if (!$slug) return ['error' => 'Missing slug'];

    $locale = I18n::locale();

    // Personalized prices must neither read nor populate the shared guest cache.
    $cacheable = empty($_SESSION['customer_id']);
    if ($cacheable) {
        $cached = Cache::readJson(Cache::localePath($locale, "product/{$slug}.json"), 3600);
        if ($cached) return $cached;
    }

    $product = Products::bySlug($slug);
    if (!$product) return ['error' => 'Product not found'];

    $product = Products::enrich(I18n::product($product));
    $product['variants'] = Products::variants($product['id']);
    $product['attributes'] = Products::attrs($product['id']);

    $result = ['product' => $product];

    if ($cacheable) Cache::writeJson(Cache::localePath($locale, "product/{$slug}.json"), $result);
    return $result;
}

function apiSearch(): array {
    $q = trim(Security::str($_GET['q'] ?? ''));
    $page = max(1, Security::int($_GET['page'] ?? 1));

    if (strlen($q) < 2) {
        return ['products' => [], 'total' => 0, 'query' => $q];
    }

    $data = Adapters::search()->search($q, ['page' => $page]);
    $data['products'] = Products::enrichMany(array_map([I18n::class, 'product'], $data['products']));

    return $data;
}

function apiSearchSuggest(): array {
    $q = trim(Security::str($_GET['q'] ?? ''));
    if (strlen($q) < 2) return [];
    // url: the store-aware product link, so the theme never builds paths itself.
    return array_map(static fn(array $row): array => $row + ['url' => StorefrontViewModel::productUrl((string)($row['slug'] ?? ''))], Adapters::search()->suggest($q, 5));
}

function apiValidateCoupon(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }

    if (!Security::csrfValidate()) {
        http_response_code(403);
        return ['error' => 'Invalid CSRF token'];
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $code = trim(Security::str($input['code'] ?? ''));
    $subtotal = Security::int($input['subtotal'] ?? 0);
    $email = Security::email($input['email'] ?? '') ?? null;

    if (!$code) return ['valid' => false, 'error' => 'Coupon code is required'];

    // Same inputs and rules as checkout: the cart's available goods, its automatic promotions, then the coupon.
    $items = array_values(array_filter(Cart::enriched()['items'] ?? [], static fn(array $item): bool => $item['available'] ?? true));
    if ($items) $subtotal = (int)array_sum(array_column($items, 'total'));
    $promotionDiscount = 0;
    foreach (Pricing::autoCartRules($subtotal, $items, !empty($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : null) as $rule) {
        $promotionDiscount += max(0, (int)($rule['discount'] ?? 0));
    }
    $result = CheckoutService::couponFor($code, $subtotal, $promotionDiscount, $email);
    if ($result['discount'] <= 0) {
        return ['valid' => false, 'error' => $result['error'] ?? 'Invalid coupon'];
    }
    $combined = Pricing::combineDiscounts((int)$result['discount'], (string)($result['stacking'] ?? 'stack'), $promotionDiscount);
    if ($combined['coupon'] === 0) {
        return ['valid' => false, 'error' => 'Coupon cannot be combined with the current promotion'];
    }

    return [
        'valid'    => true,
        'code'     => $code,
        'discount' => $combined['coupon'],
        'name'     => $result['name'] ?? $code,
    ];
}

function apiReviewSubmit(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }
    if (!Security::csrfValidate()) {
        http_response_code(403);
        return ['error' => 'Invalid CSRF token'];
    }
    // Stricter throttle than the generic api bucket — review POSTs are a spam target.
    // Reviews are moderated (always land pending), so a modest per-minute/IP cap suffices.
    if (!Security::rateLimit('review_submit', 20)) {
        http_response_code(429);
        return ['ok' => false, 'error' => 'rate_limited'];
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $productId = Security::int($input['product_id'] ?? 0);
    if ($productId <= 0 || !Products::byId($productId)) {
        return ['ok' => false, 'error' => 'invalid_product'];
    }

    $data = [
        'rating' => Security::int($input['rating'] ?? 0),
        'title'  => (string)($input['title'] ?? ''),
        'body'   => (string)($input['body'] ?? ''),
    ];

    // Logged-in customer: attribute the review and use the account name.
    $customerId = (int)($_SESSION['customer_id'] ?? 0);
    if ($customerId > 0) {
        $data['customer_id'] = $customerId;
        $c = Customers::byId($customerId);
        $data['author_name'] = trim((string)($c['name'] ?? '')) !== ''
            ? $c['name']
            : (string)($input['author_name'] ?? '');
    } else {
        $data['author_name'] = (string)($input['author_name'] ?? '');
    }

    [$ok, $id, $err] = Reviews::submit($productId, $data);
    if (!$ok) {
        return ['ok' => false, 'error' => $err];
    }
    // Reviews always land as pending — never visible until an admin approves.
    return ['ok' => true, 'id' => $id, 'status' => 'pending'];
}

function apiWishlist(): array {
    // Read-only: current owner's saved product ids + count.
    return [
        'ok'    => true,
        'ids'   => Wishlist::ids(),
        'count' => Wishlist::count(),
    ];
}

function apiWishlistToggle(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }
    if (!Security::csrfValidate()) {
        http_response_code(403);
        return ['error' => 'Invalid CSRF token'];
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $productId = Security::int($input['product_id'] ?? 0);
    if ($productId <= 0 || !Products::byId($productId)) {
        return ['ok' => false, 'error' => 'invalid_product'];
    }

    $state = Wishlist::toggle($productId);
    return [
        'ok'      => true,
        'state'   => $state,            // 'added' | 'removed'
        'in_list' => $state === 'added',
        'count'   => Wishlist::count(),
    ];
}

function apiFilters(): array {
    $catSlug = Security::slug($_GET['cat'] ?? '');
    $category = Categories::bySlug($catSlug);
    if (!$category) return ['filters' => []];

    $locale = I18n::locale();
    $cached = Cache::readJson(Cache::localePath($locale, "cat/{$catSlug}/filters.json"), 3600);
    if ($cached) return $cached;

    $raw = Products::filterValues($category['id']);

    // Translate labels
    $filters = [];
    foreach ($raw as $attrName => $options) {
        $filters[] = [
            'attr_name' => $attrName,
            'label'     => I18n::attrLabel($attrName),
            'options'   => array_map(function($opt) use ($attrName) {
                return [
                    'value' => $opt['value'],
                    'label' => I18n::attr($attrName, $opt['value']),
                    'count' => $opt['count'],
                ];
            }, $options),
        ];
    }

    $result = ['filters' => $filters];
    Cache::writeJson(Cache::localePath($locale, "cat/{$catSlug}/filters.json"), $result);
    return $result;
}

function apiCart(): array {
    return Cart::enriched();
}

function apiCartAdd(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }

    if (!Security::csrfValidate()) {
        http_response_code(403);
        return ['error' => 'Invalid CSRF token'];
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $productId = Security::int($input['product_id'] ?? 0);
    $variantId = isset($input['variant_id']) ? Security::int($input['variant_id']) : null;
    $qty = max(1, min(99, Security::int($input['qty'] ?? 1)));

    if ($productId <= 0) {
        return ['error' => 'Invalid product'];
    }

    // Verify product exists and has stock
    $product = Products::byId($productId);
    if (!$product || $product['status'] !== 'active') {
        return ['error' => 'Product not found'];
    }
    if (!VariantAvailability::isPurchasable($productId, $variantId)) {
        http_response_code(409);
        return ['error' => 'Product variant is not available'];
    }

    Cart::add($productId, $variantId, $qty);

    return ['success' => true, 'cart' => apiCart()];
}

function apiCartUpdate(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }

    if (!Security::csrfValidate()) {
        http_response_code(403);
        return ['error' => 'Invalid CSRF token'];
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $key = $input['key'] ?? '';
    if (!preg_match('/^(p|v)_\d{1,10}$/', $key)) {
        return ['error' => 'Invalid cart key'];
    }
    $qty = min(99, Security::int($input['qty'] ?? 0));

    Cart::update($key, $qty);

    return ['success' => true, 'cart' => apiCart()];
}

function apiCartRestore(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }

    if (!Security::csrfValidate()) {
        http_response_code(403);
        return ['error' => 'Invalid CSRF token'];
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!is_array($input)) return ['error' => 'Invalid data'];

    Cart::restore($input);

    return ['success' => true, 'cart' => apiCart()];
}

function apiCartEnriched(): array {
    return Cart::enriched();
}

function apiShippingMethods(): array {
    return apiShippingRates();
}

function apiShippingRates(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }

    $country = strtoupper(trim(Security::str($_GET['country'] ?? 'SK')));
    if (!preg_match('/^[A-Z]{2}$/', $country)) {
        return ['error' => 'Invalid country'];
    }
    // A goods amount of 0 (a coupon covering the whole cart) is a real amount, not a missing one.
    $subtotalGiven = isset($_GET['subtotal']) && $_GET['subtotal'] !== '';
    $subtotal = Security::int($_GET['subtotal'] ?? 0);
    $cart = Cart::enriched();
    if (cartIsDigitalOnly($cart['items'] ?? [])) {
        return [
            'country' => $country,
            'weight_g' => 0,
            'volume_cm3' => 0,
            'delivery_type' => 'digital',
            'methods' => [digitalDeliveryRate()],
        ];
    }
    $weightG = Security::int($_GET['weight_g'] ?? 0);
    $volumeCm3 = Security::int($_GET['volume_cm3'] ?? 0);
    $cod = !empty($_GET['cod']) && $_GET['cod'] !== '0';

    if ($weightG <= 0) {
        $cart = Cart::enriched();
        $metrics = shippingCartMetrics($cart['items'] ?? []);
        $subtotal = $subtotalGiven ? $subtotal : (int)($cart['subtotal'] ?? 0);
        $weightG = $metrics['weight_g'];
        $volumeCm3 = $metrics['volume_cm3'];
    }

    $physicalQty = ShippingService::physicalQuantity($cart['items'] ?? []);
    $methods = ShippingService::calculateRates($country, $weightG, $subtotal, $cod, $volumeCm3, $physicalQty);
    // An automatic free-shipping promotion zeroes the delivery checkout charges, so the preview shows the same.
    $customerId = (int)($_SESSION['customer_id'] ?? 0);
    if (CheckoutService::discountPreview('', (int)($cart['subtotal'] ?? 0), null, $cart['items'] ?? [], $customerId ?: null)['free_shipping']) {
        $methods = array_map(static fn(array $method): array => ['price' => 0] + $method, $methods);
    }
    $unitsToFree = ShippingService::unitsToFreeShipping($methods, $physicalQty);
    return [
        'country' => $country,
        'weight_g' => $weightG,
        'volume_cm3' => $volumeCm3,
        'physical_qty' => $physicalQty,
        'units_to_free_shipping' => $unitsToFree,
        'free_shipping_hint' => ShippingService::freeShippingHint($unitsToFree),
        'methods' => $methods,
    ];
}

function apiPaymentMethods(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }

    $country = strtoupper(trim(Security::str($_GET['country'] ?? 'SK')));
    if (!preg_match('/^[A-Z]{2}$/', $country)) {
        return ['error' => 'Invalid country'];
    }
    $currency = strtolower(trim(Security::str($_GET['currency'] ?? 'eur')));
    if (!preg_match('/^[a-z]{3}$/', $currency)) {
        return ['error' => 'Invalid currency'];
    }
    $amount = Security::int($_GET['amount'] ?? 0);
    $shippingMethod = Security::slug($_GET['shipping_method'] ?? '');
    $cart = Cart::enriched();
    $digitalOnly = cartIsDigitalOnly($cart['items'] ?? []);
    if ($digitalOnly) {
        $shippingMethod = 'digital';
        // The goods amount after discounts is respected (0 = a coupon covers the cart); only a missing one falls back to the cart.
        if (!isset($_GET['amount']) || $_GET['amount'] === '') $amount = (int)($cart['subtotal'] ?? 0);
    }
    $customerId = (int)($_SESSION['customer_id'] ?? 0);
    $methods = $digitalOnly && $amount === 0
        ? [freeDigitalPayment()]
        : PaymentService::availableMethods($country, $currency, $amount, $shippingMethod, $customerId > 0 ? $customerId : null);
    if ($digitalOnly) {
        $methods = array_values(array_filter($methods, static fn(array $method): bool => ($method['service_type'] ?? '') !== 'cod'));
    }

    return [
        'country' => $country,
        'currency' => $currency,
        'amount' => $amount,
        'shipping_method' => $shippingMethod,
        'methods' => $methods,
    ];
}

/**
 * Resolve the active store from stores.json by current locale.
 * Pass 1: store whose PRIMARY language (languages[0]) matches the locale.
 * Pass 2: store whose languages list contains the locale.
 * Fallback: first store.
 */
function apiActiveStore(): array {
    return StoreContext::current()->config();
}

function apiConsentDefinitions(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }
    $country = strtoupper(trim((string)($_GET['country'] ?? '')));
    $cart = Cart::enriched();
    return ['consents' => array_values(CheckoutService::consentDefinitions(preg_match('/^[A-Z]{2}$/', $country) ? $country : '', $cart['items'] ?? []))];
}

function apiCheckout(?array $runtimeConfig = null): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }

    if (!Security::csrfValidate()) {
        http_response_code(403);
        return ['error' => 'Invalid CSRF token'];
    }

    if (!Security::rateLimit('checkout', 10)
        || !Security::rateLimit('checkout_global', 200, 60, false)
        || !Security::rateLimit('checkout_session_' . hash('sha256', session_id()), 5, 900, false)) {
        return ['error' => 'Too many checkout attempts'];
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    if (!is_array($input)) {
        http_response_code(400);
        return ['error' => 'Invalid request body'];
    }
    return CheckoutService::execute($input, $runtimeConfig);
}

function checkoutExecute(array $input, ?array $runtimeConfig = null): array {
    $name = trim(Security::str($input['name'] ?? ''));
    $email = Security::email($input['email'] ?? '');
    $phone = trim(Security::str($input['phone'] ?? ''));
    $country = strtoupper(trim(Security::str($input['country'] ?? 'SK')));
    if (!preg_match('/^[A-Z]{2}$/', $country)) {
        return ['error' => 'Invalid country'];
    }
    $shippingMethod = Security::slug($input['shipping_method'] ?? '');
    $paymentMethod = $input['payment_method'] ?? 'stripe';
    $loggedInCustomerId = !empty($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : null;

    if (!$name || !$email) {
        return ['error' => 'Name and email are required'];
    }
    $checkoutSubject = !empty($_SESSION['customer_id'])
        ? 'customer_' . (int)$_SESSION['customer_id']
        : 'email_' . hash('sha256', strtolower($email));
    if (!Security::rateLimit('checkout_subject_' . $checkoutSubject, 3, 1800, false)) {
        return ['error' => 'Too many checkout attempts'];
    }

    // $cfg must be loaded here: apiCheckout is a function, the file-scope $cfg is not
    // visible (it used to be silently undefined → seller always 'SK', OSS always off,
    // admin notification never sent). Seller identity lives per-store in stores.json.
    $cfg = $runtimeConfig ?? require dirname(__DIR__, 2) . '/config.php';
    $synthetic = SyntheticCheckout::isRequest($cfg);

    // Capture email for abandoned cart recovery
    if (!$synthetic) Cart::setEmail($email);

    if (!preg_match('/^[a-z0-9_-]{2,40}$/', $paymentMethod)) {
        return ['error' => 'Invalid payment method'];
    }

    // VAT context — resolve scenario before enriching cart so per-line VAT is captured.
    StoreContext::current()->assertPermission('tax');
    $store = apiActiveStore();
    // Company buyer: name, IČO, DIČ and IČ DPH go onto the order address the invoice is issued to.
    $buyerCompany = CheckoutService::buyerCompany($input);
    if ($buyerCompany['error'] !== null) {
        http_response_code(422);
        return ['success' => false, 'error' => $buyerCompany['error']];
    }
    $vatId = $buyerCompany['vat_id'];
    $sellerCountry = strtoupper((string)(($store['legal']['country'] ?? null) ?: 'SK'));
    $sellerVatRegistered = array_key_exists('vat_registered', $store['legal'] ?? [])
        ? (bool)$store['legal']['vat_registered']
        : true;
    $sellerOss     = (bool)($store['oss_enabled'] ?? false);
    $vatIdValid    = false;
    $vatIdStatus   = null;
    if ($vatId !== '') {
        try {
            $vatRes = VatService::validateVatId($vatId, VatService::defaultValidator());
            $vatIdValid  = ($vatRes['status'] === 'valid');
            $vatIdStatus = $vatRes['status'];
        } catch (\Throwable $e) {
            IncidentMonitor::report('checkout.vat_verification_failed', 'tax', 'warning', ['exception' => $e]);
            $vatIdStatus = 'unverified';
        }
        // Persist VAT ID on logged-in customer so future orders remember it
        Security::sessionStart();
        if (!empty($_SESSION['customer_id'])) {
            Customers::update((int)$_SESSION['customer_id'], [
                'vat_id'           => $vatId,
                'vat_country'      => substr($vatId, 0, 2),
                'vat_validated_at' => $vatIdValid ? date('Y-m-d H:i:s') : null,
            ]);
        }
    }
    $vatContext = [
        'seller_country'        => $sellerCountry,
        'seller_vat_registered' => $sellerVatRegistered,
        'seller_oss_enabled'    => $sellerOss,
        'customer_country'      => $country,
        // Reverse charge needs a VAT ID of another member state; Greece's prefix is EL.
        'customer_vat_id_valid' => $vatIdValid && (substr($vatId, 0, 2) === 'EL' ? 'GR' : substr($vatId, 0, 2)) !== $sellerCountry,
        'is_b2b'                => $vatId !== '',
    ];
    $taxProviderCode = Adapters::defaultProviderCode('tax');
    $taxProvider = Adapters::tax($taxProviderCode);
    $vatContext['tax_provider'] = $taxProviderCode;
    $vatScenario = $taxProvider::resolveScenario($vatContext);

    // Cart preview and the immutable order snapshot use the same canonical
    // provider inputs. No downstream consumer resolves the scenario again.
    $cart = Cart::enriched($vatContext);
    if (empty($cart['items'])) {
        return ['error' => 'Cart is empty'];
    }
    $availableItems = array_values(array_filter($cart['items'], static fn(array $item): bool => $item['available'] ?? true));
    if (empty($availableItems)) {
        return ['success' => false, 'error' => 'Žiadne dostupné produkty v košíku.'];
    }
    // One order is charged in one currency; a mixed cart is refused, never summed into the first line's currency.
    if (count(array_unique(array_map(static fn(array $item): string => strtolower((string)($item['currency'] ?? 'eur')), $availableItems))) > 1) {
        http_response_code(422);
        return ['success' => false, 'error' => 'Košík obsahuje produkty v rôznych menách. Objednajte ich samostatne.'];
    }
    $cart['items'] = $availableItems;
    $cart['subtotal'] = array_sum(array_column($availableItems, 'total'));
    $digitalOnly = cartIsDigitalOnly($availableItems);
    if ($digitalOnly) {
        $shippingMethod = 'digital';
    } elseif (trim((string)($input['street'] ?? '')) === '' || trim((string)($input['city'] ?? '')) === '' || trim((string)($input['zip'] ?? '')) === '') {
        return ['success' => false, 'error' => 'Doručovacia adresa je povinná pri fyzických produktoch.'];
    }

    $consentContext = [
        'country' => $country,
        'product_types' => array_values(array_unique(array_column($cart['items'], 'product_type'))),
    ];
    $submittedConsents = is_array($input['consents'] ?? null) ? $input['consents'] : [];
    $consentErrors = ConsentService::validate($submittedConsents, $consentContext);
    if ($consentErrors) {
        http_response_code(422);
        return ['success' => false, 'error' => 'Required consent missing', 'consent_errors' => $consentErrors];
    }

    // Automatic promotions first; the coupon is then priced on what they leave (CheckoutService::couponFor).
    $autoRules = Pricing::autoCartRules($cart['subtotal'], $cart['items'], $loggedInCustomerId);
    $promotionDiscount = 0;
    $promotionFreeShipping = false;
    foreach ($autoRules as $rule) {
        if ($rule['action'] === 'free_shipping') $promotionFreeShipping = true;
        $promotionDiscount += max(0, (int)($rule['discount'] ?? 0));
    }

    // Apply coupon if provided
    $couponCode = trim(Security::str($input['coupon_code'] ?? ''));
    $couponDiscount = 0;
    $couponStacking = 'stack';
    if ($couponCode) {
        $couponResult = CheckoutService::couponFor($couponCode, $cart['subtotal'], $promotionDiscount, $email);
        if ($couponResult['discount'] > 0) {
            $couponDiscount = $couponResult['discount'];
            $couponStacking = (string)($couponResult['stacking'] ?? 'stack');
        } else {
            return ['error' => $couponResult['error'] ?? 'Invalid coupon'];
        }
    }
    $combinedDiscounts = Pricing::combineDiscounts($couponDiscount, $couponStacking, $promotionDiscount);
    if ($couponDiscount > 0 && $combinedDiscounts['coupon'] === 0) {
        return ['success' => false, 'error' => 'Coupon cannot be combined with the current promotion'];
    }
    $promotionDiscount = $combinedDiscounts['promotion'];
    $totalDiscount = min($cart['subtotal'], $couponDiscount + $promotionDiscount);

    $metrics = shippingCartMetrics($cart['items']);
    $totalWeight = $digitalOnly ? 0 : $metrics['weight_g'];
    $totalVolume = $digitalOnly ? 0 : $metrics['volume_cm3'];
    // Free shipping "from X" is earned by what the buyer pays for the goods, i.e. after discounts.
    $selectedRate = $digitalOnly
        ? digitalDeliveryRate()
        : ShippingService::selectRate($shippingMethod, $country, $totalWeight, $cart['subtotal'] - $totalDiscount, PaymentService::isCashOnDelivery($paymentMethod), $totalVolume, ShippingService::physicalQuantity($cart['items']));
    if (!$selectedRate) {
        return ['success' => false, 'error' => 'Doručenie nie je dostupné do zvolenej krajiny.'];
    }
    if (!$digitalOnly) {
        // Point-delivery carriers: the chosen pick-up point must be on the carrier's list for this address.
        $pickupInput = $input['pickup_point'] ?? '';
        $pickup = ShippingService::choosePickupPoint($selectedRate, $country, trim(Security::str($input['zip'] ?? '')), is_string($pickupInput) ? $pickupInput : '');
        if ($pickup['error'] !== null) return ['success' => false, 'error' => I18n::t($pickup['error'])];
        if ($pickup['point'] !== null) $selectedRate['pickup_point'] = $pickup['point'];
    }
    $shippingCost = $promotionFreeShipping ? 0 : (int)$selectedRate['price'];
    // Shipping rates include domestic VAT like catalog prices; zero-rated sales are charged them net.
    if ($shippingCost > 0 && Cart::chargesNetOfDomesticVat($vatScenario)) {
        $shippingCost = Cart::netOfDomesticVat($shippingCost, $sellerCountry, 'standard', $taxProviderCode);
    }

    $currency = $cart['items'][0]['currency'] ?? 'eur';
    $paymentBaseAmount = max(0, $cart['subtotal'] - $totalDiscount) + $shippingCost;
    $freeDigitalOrder = $digitalOnly && $paymentBaseAmount === 0;
    if ($freeDigitalOrder) {
        $paymentMethod = 'free';
        $selectedPayment = freeDigitalPayment();
    } else {
        $selectedPayment = PaymentService::selectMethod($paymentMethod, $country, $currency, $paymentBaseAmount, $selectedRate['method_code'], $loggedInCustomerId);
    }
    if (!$selectedPayment || ($digitalOnly && ($selectedPayment['service_type'] ?? '') === 'cod')) {
        return ['success' => false, 'error' => 'Platba nie je dostupná pre digitálne doručenie.'];
    }
    if ($synthetic && empty($selectedPayment['requires_online'])) {
        // The smoke test exists to prove the gateway redirect; anything else would be a real order.
        http_response_code(422);
        return ['success' => false, 'error' => 'Synthetic checkout requires an online payment method'];
    }
    $paymentFee = PaymentService::chargedFee($selectedPayment, Cart::chargesNetOfDomesticVat($vatScenario), $sellerCountry, $taxProviderCode);
    $providerCodes = [
        'tax' => $taxProviderCode,
        'payment' => (string)$selectedPayment['provider'],
        'shipping' => (string)($selectedRate['provider'] ?? $selectedRate['carrier'] ?? 'none'),
        'mail' => Adapters::defaultProviderCode('mail'),
    ];
    if (Adapters::integration() !== null) $providerCodes['erp'] = Adapters::defaultProviderCode('erp');
    $financialSnapshot = $taxProvider::buildSnapshot($availableItems, $vatContext, [
        'discount' => $totalDiscount,
        'shipping' => $shippingCost,
        'payment_fee' => $paymentFee,
        'currency' => $currency,
        'effective_date' => date('Y-m-d'),
        'providers' => $providerCodes,
    ]);

    // Attach logged-in customer_id if a session is active (guest checkout keeps customer_id=null)
    Security::sessionStart();

    $customer = [
        'customer_id'    => $loggedInCustomerId,
        'consents' => $submittedConsents,
        'consent_context' => $consentContext,
        'name'           => $name,
        'email'          => $email,
        'phone'          => $phone,
        'address'        => [
            'street'  => $digitalOnly ? '' : trim(Security::str($input['street'] ?? '')),
            'city'    => $digitalOnly ? '' : trim(Security::str($input['city'] ?? '')),
            'zip'     => $digitalOnly ? '' : trim(Security::str($input['zip'] ?? '')),
            'country' => $country,
        ] + $buyerCompany['fields'],
        'currency'       => $currency,
        'locale'         => I18n::locale(),
        'shipping_cost'  => $shippingCost,
        'payment_fee'    => $paymentFee,
        'payment_method_snapshot' => json_encode($selectedPayment, JSON_UNESCAPED_UNICODE),
        'shipping_method'=> $selectedRate['method_code'],
        'shipping_zone'  => $selectedRate['zone_code'] ?? '',
        'shipping_weight_g' => $totalWeight,
        'shipping_rate_snapshot' => json_encode($selectedRate, JSON_UNESCAPED_UNICODE),
        'tax_amount'     => $financialSnapshot['totals']['vat'],
        'coupon_discount'=> $totalDiscount,
        'financial_snapshot' => $financialSnapshot,
        'vat_id'         => $vatId,
        'vat_country'    => $vatId !== '' ? substr($vatId, 0, 2) : '',
        'vat_id_status'  => $vatIdStatus,
        'vat_scenario'   => $vatScenario['scenario'],
        // Buyer note from the HTML form and JSON API: plain text, control characters removed, bounded.
        'notes'          => is_string($input['notes'] ?? null)
            ? mb_substr(trim((string)preg_replace('/[^\P{C}\n\t]/u', '', $input['notes'])), 0, 2000)
            : '',
        'is_synthetic'   => $synthetic,
    ];

    // Pass coupon info for atomic recording inside the order transaction
    $coupon = ($couponCode && $couponDiscount > 0)
        ? ['code' => $couponCode, 'discount' => $couponDiscount]
        : null;

    try {
        $orderId = Orders::create($availableItems, $customer, $paymentMethod, $coupon);

        if ($freeDigitalOrder) {
            PaymentService::updatePaymentStatus($orderId, 'paid');
            OrderWorkflow::transition($orderId, 'paid', 'Bezplatná digitálna objednávka');
        }

        AbandonedCarts::markConverted($orderId);
        Cart::clear();
        // Invalidate the submitted CSRF value after the irreversible create so
        // an exact browser/network replay cannot reserve stock a second time.
        Security::rotateCsrfToken();

        $order = Orders::byId($orderId);
        $orderItems = Orders::items($orderId);
        $confirmationToken = CheckoutSession::createConfirmation($orderId, CheckoutSession::browserSessionId());
        // For COD/bank_transfer: send confirmation immediately (no webhook will fire)
        // For Stripe: confirmation email is sent from the webhook after payment is confirmed
        try {
            if (!$freeDigitalOrder && empty($selectedPayment['requires_online'])) {
                JobQueue::push('email_send', ['template' => 'order_confirmation', 'to' => $email, 'order_id' => $orderId]);
            }
            // Merchant hears about every order at creation, including unpaid bank transfers.
            if (!$synthetic) JobQueue::push('merchant_notify', ['order_id' => $orderId, 'event' => 'created'], 6);
        } catch (Throwable $e) {
            IncidentMonitor::report('checkout.notification_failed', 'notification', 'critical', ['order_id' => $orderId, 'exception' => $e]);
        }

        $result = [
            'success'      => true,
            'order_id'     => $orderId,
            'order_number' => $order['order_number'],
            'total'        => (int)$order['total'],
            'confirmation_token' => $confirmationToken,
        ];
        $bankTransfer = BankTransferInstructions::forApi($order);
        if ($bankTransfer !== null) $result['bank_transfer'] = $bankTransfer;

        // For online payments, create a payment session
        if (!empty($selectedPayment['requires_online'])) {
            try {
                $paymentProvider = (string)$selectedPayment['provider'];
                $payment = Adapters::payment($paymentProvider);
                $baseUrl = Security::appUrl($cfg);
                $session = PaymentService::initiate([
                    'order_id'       => $orderId,
                    'order_number'   => $order['order_number'],
                    'total'          => (int)$order['total'],
                    'customer_email' => $email,
                    'customer_name'  => (string)($order['customer_name'] ?? ''),
                    'locale'         => (string)($order['locale'] ?? ''),
                    'currency'       => $order['currency'] ?? 'eur',
                    'items'          => $orderItems,
                    'shipping_cost'  => (int)$order['shipping_cost'],
                    'financial_snapshot_id' => $financialSnapshot['fingerprint'],
                    'success_url'    => $baseUrl . '/?page=order-done&order=' . rawurlencode($order['order_number']) . '&confirmation_token=' . rawurlencode($confirmationToken) . '&session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url'     => $baseUrl . '/?page=checkout-cancel&order=' . rawurlencode($order['order_number']),
                    // Provider-specific notification endpoint (PayU notifyUrl, GoPay notification_url, ...).
                    'notify_url'     => $baseUrl . '/api.php?action=webhook_payment&provider=' . rawurlencode($paymentProvider),
                ], $payment, $paymentProvider);
                $result['payment_url'] = $session['url'];
                $result['session_id'] = $session['session_id'];
                CheckoutSession::rememberPendingPayment($orderId, (string)$order['order_number'], (string)$session['url'], $confirmationToken);

                // Persist the provider session separately so payment_id may later
                // be replaced by the payment intent without losing ownership proof.
                if (!empty($session['session_id'])) {
                    CheckoutSession::bind($orderId, (string)$session['session_id'], $paymentProvider, session_id());
                    Db::get()->prepare("UPDATE orders SET payment_id = :pid WHERE id = :id")
                        ->execute([':pid' => $session['session_id'], ':id' => $orderId]);
                }
            } catch (\Throwable $e) {
                // Payment adapter error — order still created, log and return
                IncidentMonitor::report('payment.session_failed', 'payment', 'critical', ['order_id' => $orderId, 'exception' => $e]);
                $result['payment_error'] = 'Payment processing unavailable. Please try again.';
            }
        }
        if ($synthetic) {
            SyntheticCheckout::close($orderId);
            $result['synthetic'] = true;
        }

        return $result;
    } catch (OrderRejectedException $e) {
        Cart::removeUnavailable([]);
        return ['success' => false, 'error' => I18n::t('stock_changed')];
    }
}

function cartIsDigitalOnly(array $items): bool {
    if (empty($items)) return false;
    foreach ($items as $item) {
        if (!VariantAvailability::isDigitalType((string)($item['product_type'] ?? ''))) return false;
    }
    return true;
}

function digitalDeliveryRate(): array {
    return [
        'method_code' => 'digital',
        'carrier' => 'digital',
        'provider' => 'none',
        'label' => 'Elektronicky — odkaz na stiahnutie e-mailom',
        'description' => 'Bez fyzickej dopravy',
        'service_type' => 'digital',
        'zone_code' => 'digital',
        'price' => 0,
        'cod_surcharge' => 0,
        'delivery_days' => 'ihneď po úhrade',
        'cod' => false,
    ];
}

function freeDigitalPayment(): array {
    return [
        'code' => 'free',
        'provider' => 'none',
        'label' => 'Bez platby — objednávka je zdarma',
        'description' => 'Bezplatná digitálna objednávka',
        'service_type' => 'free',
        'requires_online' => false,
        'fee' => 0,
    ];
}

function shippingCartMetrics(array $items): array {
    $productIds = array_unique(array_column($items, 'product_id'));
    $weights = [];
    $attrs = [];
    if (!empty($productIds)) {
        $ph = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = Db::get()->prepare("SELECT id, weight_g, attrs FROM products WHERE id IN ({$ph})");
        $stmt->execute(array_values($productIds));
        while ($row = $stmt->fetch()) {
            $pid = (int)$row['id'];
            $weights[$pid] = (int)($row['weight_g'] ?? 0);
            $attrs[$pid] = json_decode($row['attrs'] ?? '{}', true) ?: [];
        }
    }

    $weightG = 0;
    $volumeCm3 = 0;
    foreach ($items as $item) {
        $pid = (int)$item['product_id'];
        $qty = (int)$item['qty'];
        $itemWeight = array_key_exists('weight_g', $item) ? (int)$item['weight_g'] : ($weights[$pid] ?? 0);
        $weightG += $itemWeight * $qty;
        $length = (int)($item['length_mm'] ?? 0);
        $width = (int)($item['width_mm'] ?? 0);
        $height = (int)($item['height_mm'] ?? 0);
        $a = $attrs[$pid] ?? [];
        $itemVolume = $length > 0 && $width > 0 && $height > 0
            ? intdiv($length * $width * $height, 1000)
            : (int)($a['volume_cm3'] ?? 0);
        $volumeCm3 += $itemVolume * $qty;
    }

    return ['weight_g' => $weightG, 'volume_cm3' => $volumeCm3];
}

function apiWebhookStripe(): array {
    $payload = file_get_contents('php://input');
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $cfg = require dirname(__DIR__, 2) . '/config.php';
    $secret = $cfg['stripe_webhook_secret'] ?? '';

    if (strlen((string)$secret) < 16 || trim((string)$secret) !== (string)$secret) {
        http_response_code(503);
        return ['error' => 'Webhook not configured'];
    }

    if (!verifyStripeSignature($payload, $sigHeader, $secret)) {
        http_response_code(400);
        return ['error' => 'Invalid signature'];
    }

    $event = json_decode($payload, true);
    if (!$event || empty($event['id']) || empty($event['type'])) {
        http_response_code(400);
        return ['error' => 'Invalid payload'];
    }

    $eventKey = 'payment_stripe_' . $event['id'];
    $claim = Idempotency::webhookAlreadyProcessed($event['id']) ? 'done' : Idempotency::claim($eventKey);
    if ($claim === 'in_flight') return paymentWebhookInFlightResponse();
    if ($claim === 'done') return ['status' => 'already_processed'];

    try {
        $resolvedStore = match($event['type']) {
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => onPaymentCompleted($event['data']['object'] ?? []),
            'charge.refunded'            => onChargeRefunded($event['data']['object'] ?? []),
            'charge.dispute.created'     => onDisputeCreated($event['data']['object'] ?? []),
            default                      => null,
        };
        if ($resolvedStore !== null) PaymentWebhookRouting::resolved('stripe', (string)$event['id'], $resolvedStore);
    } catch (PaymentWebhookUnresolved $e) {
        Idempotency::unmark($eventKey);
        return paymentWebhookParkedResponse(PaymentWebhookRouting::park('stripe', (string)$event['id'], (string)$event['type'], $e));
    } catch (\Throwable $e) {
        // Unmark so Stripe can retry the webhook
        Idempotency::unmark($eventKey);
        IncidentMonitor::report('payment.webhook_failed', 'webhook', 'critical', ['exception' => $e]);
        http_response_code(500);
        return ['error' => 'Processing failed'];
    }
    Idempotency::complete($eventKey);

    return ['status' => 'ok'];
}

/** Unresolved retry → 503 so the provider redelivers; exhausted → 202, persisted as a webhook incident. */
function paymentWebhookParkedResponse(array $parked): array {
    if ($parked['status'] === 'dead') {
        http_response_code(202);
        return ['status' => 'parked'];
    }
    http_response_code(503);
    return ['error' => 'Order not resolvable yet', 'retry' => true];
}

/** Another request is processing the same event right now → 409 so the provider redelivers it later. */
function paymentWebhookInFlightResponse(): array {
    http_response_code(409);
    return ['error' => 'Event is being processed', 'retry' => true];
}

/** Stripe handlers return the store that owned the order, or null when the event needs no order. */
function onPaymentCompleted(array $session): ?string {
    if (isset($session['payment_status']) && $session['payment_status'] !== 'paid') return null;
    $sessionId = (string)($session['id'] ?? '');
    $target = PaymentWebhookRouting::locate(array_filter([
        'order_id' => (int)($session['metadata']['order_id'] ?? 0),
        'session_id' => $sessionId,
        'order_number' => (string)($session['client_reference_id'] ?? ''),
    ]), 'stripe');
    PaymentWebhookRouting::within($target['store_id'], static fn() => applyStripePaymentCompleted($session, $sessionId, $target['id']));
    return $target['store_id'];
}

function applyStripePaymentCompleted(array $session, string $sessionId, int $orderId): void {
    $order = Orders::byId($orderId);
    if (!$order) throw new RuntimeException("Order #{$orderId} vanished during Stripe webhook");
    // Like the generic path in PaymentService: a capture without amount or currency is unverified, never paid.
    if (!isset($session['amount_total']) || (int)$session['amount_total'] !== (int)$order['total']) {
        throw new RuntimeException("Stripe amount missing or mismatched for order #{$orderId}");
    }
    if (!isset($session['currency']) || strtolower((string)$session['currency']) !== strtolower((string)$order['currency'])) {
        throw new RuntimeException("Stripe currency missing or mismatched for order #{$orderId}");
    }

    $paymentRef = (string)($session['payment_intent'] ?? $sessionId);
    // A second session paid for an already settled order: alert, and keep the recorded payment_id refunds use.
    if (in_array((string)($order['payment_status'] ?? 'pending'), ['paid', 'refund_pending', 'partially_refunded', 'refunded', 'disputed'], true)
        && PaymentService::reportForeignCapture($order, [$paymentRef, $sessionId])) {
        return;
    }
    if ($paymentRef !== '') Orders::setPaymentId($orderId, $paymentRef);
    if ($sessionId !== '') CheckoutSession::markCompleted($sessionId, 'stripe');

    if (($order['payment_status'] ?? 'pending') !== 'paid') {
        PaymentService::transitionPayment(
            $orderId,
            'paid',
            'checkout.session.completed',
            (int)$session['amount_total'],
            'stripe_checkout_' . ($sessionId ?: $paymentRef),
            ['session_id' => $sessionId, 'payment_id' => $paymentRef]
        );
    }
    PaymentService::settleCapture($orderId, 'Stripe webhook: ' . $sessionId);
}

function onChargeRefunded(array $charge): ?string {
    $paymentIntent = (string)($charge['payment_intent'] ?? '');
    if ($paymentIntent === '') return null;
    $target = PaymentWebhookRouting::locate(['payment_id' => $paymentIntent], 'stripe');
    PaymentWebhookRouting::within($target['store_id'], static fn() => applyStripeChargeRefunded($charge, $paymentIntent, $target['id']));
    return $target['store_id'];
}

function applyStripeChargeRefunded(array $charge, string $paymentIntent, int $orderId): void {
    $order = Orders::byId($orderId);
    if (!$order) throw new RuntimeException("Order #{$orderId} vanished during Stripe webhook");

    $refundedAmount = (int)($charge['amount_refunded'] ?? 0);
    $orderTotal = (int)$order['total'];

    AuditLog::log('order_refund', (int)$order['id'], '', (string)$refundedAmount,
        "Stripe refund: {$refundedAmount}/{$orderTotal} cents");

    $fullRefund = $refundedAmount >= $orderTotal;
    $targetPaymentStatus = $fullRefund ? 'refunded' : 'partially_refunded';
    $refundDelta = max(0, $refundedAmount - PaymentService::refundedAmount($orderId));
    if ($refundDelta > 0) {
        $eventKey = 'stripe_refund_' . (string)($charge['id'] ?? $paymentIntent) . '_' . $refundedAmount;
        $eventPayload = ['cumulative_amount' => $refundedAmount];
        if (($order['payment_status'] ?? '') !== $targetPaymentStatus
            && PaymentService::canPaymentTransition((string)($order['payment_status'] ?? 'pending'), $targetPaymentStatus)) {
            PaymentService::transitionPayment($orderId, $targetPaymentStatus, 'charge.refunded', $refundDelta, $eventKey, $eventPayload);
        } else {
            PaymentService::recordEvent(
                $orderId,
                'charge.refunded',
                $targetPaymentStatus,
                $refundDelta,
                (string)($order['currency'] ?? 'eur'),
                $eventKey,
                $eventPayload,
                'stripe',
                (string)$paymentIntent
            );
        }
    }

    if ($fullRefund) {
        if (OrderWorkflow::canTransition((string)$order['status'], 'refunded')) {
            OrderWorkflow::transition($orderId, 'refunded', "Stripe full refund: {$refundedAmount} cents");
        } else {
            FulfillmentDispatcher::queue('refunded', $order);
        }
    }
}

function onDisputeCreated(array $dispute): ?string {
    $paymentIntent = (string)($dispute['payment_intent'] ?? '');
    if ($paymentIntent === '') return null;
    $target = PaymentWebhookRouting::locate(['payment_id' => $paymentIntent], 'stripe');
    PaymentWebhookRouting::within($target['store_id'], static fn() => applyStripeDisputeCreated($dispute, $paymentIntent, $target['id']));
    return $target['store_id'];
}

function applyStripeDisputeCreated(array $dispute, string $paymentIntent, int $orderId): void {
    $order = Orders::byId($orderId);
    if (!$order) throw new RuntimeException("Order #{$orderId} vanished during Stripe webhook");
    AuditLog::log('order_dispute', $orderId, $order['status'], 'disputed', 'Stripe dispute: ' . ($dispute['id'] ?? ''));
    if (($order['payment_status'] ?? '') !== 'disputed'
        && PaymentService::canPaymentTransition((string)($order['payment_status'] ?? 'pending'), 'disputed')) {
        PaymentService::transitionPayment(
            $orderId,
            'disputed',
            'charge.dispute.created',
            (int)($dispute['amount'] ?? $order['total']),
            'stripe_dispute_' . (string)($dispute['id'] ?? $paymentIntent)
        );
    }
    FulfillmentDispatcher::queue('disputed', $order);
}

if (!function_exists('verifyStripeSignature')) {
function verifyStripeSignature(string $payload, string $sigHeader, string $secret): bool {
    if (strlen($secret) < 16 || trim($secret) !== $secret) return false;

    $timestamp = '';
    $signatures = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$key, $value] = explode('=', trim($part), 2) + [1 => ''];
        if ($key === 't') {
            if ($timestamp !== '' && $timestamp !== $value) return false;
            $timestamp = $value;
        } elseif ($key === 'v1' && $value !== '') {
            $signatures[] = strtolower($value);
        }
    }
    if ($timestamp === '' || !ctype_digit($timestamp) || !$signatures) return false;
    if (abs(time() - (int)$timestamp) > 300) return false;

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) return true;
    }
    return false;
}
}

// ============================================================
// Generic Payment Webhook Handler (Stripe, PayU, Mock)
// Usage: POST /api.php?action=webhook_payment&provider=stripe|payu|mock
// ============================================================

function apiWebhookPayment(): array {
    $provider = Security::clean($_GET['provider'] ?? '');
    if (!$provider || !preg_match('/^[a-z0-9_]+$/', $provider)) {
        http_response_code(400);
        return ['error' => 'Invalid provider'];
    }
    // Both Stripe URLs must use the same verified processing and claim/retry path.
    if ($provider === 'stripe') return apiWebhookStripe();
    $pulled = apiWebhookPulledNotification($provider);
    if ($pulled !== null) return $pulled;

    $rawPayload = file_get_contents('php://input');
    if (!$rawPayload) {
        http_response_code(400);
        return ['error' => 'Empty payload'];
    }

    $parsed = json_decode($rawPayload, true);
    if ($parsed === null) {
        http_response_code(400);
        return ['error' => 'Invalid JSON'];
    }

    // Resolve the exact registered provider. Mock remains an explicit dev-only bypass.
    $cfg = require dirname(__DIR__, 2) . '/config.php';
    if ($provider === 'mock'
        && ($cfg['adapters']['payment'] ?? '') !== 'MockPayment'
        && empty($cfg['allow_mock_webhook'])) {
        http_response_code(400);
        return ['error' => "Unknown provider: {$provider}"];
    }
    try {
        $definition = Adapters::registry('payment')[$provider] ?? null;
        if (!$definition) throw new RuntimeException('Provider is not registered');
        $headerName = (string)($definition['signature_header'] ?? '');
        if (!preg_match('/^HTTP_[A-Z0-9_]+$/', $headerName)) {
            throw new RuntimeException('Provider signature header is not configured');
        }
        $gateway = Adapters::payment($provider);
        $sigHeader = (string)($_SERVER[$headerName] ?? '');
    } catch (\Throwable $e) {
        http_response_code(400);
        return ['error' => "Unknown provider: {$provider}"];
    }

    try {
        $result = PaymentService::handleWebhook(
            rawPayload:    $rawPayload,
            sigHeader:     $sigHeader,
            parsedPayload: $parsed,
            gateway:       $gateway,
            providerName:  $provider,
        );
    } catch (\RuntimeException $e) {
        if (str_contains($e->getMessage(), 'signature')) {
            http_response_code(400);
            return ['error' => 'Invalid signature'];
        }
        IncidentMonitor::report('payment.webhook_failed', 'webhook', 'critical', ['exception' => $e]);
        http_response_code(500);
        return ['error' => 'Processing failed'];
    }

    if (isset($result['parked'])) return paymentWebhookParkedResponse($result['parked']);
    if (!empty($result['retry'])) return paymentWebhookInFlightResponse();
    return ['status' => $result['duplicate'] ?? false ? 'already_processed' : 'ok'];
}

/**
 * Reference notifications (GoPay GET ?id=…, Comgate push): answered only for providers that verify
 * every notification through their authenticated API. Returns null for signed-webhook providers.
 * 400 = the provider does not confirm the payment (nothing changes); 500 = retry later.
 */
function apiWebhookPulledNotification(string $provider): ?array {
    try {
        if (!isset(Adapters::registry('payment')[$provider])) return null;
        $gateway = Adapters::payment($provider);
    } catch (\Throwable $e) {
        return null;
    }
    if (!$gateway instanceof PullVerifiedNotificationAdapter) return null;

    // Query string, form body or JSON body (Comgate REST push); only scalar values reach the adapter.
    $json = json_decode((string)file_get_contents('php://input'), true);
    $notification = array_filter(
        array_diff_key($_GET + $_POST + (is_array($json) ? $json : []), ['action' => true, 'provider' => true]),
        'is_scalar'
    );
    try {
        $result = PaymentService::handlePulledNotification($notification, $gateway, $provider);
    } catch (PaymentNotificationUnverified $e) {
        error_log("webhook_payment [{$provider}] unverified notification: " . $e->getMessage());
        http_response_code(400);
        return ['error' => 'Invalid notification'];
    } catch (\Throwable $e) {
        error_log("webhook_payment [{$provider}] error: " . $e->getMessage());
        http_response_code(500);
        return ['error' => 'Processing failed'];
    }
    if (isset($result['parked'])) return paymentWebhookParkedResponse($result['parked']);
    if (!empty($result['retry'])) return paymentWebhookInFlightResponse();
    return ['status' => $result['duplicate'] ?? false ? 'already_processed' : 'ok'];
}

function apiWebhookShipping(): array {
    $carrier = Security::clean($_GET['carrier'] ?? '');
    if (!$carrier || !preg_match('/^[a-z0-9_]+$/', $carrier)) {
        http_response_code(400);
        return ['error' => 'Invalid carrier'];
    }

    $rawPayload = file_get_contents('php://input');
    if (!$rawPayload) {
        http_response_code(400);
        return ['error' => 'Empty payload'];
    }

    $parsed = json_decode($rawPayload, true);
    if ($parsed === null) {
        http_response_code(400);
        return ['error' => 'Invalid JSON'];
    }

    // Signature header varies by carrier
    $sigHeader = match($carrier) {
        'packeta' => $_SERVER['HTTP_X_PACKETA_SIGNATURE'] ?? '',
        'gls'     => $_SERVER['HTTP_X_GLS_SIGNATURE'] ?? '',
        default   => $_SERVER['HTTP_X_SHIPPING_SIGNATURE'] ?? '',
    };

    try {
        $result = ShippingService::handleTrackingWebhook($carrier, $parsed, $sigHeader, $rawPayload);
    } catch (\RuntimeException $e) {
        if (str_contains($e->getMessage(), 'signature')) {
            http_response_code(400);
            return ['error' => 'Invalid signature'];
        }
        IncidentMonitor::report('shipping.webhook_failed', 'webhook', 'critical', ['exception' => $e]);
        http_response_code(500);
        return ['error' => 'Processing failed'];
    }
    if (!empty($result['retry'])) return paymentWebhookInFlightResponse();

    return $result;
}

// ============================================================
// Product specification (parametric) API handlers
// ============================================================

function apiParametricSearch(): array {
    $catId = Security::int($_GET['category_id'] ?? 0);
    if (!$catId) return ['error' => 'category_id required'];

    $page = max(1, Security::int($_GET['page'] ?? 1));
    $sort = Security::clean($_GET['sort'] ?? 'price_asc');
    $lang = I18n::locale();

    $priceMin = !empty($_GET['price_min']) ? Security::int($_GET['price_min']) : null;
    $priceMax = !empty($_GET['price_max']) ? Security::int($_GET['price_max']) : null;

    // Parse spec filters from query params: spec[Material]=cotton&spec[Size][]=M
    $specFilters = [];
    if (!empty($_GET['spec']) && is_array($_GET['spec'])) {
        foreach ($_GET['spec'] as $name => $val) {
            $name = Security::clean($name);
            if (is_array($val)) {
                $specFilters[$name] = array_map([Security::class, 'clean'], $val);
            } else {
                $specFilters[$name] = [Security::clean($val)];
            }
        }
    }

    $result = Products::parametricSearch($catId, $page, 24, $sort, $specFilters, $priceMin, $priceMax, $lang);
    $result['products'] = Products::enrichMany($result['products']);
    return $result;
}

function apiProductSpecs(): array {
    $id = Security::int($_GET['id'] ?? 0);
    if (!$id) return ['error' => 'id required'];
    $lang = I18n::locale();
    return ['specifications' => Products::specifications($id, $lang)];
}

/** Browser CSP violation reports; no CSRF (browsers send them without page context). */
function apiCspReport(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'POST required'];
    }
    if (!Security::rateLimit('csp_report', 30) || !Security::rateLimit('csp_report_global', 600, 60, false)) {
        http_response_code(429);
        return ['error' => 'Too many requests'];
    }
    $body = (string)file_get_contents('php://input', false, null, 0, CspReports::MAX_BODY_BYTES + 1);
    CspReports::ingest($body);
    http_response_code(202);
    return ['ok' => true];
}

function apiCookieConsent(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'POST required'];
    }
    if (!Security::csrfValidate()) {
        http_response_code(403);
        return ['error' => 'Invalid CSRF token'];
    }
    $cats = [
        'necessary' => true, // always
        'analytics' => !empty($_POST['analytics']),
        'marketing' => !empty($_POST['marketing']),
    ];
    $method = (string)($_POST['method'] ?? 'preferences');
    if (!in_array($method, ['accept_all', 'reject_all', 'preferences'], true)) {
        $method = 'preferences';
    }
    $cid = (int)($_SESSION['customer_id'] ?? 0);
    GdprService::logCookieConsent([
        'customer_id' => $cid > 0 ? $cid : null,
        'session_id'  => Security::sessionKey() ?: null,
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent'  => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'categories'  => $cats,
        'method'      => $method,
    ]);
    return ['ok' => true, 'categories' => $cats, 'method' => $method];
}

function apiPage(): array {
    $slug = Security::slug($_GET['slug'] ?? '');
    if (!$slug) return ['error' => 'slug required'];
    $stmt = Db::get()->prepare("SELECT * FROM pages WHERE store_id = :store AND slug = :slug AND status = 'active'");
    $stmt->execute([':store' => StoreContext::current()->id(), ':slug' => $slug]);
    $page = $stmt->fetch();
    if (!$page) { http_response_code(404); return ['error' => 'Page not found']; }
    // Translate if non-default locale
    $tr = json_decode($page['translations'] ?? '{}', true);
    $locale = I18n::locale();
    if ($locale !== I18n::sysDefault() && isset($tr[$locale])) {
        if (!empty($tr[$locale]['title'])) $page['title'] = $tr[$locale]['title'];
        if (!empty($tr[$locale]['body'])) $page['body'] = $tr[$locale]['body'];
    }
    return ['page' => ['slug' => $page['slug'], 'title' => $page['title'], 'body' => Security::sanitizeRichHtml((string)($page['body'] ?? ''))]];
}
