<?php
declare(strict_types=1);

/**
 * The `cli/setup.php doctor` checks for the admin dashboard: what still needs attention, each with the admin page that
 * fixes it (or the doctor's remediation when only config.php or the server can).
 */
final class SetupChecklist
{
    /** Check-name prefix => admin page that fixes it (longest prefix wins). */
    private const FIX_PAGES = [
        'stores.currency_prices.' => 'price_lists',
        'stores.' => 'stores',
        'payment.' => 'payments',
        'admin.' => 'admin_users',
        'provider.shipping' => 'shipping',
        'provider.mail' => 'mail', // R29: the SMTP server and the sender are set in Admin → E-mail delivery (provider.mail, provider.mail_from)
        'catalog.digital_files' => 'digital_assets',
    ];

    /**
     * @return array{counts: array{fail: int, warn: int}, items: list<array{status: string, name: string, title: ?string, message: string, action: string, page: ?string}>}
     */
    public static function summary(?array $doctor = null): array
    {
        try {
            if ($doctor === null) {
                $doctor = Setup::doctor(Security::config());
                // The store the admin is switched to, as resolved for this request, decides which catalog is priced.
                $store = StoreContext::current();
                $own = Setup::storeCurrencyCheck(Db::get(), $store->id(), $store->config());
                $doctor['checks'] = array_map(static fn(array $c): array => ($c['name'] ?? '') === $own['name'] ? $own : $c, (array)($doctor['checks'] ?? []));
            }
        } catch (Throwable $e) {
            Logger::warning('Setup checklist: doctor failed', ['exception' => get_class($e)]);
            $doctor = ['checks' => [['status' => 'fail', 'name' => 'doctor', 'message' => 'The setup doctor could not run.', 'action' => 'Run php cli/setup.php doctor on the server.']]];
        }
        $items = [];
        $counts = ['fail' => 0, 'warn' => 0];
        foreach ((array)($doctor['checks'] ?? []) as $check) {
            $status = (string)($check['status'] ?? '');
            $name = (string)($check['name'] ?? '');
            if (!isset($counts[$status]) || !self::concernsCurrentStore($name)) continue;
            $counts[$status]++;
            $items[] = ['status' => $status, 'name' => $name, 'title' => self::title($name), 'message' => (string)($check['message'] ?? ''),
                'action' => (string)($check['action'] ?? ''), 'page' => self::fixPage($name)];
        }
        usort($items, static fn(array $a, array $b): int => [$a['status'] !== 'fail', $a['name']] <=> [$b['status'] !== 'fail', $b['name']]);
        return ['counts' => $counts, 'items' => $items];
    }

    /** A per-store check (its SKUs, seller data) is shown only in the store the dashboard is switched to. */
    private static function concernsCurrentStore(string $checkName): bool
    {
        if (!preg_match('/^stores\.(?:legal|currency_prices|oss_threshold)\.(.+)$/', $checkName, $m)) return true;
        return $m[1] === StoreContext::current()->id();
    }

    /**
     * R23 UX: what a failing or warning check means, in the admin's language (admin.doctor.<check>; {item} is the store,
     * provider, extension or path the check names). Null for a check without a translation: its doctor text is shown.
     */
    public static function title(string $checkName): ?string
    {
        $group = $checkName;
        $item = '';
        foreach (self::ITEM_GROUPS as $prefix) {
            if (str_starts_with($checkName, $prefix) && strlen($checkName) > strlen($prefix)) {
                [$group, $item] = [rtrim($prefix, '.'), substr($checkName, strlen($prefix))];
                break;
            }
        }
        if ($item === '' && str_starts_with($checkName, 'runtime.') && !in_array($checkName, ['runtime.php', 'runtime.vendor_bbf'], true)) {
            [$group, $item] = ['runtime.path', substr($checkName, strlen('runtime.'))];
        }
        $key = 'admin.doctor.' . str_replace('.', '_', $group);
        $text = I18n::format($key, ['item' => $item]);
        return $text === $key ? null : $text;
    }

    /** Check-name prefixes followed by the store, provider or extension the check is about. */
    private const ITEM_GROUPS = ['stores.legal.', 'stores.url_prefix.', 'stores.timezone.', 'stores.currency_prices.', 'stores.oss_threshold.', 'payment.provider.', 'runtime.extension.'];

    public static function fixPage(string $checkName): ?string
    {
        foreach (self::FIX_PAGES as $prefix => $page) {
            if (str_starts_with($checkName, $prefix)) return $page;
        }
        return null;
    }
}
