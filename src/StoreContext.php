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

    public static function activate(self $context): void {
        self::$current = $context;
    }

    public static function hasCurrent(): bool { return self::$current !== null; }

    public static function current(): self {
        if (self::$current === null) {
            self::$current = self::resolve(class_exists('Bootstrap') ? Bootstrap::stores() : []);
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
    public function ossEnabled(): bool { return (bool)($this->config['oss_enabled'] ?? false); }
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
