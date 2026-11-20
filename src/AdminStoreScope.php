<?php
/** Which stores the signed-in admin may administer, and which one an admin request works in. */
final class AdminStoreScope {
    /**
     * Store ids the admin may work in. A database identity needs a non-empty grant for the store;
     * the legacy config admin (no identity) administers every configured store.
     * @return list<string>
     */
    public static function allowed(array $storesCfg, array $session): array {
        $stores = is_array($storesCfg['stores'] ?? null) && $storesCfg['stores'] !== [] ? $storesCfg['stores'] : ['default' => []];
        $ids = array_map('strval', array_keys($stores));
        if (empty($session['admin_identity_id'])) return $ids;
        $grants = is_array($session['admin_store_permissions'] ?? null) ? $session['admin_store_permissions'] : [];
        return array_values(array_filter($ids, static fn(string $id): bool => is_array($grants[$id] ?? null) && $grants[$id] !== []));
    }

    /**
     * Store for this request: an explicitly requested store must be allowed (null = reject the request);
     * otherwise the remembered choice, then the host/path store, then the first allowed store.
     */
    public static function select(array $allowed, ?string $requested, ?string $remembered, string $resolved): ?string {
        if ($requested !== null) return in_array($requested, $allowed, true) ? $requested : null;
        foreach ([$remembered, $resolved] as $candidate) {
            if ($candidate !== null && in_array($candidate, $allowed, true)) return $candidate;
        }
        return $allowed[0] ?? null;
    }

    /**
     * R50 (setup): how the admin names a store — its name with the code and where it is served (first domain, else its
     * URL prefix), e.g. "Slovenský obchod (default · shop.example.sk)": sample stores share names, and a coupon was
     * created in the wrong one.
     */
    public static function label(string $storeId, array $store): string {
        $name = trim((string)($store['name'] ?? ''));
        $domains = array_values(array_filter(array_map(static fn($d): string => trim(is_string($d) ? $d : ''), (array)($store['domains'] ?? []))));
        $prefix = trim((string)($store['url_prefix'] ?? ''), '/');
        $where = $domains[0] ?? ($prefix !== '' ? '/' . $prefix : '');
        $detail = $storeId . ($where !== '' ? ' · ' . $where : '');
        return $name !== '' && $name !== $storeId ? "{$name} ({$detail})" : $detail;
    }

    /**
     * R50 (setup): a form that creates something in the active store names that store, and posts it back (?store= is
     * checked against the grants and activated by adminActivateStore()), so a form opened before switching stores in
     * another tab still saves where it said it would.
     */
    public static function targetField(): string {
        $store = StoreContext::current();
        return '<input type="hidden" name="store" value="' . esc($store->id()) . '"><p class="muted" data-target-store>'
            . esc(I18n::format('admin.store_switcher.target', ['store' => self::label($store->id(), $store->config())])) . '</p>';
    }
}
