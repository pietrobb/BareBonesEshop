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
class VatService
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
        $class   = self::resolveClass($class);
        $date    = $atDate ?: date('Y-m-d');

        $row = $cfg['rates'][$country] ?? null;
        if (!$row) {
            return (float)($cfg['default_rate'] ?? 0);
        }

        // Check current band
        $vfrom = $row['valid_from'] ?? '1970-01-01';
        if ($date >= $vfrom) {
            return (float)($row[$class] ?? $row['standard'] ?? 0);
        }

        // Walk history (date < current valid_from)
        foreach (($row['history'] ?? []) as $h) {
            $hf = $h['valid_from'] ?? '1970-01-01';
            $ht = $h['valid_to']   ?? '9999-12-31';
            if ($date >= $hf && $date <= $ht) {
                return (float)($h[$class] ?? $h['standard'] ?? 0);
            }
        }

        // Fallback
        return (float)($row[$class] ?? $row['standard'] ?? 0);
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
        $gross = $unitGrossCents * $qty;
        if ($reverse || $rate <= 0) {
            return [
                'gross'      => $gross,
                'net'        => $gross,
                'vat_amount' => 0,
                'rate'       => 0.0,
                'qty'        => $qty,
            ];
        }
        // gross = net * (1 + rate/100)
        $net = (int)round($gross / (1 + $rate / 100));
        $vat = $gross - $net;
        return [
            'gross'      => $gross,
            'net'        => $net,
            'vat_amount' => $vat,
            'rate'       => $rate,
            'qty'        => $qty,
        ];
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
            $age = $now - (int)strtotime((string)$cached['checked_at']);
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
        $status = (string)($res['status'] ?? 'unverified');
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
            ':ck'  => date('Y-m-d H:i:s', $now),
            ':ttl' => $ttl,
            ':raw' => $res['raw'] ?? null,
        ]);

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
