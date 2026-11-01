<?php
/**
 * Whether checkout can take the payments it offers: every active online payment method of every store, its gateway
 * and that gateway's credentials. Checkout sends the buyer to the method's provider (payment_methods.provider), not
 * to provider_defaults.payment, so the doctor and the staging preflight both report from here.
 */
final class PaymentReadiness
{
    /** Credentials the shipped gateways read: config key => environment variable that overrides it (null = config only). */
    private const CREDENTIALS = [
        'stripe' => ['stripe_secret_key' => null, 'stripe_webhook_secret' => null],
        'payu' => ['payu_pos_id' => 'PAY_PAYU_POS_ID', 'payu_client_id' => 'PAY_PAYU_CLIENT_ID', 'payu_client_secret' => 'PAY_PAYU_CLIENT_SECRET', 'payu_second_key' => 'PAY_PAYU_SECOND_KEY'],
        'gopay' => ['gopay_goid' => 'PAY_GOPAY_GOID', 'gopay_client_id' => 'PAY_GOPAY_CLIENT_ID', 'gopay_client_secret' => 'PAY_GOPAY_CLIENT_SECRET'],
        'comgate' => ['comgate_merchant' => 'PAY_COMGATE_MERCHANT', 'comgate_secret' => 'PAY_COMGATE_SECRET'],
        'thepay' => ['thepay_merchant_id' => 'PAY_THEPAY_MERCHANT_ID', 'thepay_project_id' => 'PAY_THEPAY_PROJECT_ID', 'thepay_password' => 'PAY_THEPAY_PASSWORD'],
    ];

    /**
     * One check per provider of the active online methods: [status pass|warn|fail, name, message, remediation].
     * With $stores (stores.json "stores") only the price lists a store still sells from count: the methods of a removed
     * store stay in the database but no buyer reaches them.
     * @return list<array{0:string,1:string,2:string,3:string}>
     */
    public static function checks(PDO $db, array $config, ?array $stores = null): array {
        try {
            $rows = $db->query('SELECT store_id, code, provider FROM payment_methods WHERE active = 1 AND requires_online = 1 ORDER BY provider, store_id, code')->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $rows = [['store_id' => 'default', 'code' => 'card', 'provider' => 'stripe']]; // checkout's fallback method without the tables
        }
        if ($stores !== null) {
            $scopes = [];
            foreach ($stores as $storeId => $store) if (is_array($store)) $scopes[(string)($store['provider_scope'] ?? $storeId)] = true;
            $rows = array_values(array_filter($rows, static fn(array $row): bool => isset($scopes[(string)$row['store_id']])));
        }
        if ($rows === []) {
            return [['warn', 'payment.online', 'No active online payment method: checkout offers only offline payment (bank transfer, cash on delivery, invoice).', 'Activate a card method in admin → Payments if the shop takes online payments.']];
        }
        $byProvider = [];
        foreach ($rows as $row) $byProvider[(string)$row['provider']][] = $row['store_id'] . ':' . $row['code'];
        $known = self::knownProviders($config);
        $checks = [];
        foreach ($byProvider as $provider => $methods) {
            $name = 'payment.provider.' . $provider;
            $used = 'method ' . implode(', ', $methods);
            if ($provider === 'mock') {
                $checks[] = ['fail', $name, "The test gateway mock takes payments ({$used}): buyers pay nothing.", 'Deactivate the method or switch it to a real provider in admin → Payments.'];
            } elseif (!in_array($provider, $known, true)) {
                $checks[] = ['fail', $name, "Unknown payment provider {$provider} ({$used}): checkout fails when the buyer chooses it.", 'Use a shipped provider code (' . implode(', ', array_keys(self::CREDENTIALS)) . ') or enable the extension that provides it.'];
            } elseif (!isset(self::CREDENTIALS[$provider])) {
                $checks[] = ['pass', $name, "Payment provider {$provider} comes from configuration or an extension ({$used}); its credentials are not checked here.", ''];
            } else {
                $missing = self::missingCredentials($provider, $config);
                $testMode = $missing === [] ? self::testMode($provider, $config) : null;
                $checks[] = match (true) {
                    $missing !== [] => ['fail', $name, "Payment provider {$provider} lacks " . implode(', ', $missing) . " ({$used}): checkout cannot start these payments.", 'Set the listed keys in config.php or the environment, or deactivate the method.'],
                    $testMode !== null => ['warn', $name, "Payment provider {$provider} runs in test mode ({$testMode}; {$used}): orders are marked paid without real money. Fine for staging, never for a live shop.", "Switch {$provider} to production ({$testMode}) before taking real orders."],
                    default => ['pass', $name, "Payment provider {$provider} has its credentials ({$used}).", ''],
                };
            }
        }
        return $checks;
    }

    /**
     * Whether checkout may offer an online method of $provider: false for a shipped gateway missing its credentials
     * (a fresh install's card method without Stripe keys), so the buyer is never sent into a payment that cannot start.
     * Providers whose credentials are not known here (extensions, configured adapters, mock) are offered.
     */
    public static function canTakePayments(string $provider, array $config): bool {
        return !isset(self::CREDENTIALS[$provider]) || self::missingCredentials($provider, $config) === [];
    }

    /** @return list<string> */
    private static function missingCredentials(string $provider, array $config): array {
        $missing = [];
        foreach (self::CREDENTIALS[$provider] as $key => $env) {
            $value = trim((string)(($env !== null ? getenv($env) : false) ?: ($config[$key] ?? '')));
            if (!self::filled($key, $value)) $missing[] = $env !== null ? "{$key} ({$env})" : $key;
        }
        return $missing;
    }

    /** The setting that keeps a gateway on test payments, or null in production mode (same defaults as the adapters). */
    private static function testMode(string $provider, array $config): ?string {
        $value = static fn(string $key, ?string $env, string $default): string => strtolower(trim((string)(($env !== null ? getenv($env) : false) ?: ($config[$key] ?? $default))));
        return match ($provider) {
            'stripe' => preg_match('/^(sk|rk)_test_/', $value('stripe_secret_key', null, '')) ? 'stripe_secret_key is a test key' : null,
            'comgate' => filter_var($value('comgate_test', 'PAY_COMGATE_TEST', 'false'), FILTER_VALIDATE_BOOLEAN) ? 'comgate_test = true' : null,
            'gopay' => $value('gopay_env', 'PAY_GOPAY_ENV', 'sandbox') !== 'production' ? 'gopay_env is not production' : null,
            'payu' => $value('payu_env', 'PAY_PAYU_ENV', 'sandbox') !== 'production' ? 'payu_env is not production' : null,
            'thepay' => $value('thepay_env', 'PAY_THEPAY_ENV', 'demo') !== 'production' ? 'thepay_env is not production' : null,
            default => null,
        };
    }

    private static function filled(string $key, string $value): bool {
        if ($value === '') return false;
        $lower = strtolower($value);
        foreach (['...', 'your_', 'example', 'changeme', 'placeholder', 'xxx'] as $needle) {
            if (str_contains($lower, $needle)) return false;
        }
        return match ($key) {
            'stripe_secret_key' => preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]{16,}$/', $value) === 1,
            'stripe_webhook_secret' => preg_match('/^whsec_[A-Za-z0-9]{16,}$/', $value) === 1,
            default => true,
        };
    }

    /** @return list<string> */
    private static function knownProviders(array $config): array {
        try {
            $codes = array_keys(Adapters::registry('payment'));
        } catch (Throwable $e) {
            $codes = array_merge(array_keys(self::CREDENTIALS), ['cod']);
        }
        return array_values(array_unique(array_map('strval', array_merge($codes, array_keys((array)($config['providers']['payment'] ?? []))))));
    }
}
