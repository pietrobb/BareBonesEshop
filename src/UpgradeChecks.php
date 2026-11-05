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
     * scripts to @redirect, which store-prefixed scripts (/cs/account.php) need. Null when not
     * determinable: no path configured, the file is unreadable, or it has no PHP location.
     */
    public static function nginxPhpFallback(array $config): ?bool
    {
        $path = trim((string)(((array)($config['operations'] ?? []))['nginx_config'] ?? ''));
        if ($path === '' || !is_file($path) || !is_readable($path)) return null;
        $conf = file_get_contents($path);
        return $conf === false ? null : self::nginxConfHasPhpFallback($conf);
    }

    /**
     * The sender MailService uses (MAIL_FROM) and how it fares: 'ok'; 'warn' when unset (the fallback
     * MailService::DEFAULT_FROM, which receiving servers reject or file as spam) or on a reserved domain no
     * mailbox can exist on; 'fail' when it is not an address at all (MailService skips every e-mail).
     * @return array{0: 'ok'|'warn'|'fail', 1: string}
     */
    public static function mailSender(): array
    {
        $from = strtolower(trim((string)(getenv('MAIL_FROM') ?: '')));
        if ($from === '') return ['warn', 'MAIL_FROM is not set: e-mails go out from ' . MailService::DEFAULT_FROM . ', which receiving servers reject or file as spam.'];
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return ['fail', "MAIL_FROM ({$from}) is not a valid e-mail address: no e-mail is sent."];
        $domain = substr($from, strrpos($from, '@') + 1);
        if (preg_match('/(^|\.)(invalid|localhost|local|test|example|example\.(com|net|org))$/', $domain)) {
            return ['warn', "MAIL_FROM ({$from}) uses a reserved domain (still the example value?): receiving servers reject it."];
        }
        return ['ok', "E-mails are sent from {$from}."];
    }

    /**
     * Deny rules of the shipped deploy/nginx.conf (each `location … { return 403; }`) the live nginx site config
     * (operations.nginx_config) lacks: a release that adds one (e.g. *.lock and *.tmp) leaves those files served by an
     * nginx copied from an older sample. Null when not determinable (no path configured, the file is unreadable).
     * @return list<string>|null the missing location patterns
     */
    public static function nginxMissingDenies(array $config, string $appRoot): ?array
    {
        $path = trim((string)(((array)($config['operations'] ?? []))['nginx_config'] ?? ''));
        if ($path === '' || !is_file($path) || !is_readable($path)) return null;
        $live = file_get_contents($path);
        $shipped = @file_get_contents($appRoot . '/deploy/nginx.conf');
        return $live === false || $shipped === false ? null : self::nginxConfMissingDenies($live, $shipped);
    }

    /** @return list<string> deny location patterns of $shipped that $live does not have (comments and spacing ignored) */
    public static function nginxConfMissingDenies(string $live, string $shipped): array
    {
        $denies = static function (string $conf): array {
            $conf = (string)preg_replace('/#[^\n]*/', '', $conf);
            preg_match_all('/location\s+((?:~\*?|=|\^~)\s+\S+)\s*\{\s*return\s+403\s*;\s*\}/', $conf, $m);
            return array_values(array_unique(array_map(static fn(string $l): string => (string)preg_replace('/\s+/', ' ', $l), $m[1])));
        };
        return array_values(array_diff($denies($shipped), $denies($live)));
    }

    /**
     * Carrier webhook secrets configured while shipping_webhook_require_timestamp is not set: the default is now true,
     * so a carrier that signs only the body (no signed X-Shipping-Timestamp) has its tracking webhooks refused.
     * @return list<string> carrier codes with a secret
     */
    public static function carrierWebhooksTimestampDefault(array $config): array
    {
        if (array_key_exists('shipping_webhook_require_timestamp', $config)) return [];
        $configured = (array)($config['shipping_webhook_secrets'] ?? []);
        $carriers = [];
        foreach (array_unique(array_merge(['packeta', 'gls'], array_map('strval', array_keys($configured)))) as $carrier) {
            $secret = (string)(getenv(ShippingService::webhookSecretEnvName($carrier)) ?: ($configured[$carrier] ?? ''));
            if (trim($secret) !== '') $carriers[] = $carrier;
        }
        return $carriers;
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
