<?php
/** Consent-aware first-party hooks; no analytics/marketing signal is emitted before opt-in. */
class MarketingConsent {
    public static function categories(array $cookies): array {
        foreach (['cc_v1', 'cookie_consent'] as $name) {
            if (!isset($cookies[$name])) continue;
            $decoded = json_decode((string)$cookies[$name], true);
            if (is_array($decoded)) {
                return [
                    'necessary' => true,
                    'analytics' => !empty($decoded['analytics']),
                    'marketing' => !empty($decoded['marketing']),
                ];
            }
        }
        return ['necessary' => true, 'analytics' => false, 'marketing' => false];
    }

    public static function hooks(array $cookies, string $nonce): string {
        $categories = self::categories($cookies);
        if (!$categories['analytics'] && !$categories['marketing']) return '';
        $payload = json_encode([
            'analytics' => $categories['analytics'],
            'marketing' => $categories['marketing'],
        ], JSON_THROW_ON_ERROR);
        $nonce = htmlspecialchars($nonce, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '<script nonce="' . $nonce . '">(function(){var consent=' . $payload
            . ';window.BareBonesConsent=Object.freeze(consent);window.dispatchEvent(new CustomEvent('
            . "'barebones:consent-ready',{detail:consent}));})();</script>";
    }
}
