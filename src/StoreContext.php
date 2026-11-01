<?php
/**
 * Immutable identity and merchant settings for the active store/channel.
 */
class StoreContext {
    private static ?self $current = null;

    private string $id;
    private string $channel;
    private array $config;
    private string $providerCurrency;

    private function __construct(string $id, string $channel, array $config, ?string $providerCurrency = null) {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $id)) {
            throw new InvalidArgumentException('Invalid store ID');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $channel)) {
            throw new InvalidArgumentException('Invalid channel ID');
        }
        foreach (['catalog_scope', 'provider_scope'] as $scope) {
            if (isset($config[$scope]) && !preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', (string)$config[$scope])) {
                throw new InvalidArgumentException("Store {$id} has invalid {$scope}");
            }
        }
        $currency = strtolower((string)($config['currency'] ?? ''));
        if (!preg_match('/^[a-z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Store {$id} has invalid currency");
        }

        $this->id = $id;
        $this->channel = $channel;
        $this->config = $config + ['id' => $id, 'channel' => $channel];
        $this->config['id'] = $id;
        $this->config['channel'] = $channel;
        $this->config['currency'] = $currency;
        $providerCurrency = strtolower((string)$providerCurrency);
        $this->providerCurrency = preg_match('/^[a-z]{3}$/', $providerCurrency) ? $providerCurrency : $currency;
    }

    public static function resolve(
        array $rootConfig,
        ?string $uri = null,
        ?string $host = null,
        ?string $explicitStoreId = null,
        string $channel = 'storefront'
    ): self {
        $stores = $rootConfig['stores'] ?? [];
        if (!is_array($stores) || $stores === []) {
            $stores = [
                'default' => [
                    'name' => 'Default store',
                    'default_locale' => $rootConfig['default_locale'] ?? 'en',
                    'languages' => [$rootConfig['default_locale'] ?? 'en'],
                    'currency' => $rootConfig['default_currency'] ?? 'eur',
                    'url_prefix' => '',
                    'domains' => [],
                    'permissions' => ['*'],
                    'legal' => [],
                ],
            ];
        }

        $defaultId = (string)($rootConfig['default_store'] ?? 'default');
        if (!isset($stores[$defaultId])) {
            $defaultId = isset($stores['default']) ? 'default' : (string)array_key_first($stores);
        }

        $storeId = $defaultId;
        if ($explicitStoreId !== null) {
            if (!isset($stores[$explicitStoreId])) {
                throw new InvalidArgumentException('Unknown store ID');
            }
            $storeId = $explicitStoreId;
        } else {
            $normalizedHost = self::normalizeHost($host ?? ($_SERVER['HTTP_HOST'] ?? ''));
            $hostMatched = false;
            if ($normalizedHost !== '') {
                foreach ($stores as $candidateId => $candidate) {
                    $domains = $candidate['domains'] ?? [];
                    if (is_string($domains)) $domains = [$domains];
                    foreach ($domains as $domain) {
                        if (self::normalizeHost((string)$domain) === $normalizedHost) {
                            $storeId = (string)$candidateId;
                            $hostMatched = true;
                            break 2;
                        }
                    }
                }
            }

            if (!$hostMatched) {
                $path = parse_url($uri ?? ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
                $bestLength = 0;
                foreach ($stores as $candidateId => $candidate) {
                    $prefix = rtrim((string)($candidate['url_prefix'] ?? ''), '/');
                    if ($prefix === '' || !str_starts_with($prefix, '/')) continue;
                    if (($path === $prefix || str_starts_with($path, $prefix . '/')) && strlen($prefix) > $bestLength) {
                        $storeId = (string)$candidateId;
                        $bestLength = strlen($prefix);
                    }
                }
            }
        }

        $config = $stores[$storeId];
        $channels = $config['channels'] ?? ['storefront'];
        if (!is_array($channels) || !in_array($channel, $channels, true)) {
            throw new InvalidArgumentException("Channel {$channel} is not enabled for store {$storeId}");
        }
        $providerOwner = $stores[(string)($config['provider_scope'] ?? $storeId)] ?? null;
        return new self($storeId, $channel, $config, is_array($providerOwner) ? (string)($providerOwner['currency'] ?? '') : null);
    }

    /**
     * The active store's time zone becomes PHP's default, so every local date (invoice date and number year, VAT rate
     * in force, deadlines, cron calendars) is the shop's wall clock, whatever php.ini or the container says.
     */
    public static function activate(self $context): void {
        self::$current = $context;
        date_default_timezone_set($context->timezone());
    }

    /** Fallback when a store sets no (valid) `timezone` and its seller country has no single obvious one. */
    public const DEFAULT_TIMEZONE = 'Europe/Bratislava';

    /** Seller countries (legal.country) whose shops keep one wall clock. */
    private const COUNTRY_TIMEZONES = [
        'SK' => 'Europe/Bratislava', 'CZ' => 'Europe/Prague', 'PL' => 'Europe/Warsaw', 'HU' => 'Europe/Budapest',
        'AT' => 'Europe/Vienna', 'DE' => 'Europe/Berlin', 'SI' => 'Europe/Ljubljana', 'HR' => 'Europe/Zagreb',
        'IT' => 'Europe/Rome', 'FR' => 'Europe/Paris', 'ES' => 'Europe/Madrid', 'PT' => 'Europe/Lisbon',
        'NL' => 'Europe/Amsterdam', 'BE' => 'Europe/Brussels', 'LU' => 'Europe/Luxembourg', 'IE' => 'Europe/Dublin',
        'DK' => 'Europe/Copenhagen', 'SE' => 'Europe/Stockholm', 'FI' => 'Europe/Helsinki', 'EE' => 'Europe/Tallinn',
        'LV' => 'Europe/Riga', 'LT' => 'Europe/Vilnius', 'RO' => 'Europe/Bucharest', 'BG' => 'Europe/Sofia',
        'GR' => 'Europe/Athens', 'CY' => 'Asia/Nicosia', 'MT' => 'Europe/Malta', 'GB' => 'Europe/London',
        'CH' => 'Europe/Zurich', 'NO' => 'Europe/Oslo',
    ];

    /** The configured `timezone` of a store record when it is a valid identifier, else null. */
    public static function configuredTimezone(array $storeConfig): ?string {
        $zone = trim((string)($storeConfig['timezone'] ?? ''));
        return $zone !== '' && in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : null;
    }

    /** A store's wall clock: its `timezone`, else its seller country's, else DEFAULT_TIMEZONE. */
    public static function timezoneFor(array $storeConfig): string {
        $country = strtoupper(trim((string)($storeConfig['legal']['country'] ?? '')));
        return self::configuredTimezone($storeConfig) ?? self::COUNTRY_TIMEZONES[$country] ?? self::DEFAULT_TIMEZONE;
    }

    public function timezone(): string { return self::timezoneFor($this->config); }

    public static function hasCurrent(): bool { return self::$current !== null; }

    public static function current(): self {
        if (self::$current === null) {
            self::activate(self::resolve(class_exists('Bootstrap') ? Bootstrap::stores() : []));
        }
        return self::$current;
    }

    public static function reset(): void {
        self::$current = null;
    }

    public function id(): string { return $this->id; }
    public function channel(): string { return $this->channel; }
    public function catalogId(): string { return (string)($this->config['catalog_scope'] ?? $this->id); }
    public function providerId(): string { return (string)($this->config['provider_scope'] ?? $this->id); }
    public function cacheNamespace(): string { return 'stores/' . $this->id . '/' . $this->channel; }
    public function defaultLocale(): string { return (string)($this->config['default_locale'] ?? 'en'); }
    public function currency(): string { return (string)$this->config['currency']; }
    /**
     * Whether the provider scope's price lists (shipping rates, COD surcharges, free-shipping thresholds, payment
     * fees and limits) are in the currency being charged - the cart's, which is the products' currency and can differ
     * from the store's; the store's when none is given. They are entered in the currency of the store that owns the
     * scope and must never be charged as amounts of another currency.
     */
    public function usesProviderPrices(?string $chargeCurrency = null): bool {
        return $this->providerCurrency === strtolower($chargeCurrency ?? $this->currency());
    }
    public function legal(): array { return is_array($this->config['legal'] ?? null) ? $this->config['legal'] : []; }
    public function config(): array { return $this->config; }

    public function allows(string $permission): bool {
        $permissions = $this->config['permissions'] ?? ['*'];
        return is_array($permissions)
            && (in_array('*', $permissions, true) || in_array($permission, $permissions, true));
    }

    public function assertPermission(string $permission): void {
        if (!$this->allows($permission)) {
            throw new RuntimeException("Store {$this->id} does not allow {$permission}");
        }
    }

    private static function normalizeHost(string $host): string {
        $host = strtolower(trim($host));
        if ($host === '') return '';
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            return $end === false ? $host : substr($host, 0, $end + 1);
        }
        return explode(':', $host, 2)[0];
    }
}
