<?php
/** Config-driven checkout consent validation and immutable order evidence. */
class ConsentService {
    private static ?array $definitionsOverride = null;

    public static function setDefinitionsForTesting(?array $definitions): void {
        self::$definitionsOverride = $definitions;
    }

    public static function definitions(array $context = []): array {
        $cfg = self::$definitionsOverride !== null
            ? ['checkout_consents' => self::$definitionsOverride]
            : require __DIR__ . '/../config.php';
        $definitions = $cfg['checkout_consents'] ?? [];
        $result = [];
        foreach ($definitions as $key => $definition) {
            if (!preg_match('/^[a-z0-9_-]{2,80}$/', (string)$key) || !is_array($definition)) continue;
            if (($definition['active'] ?? true) === false || !self::applies($definition, $context)) continue;
            $url = (string)($definition['url'] ?? '');
            if (preg_match('/[\x00-\x20\x7F\\\\]/', $url) || !(preg_match('#^https?://#i', $url) || (str_starts_with($url, '/') && !str_starts_with($url, '//')))) $url = '';
            $result[$key] = [
                'key' => (string)$key,
                'version' => (string)($definition['version'] ?? '1'),
                'required' => !empty($definition['required']),
                'text' => (string)($definition['text'] ?? ''),
                'url' => $url,
            ];
        }
        return $result;
    }

    public static function validate(array $submitted, array $context = []): array {
        $errors = [];
        foreach (self::definitions($context) as $key => $definition) {
            if ($definition['required'] && (($submitted[$key] ?? null) !== true)) {
                $errors[$key] = 'Consent is required';
            }
        }
        return $errors;
    }

    public static function record(int $orderId, array $submitted, array $context, string $ip, string $userAgent): void {
        $definitions = self::definitions($context);
        $stmt = Db::get()->prepare(Db::insertIgnore() . " INTO order_consents
            (order_id, consent_key, version, consent_text, accepted, ip_hash, user_agent)
            VALUES (:order_id, :consent_key, :version, :consent_text, 1, :ip_hash, :user_agent)");
        foreach ($definitions as $key => $definition) {
            if (($submitted[$key] ?? null) !== true) continue;
            $stmt->execute([
                ':order_id' => $orderId,
                ':consent_key' => $key,
                ':version' => $definition['version'],
                ':consent_text' => $definition['text'],
                ':ip_hash' => GdprService::hashPii($ip),
                ':user_agent' => substr($userAgent, 0, 500),
            ]);
        }
    }

    public static function forOrder(int $orderId): array {
        $stmt = Db::get()->prepare("SELECT * FROM order_consents WHERE order_id = :order_id ORDER BY consent_key");
        $stmt->execute([':order_id' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function applies(array $definition, array $context): bool {
        $countries = array_map('strtoupper', (array)($definition['countries'] ?? []));
        if ($countries && !in_array(strtoupper((string)($context['country'] ?? '')), $countries, true)) return false;
        $types = array_map('strtolower', (array)($definition['product_types'] ?? []));
        if ($types) {
            $cartTypes = array_map('strtolower', (array)($context['product_types'] ?? []));
            if (!array_intersect($types, $cartTypes)) return false;
        }
        return true;
    }
}
