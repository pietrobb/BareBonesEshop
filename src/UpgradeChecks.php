<?php
/**
 * Configuration that worked before an upgrade but silently changes behaviour after it; shared by
 * `php cli/setup.php doctor` and `php cli/staging-preflight.php` (see CHANGELOG "Upgrade notes").
 */
final class UpgradeChecks
{
    /**
     * Active outbound webhook endpoints with a plain http:// URL and no allow_insecure_http opt-out:
     * the outbox refuses to deliver to them (Webhook::endpointProblem).
     * @return array<string, string> endpoint code => problem
     */
    public static function insecureHttpWebhooks(array $config): array
    {
        $insecure = [];
        foreach ((array)($config['outbound_webhooks'] ?? []) as $code => $endpoint) {
            if (!is_array($endpoint) || (array_key_exists('active', $endpoint) && !$endpoint['active'])) continue;
            if (strtolower((string)parse_url(trim((string)($endpoint['url'] ?? '')), PHP_URL_SCHEME)) !== 'http') continue;
            $problem = Webhook::endpointProblem((string)$code, $endpoint);
            if ($problem !== null && str_contains($problem, 'https')) $insecure[(string)$code] = $problem;
        }
        return $insecure;
    }

    /**
     * Cart reminders switched on without an active checkout consent 'cart_reminder': no cart is
     * stored for reminders and nothing is sent (AbandonedCarts only reminds consented carts).
     */
    public static function cartReminderWithoutConsent(array $config): bool
    {
        if (empty(((array)($config['cart_reminder'] ?? []))['enabled'])) return false;
        $consent = ((array)($config['checkout_consents'] ?? []))[AbandonedCarts::CONSENT_KEY] ?? null;
        return !is_array($consent) || ($consent['active'] ?? true) === false || trim((string)($consent['text'] ?? '')) === '';
    }

    /**
     * Whether the live nginx site config (config operations.nginx_config) sends missing .php
     * scripts to @redirect, which store-prefixed AJAX (/cs/api.php) needs. Null when not
     * determinable: no path configured, the file is unreadable, or it has no PHP location.
     */
    public static function nginxPhpFallback(array $config): ?bool
    {
        $path = trim((string)(((array)($config['operations'] ?? []))['nginx_config'] ?? ''));
        if ($path === '' || !is_file($path) || !is_readable($path)) return null;
        $conf = file_get_contents($path);
        return $conf === false ? null : self::nginxConfHasPhpFallback($conf);
    }

    /** @return bool|null null when the config has no `location ~ \.php$` block */
    public static function nginxConfHasPhpFallback(string $conf): ?bool
    {
        $conf = (string)preg_replace('/#[^\n]*/', '', $conf);
        if (!preg_match_all('/location\s+~\*?\s+[^{]*\\\\\.php[^{]*\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/', $conf, $blocks)) return null;
        foreach ($blocks[1] as $body) {
            if (preg_match('/try_files\s+\$uri\s+@redirect\s*;/', $body)) return true;
        }
        return false;
    }
}
