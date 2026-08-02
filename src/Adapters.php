<?php
/**
 * BareBones Shop — Adapter Factory
 * Resolves adapter names from config to concrete implementations.
 */
class Adapters {
    private static array $instances = [];

    private const BUILTIN_PROVIDERS = [
        'payment' => [
            'stripe' => ['class' => 'StripePayment', 'capabilities' => ['checkout', 'status', 'webhook', 'refund'], 'signature_header' => 'HTTP_STRIPE_SIGNATURE'],
            'payu' => ['class' => 'PayUPayment', 'capabilities' => ['checkout', 'status', 'webhook', 'refund'], 'signature_header' => 'HTTP_OPENPAYU_SIGNATURE'],
            'mock' => ['class' => 'MockPayment', 'capabilities' => ['checkout', 'status', 'webhook', 'refund'], 'signature_header' => 'HTTP_X_MOCK_SIGNATURE'],
            'cod' => ['class' => 'CashOnDelivery', 'capabilities' => ['checkout', 'status', 'refund']],
        ],
        'shipping' => [
            'pickup' => ['class' => 'PersonalPickup', 'capabilities' => ['rates', 'label', 'tracking']],
            'packeta' => ['class' => 'PacketaShipping', 'capabilities' => ['rates', 'label', 'tracking', 'cod']],
            'gls' => ['class' => 'GlsShipping', 'capabilities' => ['rates', 'label', 'tracking', 'cod']],
            'http-carrier' => ['class' => 'HttpShipping', 'capabilities' => ['rates', 'pickup', 'label', 'cancel', 'tracking'], 'requires_config' => 'http_shipping'],
        ],
        'mail' => [
            'log' => ['class' => 'LogMail', 'capabilities' => ['send']],
            'smtp' => ['class' => 'SmtpMail', 'capabilities' => ['send', 'attachments']],
            'ses' => ['class' => 'SesMail', 'capabilities' => ['send']],
        ],
        'tax' => [
            'vat' => ['class' => 'VatService', 'capabilities' => ['scenario', 'rates', 'snapshot', 'validate']],
        ],
        'erp' => [
            'http' => ['class' => 'HttpErpAdapter', 'capabilities' => ['products', 'orders', 'order_status', 'stock', 'customers', 'health'], 'requires_config' => 'http_erp'],
        ],
        'pim' => [],
        'wms' => [],
    ];

    private const PROVIDER_INTERFACES = [
        'payment' => PaymentAdapter::class,
        'shipping' => ShippingAdapter::class,
        'mail' => MailAdapter::class,
        'tax' => TaxProvider::class,
        'erp' => IntegrationAdapter::class,
        'pim' => PimAdapter::class,
        'wms' => WmsAdapter::class,
    ];

    private static function cfg(): array {
        static $cfg;
        return $cfg ??= require __DIR__ . '/../config.php';
    }

    /** Stable provider definitions, merged with additive deployment config. */
    public static function registry(?string $type = null): array {
        $registry = self::BUILTIN_PROVIDERS;
        foreach ((array)(self::cfg()['providers'] ?? []) as $providerType => $definitions) {
            if (!array_key_exists($providerType, $registry) || !is_array($definitions)) {
                throw new \RuntimeException("Invalid provider type configuration: {$providerType}");
            }
            foreach ($definitions as $code => $definition) {
                self::assertProviderCode((string)$code);
                if (is_string($definition)) $definition = ['class' => $definition];
                if (!is_array($definition) || empty($definition['class'])) {
                    throw new \RuntimeException("Invalid {$providerType} provider definition: {$code}");
                }
                $registry[$providerType][$code] = $definition + ($registry[$providerType][$code] ?? ['capabilities' => []]);
            }
        }
        $scope = class_exists('StoreContext') ? StoreContext::current()->providerId() : 'default';
        foreach ($registry as &$definitions) {
            foreach ($definitions as &$definition) $definition['scope'] = $scope;
        }
        unset($definitions, $definition);
        if ($type === null) return $registry;
        if (!array_key_exists($type, $registry)) throw new \InvalidArgumentException("Unknown provider type: {$type}");
        return $registry[$type];
    }

    /** @return array<string,object> */
    public static function providers(string $type): array {
        $providers = [];
        foreach (array_keys(self::availableRegistry($type)) as $code) {
            $providers[$code] = self::provider($type, $code);
        }
        return $providers;
    }

    public static function provider(string $type, string $code): object {
        $class = self::providerClass($type, $code);
        $scope = class_exists('StoreContext') ? StoreContext::current()->providerId() : 'default';
        $key = "provider:{$type}:{$scope}:{$code}";
        if (isset(self::$instances[$key])) return self::$instances[$key];

        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $instance = $constructor && $constructor->getNumberOfParameters() > 0
            ? $reflection->newInstance(self::cfg())
            : $reflection->newInstance();
        return self::$instances[$key] = $instance;
    }

    /** @return class-string */
    public static function providerClass(string $type, string $code): string {
        self::assertProviderCode($code);
        $cfg = self::cfg();
        if ($type === 'payment' && $code === 'mock'
            && ($cfg['adapters']['payment'] ?? '') !== 'MockPayment'
            && empty($cfg['allow_mock_webhook'])) {
            throw new \RuntimeException('Mock payment provider is disabled');
        }
        $definition = self::registry($type)[$code] ?? null;
        if (!$definition) throw new \RuntimeException("Unknown {$type} provider: {$code}");
        $class = (string)$definition['class'];
        self::loadClass($class);
        $interface = self::PROVIDER_INTERFACES[$type] ?? null;
        if (!$interface || !is_subclass_of($class, $interface)) {
            throw new \RuntimeException("Provider {$type}.{$code} must implement {$interface}");
        }
        return $class;
    }

    public static function providerCode(string $type, object|string $adapter): string {
        $class = is_object($adapter) ? get_class($adapter) : $adapter;
        foreach (self::registry($type) as $code => $definition) {
            if (($definition['class'] ?? null) === $class) return $code;
        }
        throw new \RuntimeException("No stable provider code registered for {$class}");
    }

    public static function payment(?string $code = null): PaymentAdapter {
        // Preserve the explicit legacy test override; production code never sets it.
        if (isset(self::$instances['payment'])) return self::$instances['payment'];
        if ($code !== null) return self::provider('payment', $code);
        return self::provider('payment', self::defaultProviderCode('payment'));
    }

    public static function shipping(string $code): ShippingAdapter {
        return self::provider('shipping', $code);
    }

    public static function mail(?string $code = null): MailAdapter {
        return self::provider('mail', $code ?? self::defaultProviderCode('mail'));
    }

    /** Returns the class because TaxProvider is intentionally a static snapshot contract. */
    public static function tax(?string $code = null): string {
        return self::providerClass('tax', $code ?? self::defaultProviderCode('tax'));
    }

    /** Returns null when no ERP integration adapter is configured. */
    public static function integration(?string $code = null): ?IntegrationAdapter {
        if ($code !== null) return self::provider('erp', $code);
        if (isset(self::$instances['integration'])) return self::$instances['integration'];
        $definitions = self::availableRegistry('erp');
        if ($definitions !== []) return self::provider('erp', self::defaultProviderCode('erp'));
        $cfg = self::cfg();
        if (empty($cfg['adapters']['integration'])) return null;
        return self::resolve('integration');
    }

    public static function pim(?string $code = null): ?PimAdapter {
        if ($code !== null) return self::provider('pim', $code);
        $definitions = self::availableRegistry('pim');
        return $definitions === [] ? null : self::provider('pim', self::defaultProviderCode('pim'));
    }

    public static function wms(?string $code = null): ?WmsAdapter {
        if ($code !== null) return self::provider('wms', $code);
        $definitions = self::availableRegistry('wms');
        return $definitions === [] ? null : self::provider('wms', self::defaultProviderCode('wms'));
    }

    public static function storage(): StorageAdapter {
        return self::resolve('storage');
    }

    public static function metrics(): ?MetricAlertAdapter {
        if (empty(self::cfg()['adapters']['metrics'])) return null;
        $adapter = self::resolve('metrics');
        if (!$adapter instanceof MetricAlertAdapter) {
            throw new RuntimeException('Metrics adapter must implement MetricAlertAdapter');
        }
        return $adapter;
    }

    public static function search(): object {
        return self::resolve('search');
    }

    public static function resolve(string $slot): object {
        if (isset(self::$instances[$slot])) return self::$instances[$slot];

        $class = self::cfg()['adapters'][$slot] ?? null;
        if (!$class) throw new \RuntimeException("No adapter configured for: {$slot}");
        self::loadClass((string)$class);

        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        return self::$instances[$slot] = $constructor && $constructor->getNumberOfParameters() > 0
            ? $reflection->newInstance(self::cfg())
            : $reflection->newInstance();
    }

    public static function defaultProviderCode(string $type): string {
        $cfg = self::cfg();
        if ($type === 'mail') {
            $environmentCode = strtolower((string)(getenv('MAIL_ADAPTER') ?: ''));
            if ($environmentCode !== '') {
                self::assertProviderCode($environmentCode);
                if (!isset(self::registry('mail')[$environmentCode])) {
                    throw new \RuntimeException("Unknown mail provider: {$environmentCode}");
                }
                return $environmentCode;
            }
        }
        $configured = (string)($cfg['provider_defaults'][$type] ?? '');
        if ($configured !== '') {
            self::assertProviderCode($configured);
            return $configured;
        }

        $legacySlot = $type === 'erp' ? 'integration' : $type;
        $legacyClass = (string)($cfg['adapters'][$legacySlot] ?? '');
        if ($legacyClass !== '') {
            foreach (self::registry($type) as $code => $definition) {
                if (($definition['class'] ?? '') === $legacyClass) return $code;
            }
        }

        $defaults = ['payment' => 'stripe', 'mail' => 'log', 'tax' => 'vat'];
        if (isset($defaults[$type])) return $defaults[$type];
        $first = array_key_first(self::availableRegistry($type));
        if ($first === null) throw new \RuntimeException("No {$type} provider configured");
        return (string)$first;
    }

    private static function availableRegistry(string $type): array {
        $definitions = self::registry($type);
        $cfg = self::cfg();
        return array_filter($definitions, static function (array $definition) use ($cfg): bool {
            $required = (string)($definition['requires_config'] ?? '');
            if ($required === '') return true;
            $integration = (array)($cfg['integrations'][$required] ?? []);
            $url = (string)($integration['base_url'] ?? '');
            return filter_var($url, FILTER_VALIDATE_URL)
                && strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https'
                && strlen((string)($integration['api_key'] ?? '')) >= 16;
        });
    }

    private static function assertProviderCode(string $code): void {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', $code)) {
            throw new \InvalidArgumentException("Invalid provider code: {$code}");
        }
    }

    private static function loadClass(string $class): void {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class)) {
            throw new \RuntimeException("Invalid adapter class name: {$class}");
        }
        $adapterFile = __DIR__ . '/../adapters/contrib/' . $class . '.php';
        if (file_exists($adapterFile)) require_once $adapterFile;
        if (!class_exists($class)) throw new \RuntimeException("Adapter class not found: {$class}");
    }

    /** Override a legacy slot or a stable provider (type.code) for testing. */
    public static function set(string $slot, object $instance): void {
        if (str_contains($slot, '.')) {
            [$type, $code] = explode('.', $slot, 2);
            self::assertProviderCode($code);
            $scope = class_exists('StoreContext') ? StoreContext::current()->providerId() : 'default';
            self::$instances["provider:{$type}:{$scope}:{$code}"] = $instance;
            return;
        }
        self::$instances[$slot] = $instance;
    }
}
