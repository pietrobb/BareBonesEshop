<?php
/**
 * VatService — EU VAT compliance engine.
 *
 * Responsibilities:
 *   - Load config/vat-rates.json
 *   - Resolve effective VAT rate for (country, class, date)
 *   - Reverse-charge resolver (B2C/B2B, EU/export, OSS)
 *   - Per-line VAT calculation (gross-inclusive prices)
 *   - Helper for Cart/Invoice/OSS aggregation
 *
 * Skeleton — full logic added in subsequent commits.
 *
 * Public API (planned):
 *   VatService::init(?string $rootDir = null): void
 *   VatService::rate(string $country, string $class = 'standard', ?string $atDate = null): float
 *   VatService::resolveScenario(array $ctx): array
 *       // ctx: seller_country, seller_oss_enabled, customer_country, customer_vat_id_valid, is_b2b
 *       // returns: {scenario, vat_rate (nullable), applies_vat, note_key}
 *   VatService::computeLine(int $unitGrossCents, int $qty, float $rate, bool $reverse): array
 *       // returns: {gross, net, vat_amount, rate, qty}
 *   VatService::isEuCountry(string $iso2): bool
 *   VatService::resolveClass(string $alias): string
 */
class VatService implements TaxProvider
{
    /** @var array */
    private static array $cfg = [];
    private static ?string $rootDir = null;
    private static bool $loaded = false;

    public const SCENARIO_NON_VAT_PAYER     = 'non_vat_payer';
    public const SCENARIO_DOMESTIC          = 'domestic';
    public const SCENARIO_REVERSE_CHARGE    = 'reverse_charge';
    public const SCENARIO_OSS               = 'oss';
    public const SCENARIO_DOMESTIC_SUBTHRESH = 'domestic_subthreshold';
    public const SCENARIO_EXPORT            = 'export';

    public static function init(?string $rootDir = null): void
    {
        self::$rootDir = $rootDir ?: dirname(__DIR__);
        self::$cfg = self::loadConfig(self::$rootDir . '/config/vat-rates.json');
        self::$loaded = true;
    }

    public static function reset(): void
    {
        self::$cfg = [];
        self::$rootDir = null;
        self::$loaded = false;
    }

    public static function setConfig(array $cfg): void
    {
        self::$cfg = $cfg;
        self::$loaded = true;
    }

    public static function config(): array
    {
        if (!self::$loaded) self::init();
        return self::$cfg;
    }

    public static function loadConfig(string $path): array
    {
        if (!is_file($path)) {
            return ['default_rate' => 0, 'fallback_class' => 'standard', 'eu_countries' => [], 'rates' => [], 'product_class_aliases' => []];
        }
        $raw = file_get_contents($path);
        $j   = json_decode($raw, true);
        if (!is_array($j)) {
            throw new RuntimeException('VatService: invalid JSON in ' . $path);
        }
        return $j;
    }

    public static function isEuCountry(string $iso2): bool
    {
        $cfg = self::config();
        $iso2 = strtoupper(trim($iso2));
        return in_array($iso2, $cfg['eu_countries'] ?? [], true);
    }

    /** Map alias (food/books/...) to canonical class (standard/reduced/reduced2/zero). */
    public static function resolveClass(string $alias): string
    {
        $cfg = self::config();
        $alias = strtolower(trim($alias));
        $aliases = $cfg['product_class_aliases'] ?? [];
        if (isset($aliases[$alias])) return $aliases[$alias];
        $valid = ['standard', 'reduced', 'reduced2', 'zero'];
        if (in_array($alias, $valid, true)) return $alias;
        return $cfg['fallback_class'] ?? 'standard';
    }

    /**
     * Resolve VAT rate for country at a given date.
     * @param string $country ISO-2
     * @param string $class   standard|reduced|reduced2|zero (or alias resolved upstream)
     * @param string|null $atDate YYYY-MM-DD (defaults to today)
     */
    public static function rate(string $country, string $class = 'standard', ?string $atDate = null): float
    {
        $cfg     = self::config();
        $country = strtoupper(trim($country));
        $category = strtolower(trim($class));
        $class   = self::resolveClass($class);
        // A rate change takes effect at midnight on the shop's calendar, not the server's (UTC container: 01:00/02:00).
        $date    = $atDate ?: ShopTime::date('Y-m-d');

        $row = $cfg['rates'][$country] ?? null;
        if (!$row) {
            return (float)($cfg['default_rate'] ?? 0);
        }

        // A product category (books, medicines, ...) may have its own rate in a country; otherwise its class rate.
        $band = $row;
        if ($date < ($row['valid_from'] ?? '1970-01-01')) {
            // Walk history (date < current valid_from); no matching band falls back to the current one.
            foreach (($row['history'] ?? []) as $h) {
                if ($date >= ($h['valid_from'] ?? '1970-01-01') && $date <= ($h['valid_to'] ?? '9999-12-31')) {
                    $band = $h;
                    break;
                }
            }
        }
        if (isset($band['categories'][$category])) return (float)$band['categories'][$category];
        return (float)($band[$class] ?? $band['standard'] ?? 0);
    }

    /**
     * Resolve VAT scenario for an order.
     *
     * Inputs:
     *   seller_country        ISO-2 of seller (store)
     *   seller_vat_registered bool (defaults true for existing deployments)
     *   seller_oss_enabled    bool
     *   customer_country      ISO-2 of customer
     *   customer_vat_id_valid bool (VIES result)
     *   is_b2b                bool (customer claims B2B)
     *   customer_country_verified bool (default true; false = declared only, e.g. digital-only order)
     *
     * Returns:
     *   ['scenario' => string, 'vat_rate' => float|null,
     *    'applies_vat' => bool, 'note_key' => string,
     *    'rate_country' => string]  (which country's rate to use)
     *
     * Note: vat_rate left null when caller must look it up per-line; rate_country is the country.
     */
    public static function resolveScenario(array $ctx): array
    {
        $sc   = strtoupper(trim($ctx['seller_country']   ?? ''));
        $cc   = strtoupper(trim($ctx['customer_country'] ?? ''));
        $sellerVatRegistered = array_key_exists('seller_vat_registered', $ctx)
            ? (bool)$ctx['seller_vat_registered']
            : true;
        $oss  = (bool)($ctx['seller_oss_enabled']        ?? false);
        $valid = (bool)($ctx['customer_vat_id_valid']    ?? false);
        $b2b   = (bool)($ctx['is_b2b']                   ?? false);

        if (!$sellerVatRegistered) {
            return [
                'scenario'     => self::SCENARIO_NON_VAT_PAYER,
                'vat_rate'     => 0.0,
                'applies_vat'  => false,
                'note_key'     => 'vat.non_vat_payer_note',
                'rate_country' => $sc,
            ];
        }

        // A country outside the EU only zero-rates the sale when something backs it: goods dispatched to an address
        // there. A declared country alone (digital-only order) keeps the seller's domestic VAT.
        $countryVerified = (bool)($ctx['customer_country_verified'] ?? true);
        if (!self::isEuCountry($cc) && !$countryVerified) {
            return [
                'scenario'     => self::SCENARIO_DOMESTIC,
                'vat_rate'     => null,
                'applies_vat'  => true,
                'note_key'     => '',
                'rate_country' => $sc,
            ];
        }

        // Export — outside EU
        if (!self::isEuCountry($cc)) {
            return [
                'scenario'     => self::SCENARIO_EXPORT,
                'vat_rate'     => 0.0,
                'applies_vat'  => false,
                'note_key'     => 'vat.export_note',
                'rate_country' => $cc,
            ];
        }

        // Domestic (same country) — always seller's VAT applies
        if ($sc === $cc) {
            return [
                'scenario'     => self::SCENARIO_DOMESTIC,
                'vat_rate'     => null, // resolved per-line by caller
                'applies_vat'  => true,
                'note_key'     => '',
                'rate_country' => $sc,
            ];
        }

        // B2B intra-EU with valid VAT ID — reverse charge
        if ($b2b && $valid) {
            return [
                'scenario'     => self::SCENARIO_REVERSE_CHARGE,
                'vat_rate'     => 0.0,
                'applies_vat'  => false,
                'note_key'     => 'vat.reverse_charge_note',
                'rate_country' => $cc,
            ];
        }

        // B2C cross-border EU
        if ($oss) {
            return [
                'scenario'     => self::SCENARIO_OSS,
                'vat_rate'     => null, // customer-country rate per-line
                'applies_vat'  => true,
                'note_key'     => '',
                'rate_country' => $cc,
            ];
        }

        // OSS not enabled / under threshold — seller-country rate
        return [
            'scenario'     => self::SCENARIO_DOMESTIC_SUBTHRESH,
            'vat_rate'     => null,
            'applies_vat'  => true,
            'note_key'     => '',
            'rate_country' => $sc,
        ];
    }

    /**
     * Compute per-line VAT from gross-inclusive unit price.
     *
     * @param int   $unitGrossCents Unit price (gross, in cents)
     * @param int   $qty            Quantity
     * @param float $rate           VAT rate in % (e.g. 23.0)
     * @param bool  $reverse        If true (reverse-charge / export), no VAT extracted
     * @return array{gross:int, net:int, vat_amount:int, rate:float, qty:int}
     */
    public static function computeLine(int $unitGrossCents, int $qty, float $rate, bool $reverse): array
    {
        $rateBasisPoints = $reverse ? 0 : (int)round($rate * 100);
        $line = self::computeGross($unitGrossCents * $qty, $rateBasisPoints);
        return [
            'gross'      => $line['gross'],
            'net'        => $line['net'],
            'vat_amount' => $line['vat'],
            'rate'       => $rateBasisPoints / 100,
            'qty'        => $qty,
        ];
    }

    /**
     * Freeze the complete gross-inclusive checkout calculation into one
     * deterministic, tamper-evident snapshot. All amounts are integer cents and
     * rates are integer basis points (23% = 2300).
     */
    public static function buildSnapshot(array $items, array $context, array $amounts = []): array
    {
        $scenario = self::resolveScenario($context);
        $effectiveDate = (string)($amounts['effective_date'] ?? ShopTime::date('Y-m-d'));
        $currency = strtolower((string)($amounts['currency'] ?? 'eur'));
        $appliesVat = (bool)$scenario['applies_vat'];
        $rateCountry = (string)$scenario['rate_country'];

        $products = [];
        $subtotal = 0;
        foreach ($items as $index => $item) {
            $quantity = (int)($item['qty'] ?? $item['quantity'] ?? 0);
            $unitGross = (int)($item['price'] ?? $item['unit_price'] ?? -1);
            if ($quantity <= 0 || $unitGross < 0) {
                throw new \InvalidArgumentException('Financial snapshot items require positive quantity and non-negative unit price');
            }
            $gross = $unitGross * $quantity;
            $vatClass = self::resolveClass((string)($item['vat_class'] ?? 'standard'));
            $rateBasisPoints = $appliesVat
                ? (int)round(self::rate($rateCountry, (string)($item['vat_category'] ?? $item['vat_class'] ?? 'standard'), $effectiveDate) * 100)
                : 0;
            $products[] = [
                'index' => (int)$index,
                'product_id' => (int)($item['product_id'] ?? 0),
                'variant_id' => isset($item['variant_id']) ? (int)$item['variant_id'] : null,
                'sku' => (string)($item['sku'] ?? ''),
                'quantity' => $quantity,
                'unit_gross' => $unitGross,
                'gross_before_discount' => $gross,
                'vat_class' => $vatClass,
                'rate_basis_points' => $rateBasisPoints,
            ];
            $subtotal += $gross;
        }
        if ($products === []) {
            throw new \InvalidArgumentException('Financial snapshot requires at least one item');
        }

        $discount = min($subtotal, max(0, (int)($amounts['discount'] ?? 0)));
        // A discount tied to particular lines (buy X get Y: the free units) stays on them; the rest of the discount is
        // spread over what remains of every line in proportion.
        $discounts = array_fill(0, count($products), 0);
        $targetedTotal = 0;
        foreach ((array)($amounts['line_discounts'] ?? []) as $position => $amount) {
            if (!isset($products[$position])) throw new \InvalidArgumentException('Financial snapshot line discount has no line');
            $amount = min(max(0, (int)$amount), $products[$position]['gross_before_discount'] - $discounts[$position], $discount - $targetedTotal);
            $discounts[$position] += $amount;
            $targetedTotal += $amount;
        }
        $remaining = $products;
        foreach ($remaining as $position => $product) $remaining[$position]['gross_before_discount'] -= $discounts[$position];
        foreach (self::allocateDiscount($remaining, $subtotal - $targetedTotal, $discount - $targetedTotal) as $position => $share) {
            $discounts[$position] += $share;
        }
        $components = [];
        $maxProductRate = 0;
        foreach ($products as $position => $product) {
            $gross = $product['gross_before_discount'] - $discounts[$position];
            $calculated = self::computeGross($gross, $product['rate_basis_points']);
            unset($product['index']);
            $components[] = array_merge($product, [
                'type' => 'product',
                'discount' => $discounts[$position],
                'gross' => $calculated['gross'],
                'net' => $calculated['net'],
                'vat' => $calculated['vat'],
            ]);
            $maxProductRate = max($maxProductRate, $product['rate_basis_points']);
        }

        // Shipping and the payment (e.g. cash on delivery) fee are part of the consideration for the goods: they carry
        // VAT at the highest rate among the goods.
        $ancillaryRate = $appliesVat ? $maxProductRate : 0;
        $shipping = max(0, (int)($amounts['shipping'] ?? 0));
        if ($shipping > 0) {
            $shippingRate = $ancillaryRate;
            $calculated = self::computeGross($shipping, $shippingRate);
            $components[] = [
                'type' => 'shipping',
                'vat_class' => 'shipping',
                'rate_basis_points' => $shippingRate,
                'gross' => $calculated['gross'],
                'net' => $calculated['net'],
                'vat' => $calculated['vat'],
            ];
        }

        $paymentFee = max(0, (int)($amounts['payment_fee'] ?? 0));
        if ($paymentFee > 0) {
            $calculated = self::computeGross($paymentFee, $ancillaryRate);
            $components[] = [
                'type' => 'payment_fee',
                'vat_class' => 'payment_fee',
                'rate_basis_points' => $ancillaryRate,
                'gross' => $calculated['gross'],
                'net' => $calculated['net'],
                'vat' => $calculated['vat'],
            ];
        }

        $breakdown = [];
        $netTotal = 0;
        $vatTotal = 0;
        foreach ($components as $component) {
            $rateBasisPoints = (int)$component['rate_basis_points'];
            $key = self::rateKey($rateBasisPoints);
            if (!isset($breakdown[$key])) {
                $breakdown[$key] = ['rate_basis_points' => $rateBasisPoints, 'net' => 0, 'vat' => 0, 'gross' => 0];
            }
            $breakdown[$key]['net'] += (int)$component['net'];
            $breakdown[$key]['vat'] += (int)$component['vat'];
            $breakdown[$key]['gross'] += (int)$component['gross'];
            $netTotal += (int)$component['net'];
            $vatTotal += (int)$component['vat'];
        }
        uksort($breakdown, static fn(string $a, string $b): int => (float)$a <=> (float)$b);

        $providers = (array)($amounts['providers'] ?? ['tax' => 'vat']);
        ksort($providers, SORT_STRING);
        foreach ($providers as $type => $code) {
            if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', (string)$type)
                || !preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', (string)$code)) {
                throw new \InvalidArgumentException('Financial snapshot provider code is invalid');
            }
        }

        $snapshot = [
            'version' => 1,
            'providers' => $providers,
            'currency' => $currency,
            'effective_date' => $effectiveDate,
            'scenario' => (string)$scenario['scenario'],
            'rate_country' => $rateCountry,
            'note_key' => (string)$scenario['note_key'],
            'rounding' => 'gross_line_half_up_integer_basis_points',
            'components' => $components,
            'breakdown' => $breakdown,
            'totals' => [
                'subtotal' => $subtotal,
                'discount' => $discount,
                'shipping' => $shipping,
                'payment_fee' => $paymentFee,
                'net' => $netTotal,
                'vat' => $vatTotal,
                'gross' => $netTotal + $vatTotal,
            ],
        ];
        $snapshot['fingerprint'] = self::snapshotFingerprint($snapshot);
        self::validateSnapshot($snapshot);
        return $snapshot;
    }

    public static function validateSnapshot(array $snapshot): void
    {
        if (($snapshot['version'] ?? null) !== 1 || !is_array($snapshot['components'] ?? null)
            || !is_array($snapshot['breakdown'] ?? null) || !is_array($snapshot['totals'] ?? null)) {
            throw new \InvalidArgumentException('Invalid financial snapshot structure');
        }
        $fingerprint = (string)($snapshot['fingerprint'] ?? '');
        $unsigned = $snapshot;
        unset($unsigned['fingerprint']);
        if ($fingerprint === '' || !hash_equals(self::snapshotFingerprint($unsigned), $fingerprint)) {
            throw new \InvalidArgumentException('Financial snapshot fingerprint mismatch');
        }

        $providers = $snapshot['providers'] ?? null;
        if ($providers !== null) {
            if (!is_array($providers) || ($providers['tax'] ?? '') === '') {
                throw new \InvalidArgumentException('Financial snapshot provider map is invalid');
            }
            foreach ($providers as $type => $code) {
                if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', (string)$type)
                    || !preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', (string)$code)) {
                    throw new \InvalidArgumentException('Financial snapshot provider code is invalid');
                }
            }
        }

        if (($snapshot['rounding'] ?? '') !== 'gross_line_half_up_integer_basis_points'
            || !in_array((string)($snapshot['scenario'] ?? ''), [
                self::SCENARIO_NON_VAT_PAYER, self::SCENARIO_DOMESTIC, self::SCENARIO_REVERSE_CHARGE,
                self::SCENARIO_OSS, self::SCENARIO_DOMESTIC_SUBTHRESH, self::SCENARIO_EXPORT,
            ], true)) {
            throw new \InvalidArgumentException('Financial snapshot calculation contract is invalid');
        }

        $net = 0;
        $vat = 0;
        $gross = 0;
        $subtotal = 0;
        $discount = 0;
        $shipping = 0;
        $paymentFee = 0;
        $productComponents = 0;
        $shippingComponents = 0;
        $paymentComponents = 0;
        $computedBreakdown = [];
        foreach ($snapshot['components'] as $component) {
            $type = (string)($component['type'] ?? '');
            $rateBasisPoints = (int)($component['rate_basis_points'] ?? -1);
            $componentNet = (int)($component['net'] ?? -1);
            $componentVat = (int)($component['vat'] ?? -1);
            $componentGross = (int)($component['gross'] ?? -1);
            if (!in_array($type, ['product', 'shipping', 'payment_fee'], true)
                || $rateBasisPoints < 0 || $rateBasisPoints > 10000
                || $componentNet < 0 || $componentVat < 0 || $componentGross !== $componentNet + $componentVat
                || self::computeGross($componentGross, $rateBasisPoints) !== [
                    'gross' => $componentGross, 'net' => $componentNet, 'vat' => $componentVat,
                ]) {
                throw new \InvalidArgumentException('Financial snapshot component invariant failed');
            }

            if ($type === 'product') {
                $productComponents++;
                $quantity = (int)($component['quantity'] ?? 0);
                $unitGross = (int)($component['unit_gross'] ?? -1);
                $grossBefore = (int)($component['gross_before_discount'] ?? -1);
                $componentDiscount = (int)($component['discount'] ?? -1);
                if ($quantity <= 0 || $unitGross < 0 || $grossBefore !== $unitGross * $quantity
                    || $componentDiscount < 0 || $componentDiscount > $grossBefore
                    || $componentGross !== $grossBefore - $componentDiscount) {
                    throw new \InvalidArgumentException('Financial snapshot product invariant failed');
                }
                $subtotal += $grossBefore;
                $discount += $componentDiscount;
            } elseif ($type === 'shipping') {
                $shippingComponents++;
                $shipping += $componentGross;
            } else {
                $paymentComponents++;
                $paymentFee += $componentGross;
            }

            $rateKey = self::rateKey($rateBasisPoints);
            if (!isset($computedBreakdown[$rateKey])) {
                $computedBreakdown[$rateKey] = ['rate_basis_points' => $rateBasisPoints, 'net' => 0, 'vat' => 0, 'gross' => 0];
            }
            $computedBreakdown[$rateKey]['net'] += $componentNet;
            $computedBreakdown[$rateKey]['vat'] += $componentVat;
            $computedBreakdown[$rateKey]['gross'] += $componentGross;
            $net += $componentNet;
            $vat += $componentVat;
            $gross += $componentGross;
        }
        if ($productComponents === 0 || $shippingComponents > 1 || $paymentComponents > 1) {
            throw new \InvalidArgumentException('Financial snapshot component cardinality failed');
        }
        uksort($computedBreakdown, static fn(string $a, string $b): int => (float)$a <=> (float)$b);
        if (self::canonicalize($computedBreakdown) !== self::canonicalize($snapshot['breakdown'])) {
            throw new \InvalidArgumentException('Financial snapshot breakdown invariant failed');
        }

        $totals = $snapshot['totals'];
        if ($net !== (int)($totals['net'] ?? -1) || $vat !== (int)($totals['vat'] ?? -1)
            || $gross !== (int)($totals['gross'] ?? -1)
            || $subtotal !== (int)($totals['subtotal'] ?? -1)
            || $discount !== (int)($totals['discount'] ?? -1)
            || $shipping !== (int)($totals['shipping'] ?? -1)
            || $paymentFee !== (int)($totals['payment_fee'] ?? -1)
            || $gross !== $subtotal - $discount + $shipping + $paymentFee) {
            throw new \InvalidArgumentException('Financial snapshot totals invariant failed');
        }
    }

    /** @return array{gross:int,net:int,vat:int} */
    private static function computeGross(int $gross, int $rateBasisPoints): array
    {
        if ($gross < 0 || $rateBasisPoints < 0) {
            throw new \InvalidArgumentException('Gross amount and VAT rate must be non-negative');
        }
        if ($rateBasisPoints === 0) return ['gross' => $gross, 'net' => $gross, 'vat' => 0];
        $denominator = 10000 + $rateBasisPoints;
        $net = intdiv($gross * 10000 + intdiv($denominator, 2), $denominator);
        return ['gross' => $gross, 'net' => $net, 'vat' => $gross - $net];
    }

    private static function allocateDiscount(array $products, int $subtotal, int $discount): array
    {
        $allocations = array_fill(0, count($products), 0);
        if ($discount === 0 || $subtotal === 0) return $allocations;

        $rank = [];
        $allocated = 0;
        foreach ($products as $position => $product) {
            $numerator = $discount * (int)$product['gross_before_discount'];
            $share = intdiv($numerator, $subtotal);
            $allocations[$position] = $share;
            $allocated += $share;
            $rank[] = ['position' => $position, 'remainder' => $numerator % $subtotal];
        }
        usort($rank, static fn(array $a, array $b): int => ($b['remainder'] <=> $a['remainder']) ?: ($a['position'] <=> $b['position']));
        $remaining = $discount - $allocated;
        for ($i = 0; $i < $remaining; $i++) {
            $allocations[$rank[$i]['position']]++;
        }
        return $allocations;
    }

    private static function rateKey(int $rateBasisPoints): string
    {
        $key = rtrim(rtrim(number_format($rateBasisPoints / 100, 2, '.', ''), '0'), '.');
        return $key === '' ? '0' : $key;
    }

    private static function snapshotFingerprint(array $snapshot): string
    {
        $canonical = self::canonicalize($snapshot);
        return hash('sha256', (string)json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function canonicalize(array $value): array
    {
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) $value[$key] = self::canonicalize($item);
        }
        return $value;
    }

    /**
     * Validate a VAT ID with caching.
     *
     * Cache lookup → validator if expired/missing → upsert with TTL.
     * TTL: valid/invalid 24h, unverified 1h (retry-friendly when service down).
     *
     * @return array{status:string, country:string|null, trader_name:string|null, trader_addr:string|null, cached:bool, age_seconds:int}
     */
    public static function validateVatId(string $vatId, VatIdValidator $validator): array
    {
        $vatId = strtoupper(preg_replace('/\s+/', '', $vatId));
        if ($vatId === '' || !preg_match('/^[A-Z]{2}[0-9A-Z]{6,14}$/', $vatId)) {
            return ['status' => 'invalid', 'country' => null, 'trader_name' => null, 'trader_addr' => null, 'cached' => false, 'age_seconds' => 0];
        }

        $db = Db::get();
        $row = $db->prepare('SELECT * FROM vat_id_cache WHERE vat_id = :v');
        $row->execute([':v' => $vatId]);
        $cached = $row->fetch(\PDO::FETCH_ASSOC);

        $now = time();
        if ($cached) {
            $age = $now - (int)strtotime((string)$cached['checked_at'] . ' UTC');
            $ttl = (int)($cached['ttl_seconds'] ?? 0);
            if ($age < $ttl) {
                return [
                    'status'      => (string)$cached['status'],
                    'country'     => $cached['country'] !== null ? (string)$cached['country'] : null,
                    'trader_name' => $cached['trader_name'] !== null ? (string)$cached['trader_name'] : null,
                    'trader_addr' => $cached['trader_addr'] !== null ? (string)$cached['trader_addr'] : null,
                    'cached'      => true,
                    'age_seconds' => $age,
                ];
            }
        }

        $res = $validator->check($vatId);
        $status = (string)$res['status'];
        $ttl = ($status === 'unverified') ? 3600 : 86400; // 1h vs 24h

        $up = $db->prepare(
            'INSERT INTO vat_id_cache (vat_id, country, status, trader_name, trader_addr, checked_at, ttl_seconds, raw_response)
             VALUES (:v, :c, :s, :tn, :ta, :ck, :ttl, :raw) '
            . Db::upsertClause(
                'vat_id',
                'country = excluded.country,
                 status  = excluded.status,
                 trader_name = excluded.trader_name,
                 trader_addr = excluded.trader_addr,
                 checked_at  = excluded.checked_at,
                 ttl_seconds = excluded.ttl_seconds,
                 raw_response = excluded.raw_response',
                'country = VALUES(country),
                 status  = VALUES(status),
                 trader_name = VALUES(trader_name),
                 trader_addr = VALUES(trader_addr),
                 checked_at  = VALUES(checked_at),
                 ttl_seconds = VALUES(ttl_seconds),
                 raw_response = VALUES(raw_response)'
            )
        );
        $up->execute([
            ':v'   => $vatId,
            ':c'   => $res['country'] ?? null,
            ':s'   => $status,
            ':tn'  => $res['trader_name'] ?? null,
            ':ta'  => $res['trader_addr'] ?? null,
            ':ck'  => gmdate('Y-m-d H:i:s', $now),
            ':ttl' => $ttl,
            ':raw' => $res['raw'] ?? null,
        ]);
        // The cache row is overwritten by the next check; the evidence a reverse-charge sale relied on is kept.
        if (Db::tableExists('vat_id_checks')) {
            $db->prepare('INSERT INTO vat_id_checks (vat_id, country, status, trader_name, trader_addr, request_identifier, checked_at, raw_response)
                VALUES (:v, :c, :s, :tn, :ta, :rid, :ck, :raw)')->execute([
                ':v' => $vatId, ':c' => $res['country'] ?? null, ':s' => $status, ':tn' => $res['trader_name'] ?? null,
                ':ta' => $res['trader_addr'] ?? null, ':rid' => $res['request_identifier'] ?? null,
                ':ck' => gmdate('Y-m-d H:i:s', $now), ':raw' => $res['raw'] ?? null,
            ]);
        }

        return [
            'status'      => $status,
            'country'     => $res['country'] ?? null,
            'trader_name' => $res['trader_name'] ?? null,
            'trader_addr' => $res['trader_addr'] ?? null,
            'cached'      => false,
            'age_seconds' => 0,
        ];
    }

    /**
     * Build the configured VAT ID validator instance.
     *
     * Reads config.php adapters.vat_validator (default ViesVatIdValidator).
     * Class is resolved by the autoloader (src/ → adapters/ → adapters/contrib/).
     * Dev/test environments can set 'vat_validator' => 'MockVatIdValidator'.
     */
    public static function defaultValidator(): VatIdValidator
    {
        $cfgFile = (self::$rootDir ?: dirname(__DIR__)) . '/config.php';
        $cfg = is_file($cfgFile) ? require $cfgFile : [];
        $class = (string)(($cfg['adapters']['vat_validator'] ?? '') ?: 'ViesVatIdValidator');
        if (!class_exists($class) || !is_subclass_of($class, VatIdValidator::class)) {
            throw new \RuntimeException("VAT validator adapter invalid: {$class}");
        }
        return new $class();
    }
}
