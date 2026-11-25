<?php
/**
 * R58 (review round 23, high V1): the one place that says which seller a store sells as and which legal details
 * (country, VAT status, IBAN, name, address, e-mail) apply to it. A store added in Admin → Stores has
 * `"legal": {"seller": "<store>"}`: only the invoices followed that reference, so checkout charged Slovak VAT, the
 * shipping.json home zone was Slovakia, bank transfer was not offered and the legal pages printed no seller. Checkout,
 * shipping, payments, OSS, e-mails, legal pages, feeds and invoices all read the seller through here now.
 *
 * - A store with its own non-empty `legal` block is its own seller, unless legal.seller names another store that is a
 *   seller itself: storefronts of one company then share that store's series and counter (its prefixes; their own legal
 *   fields only override the printed seller details).
 * - A store without a `legal` block is no seller of its own: it sells as the first seller selling in its (the order's)
 *   currency, else as the default store's seller, else as the first seller configured.
 * A reference goes one step: a seller is a store whose own block names no other store, so a loop (a → b → a) or a
 * reference to a missing store resolves to no seller (InvoiceService::numberingConflicts() reports it).
 */
final class SellerLegal {
    /** Seller country when no seller names one, as checkout has always read it. */
    public const FALLBACK_COUNTRY = 'SK';

    /**
     * Seller of a configured store: the store whose invoice and credit-note series its documents use. Null for an
     * unknown store, or a legal.seller that names no seller.
     */
    public static function sellerCode(array $root, string $storeCode, ?string $currency = null): ?string {
        $stores = self::storeMap($root);
        if (!isset($stores[$storeCode])) return null;
        $legal = self::legalBlock($stores[$storeCode]);
        if ($legal === null) return self::fallbackSeller($root, $currency);
        $target = trim((string)($legal['seller'] ?? ''));
        if ($target === '' || $target === $storeCode) return $storeCode;
        return self::ownsSeries($stores, $target) ? $target : null;
    }

    /**
     * R59 (review round 24, finding 4): the seller's tax identity. A store sharing a seller cannot set these: the same VAT
     * payer would charge and invoice another VAT in one number series (a store with "vat_registered": false printed 0 %
     * "not a VAT payer" invoices under the seller's Czech VAT ID). identityOverrides() lists such fields for the doctor.
     */
    public const IDENTITY_FIELDS = ['name', 'country', 'vat_registered', 'ico', 'dic', 'ic_dph'];

    /**
     * Legal details a store's documents print: its seller's, with the store's own fields over them (never the prefixes
     * and, R59, never the seller's tax identity, IDENTITY_FIELDS).
     */
    public static function effective(array $root, string $storeCode, string $sellerCode): array {
        $stores = self::storeMap($root);
        $sellerLegal = isset($stores[$sellerCode]) ? (self::legalBlock($stores[$sellerCode]) ?? []) : [];
        $own = isset($stores[$storeCode]) ? self::legalBlock($stores[$storeCode]) : null;
        if ($own === null || $storeCode === $sellerCode) return $sellerLegal;
        unset($own['seller'], $own['invoice_prefix'], $own['credit_note_prefix']);
        foreach (self::IDENTITY_FIELDS as $field) unset($own[$field]);
        return array_replace($sellerLegal, $own);
    }

    /**
     * R59: the tax identity fields (IDENTITY_FIELDS) a store sharing another store's seller sets to another value than
     * that seller; effective() ignores them. [] for a seller store or a store without legal.seller.
     * @return array<string, array{0: mixed, 1: mixed}> field => [the store's value, the seller's value]
     */
    public static function identityOverrides(array $root, string $storeCode): array {
        $stores = self::storeMap($root);
        $own = isset($stores[$storeCode]) ? self::legalBlock($stores[$storeCode]) : null;
        $target = trim((string)($own['seller'] ?? ''));
        if ($own === null || $target === '' || $target === $storeCode || !self::ownsSeries($stores, $target)) return [];
        $seller = self::legalBlock($stores[$target]) ?? [];
        $overrides = [];
        foreach (self::IDENTITY_FIELDS as $field) {
            if (!array_key_exists($field, $own)) continue;
            $mine = is_string($own[$field]) ? trim($own[$field]) : $own[$field];
            $theirs = is_string($seller[$field] ?? null) ? trim((string)$seller[$field]) : ($seller[$field] ?? null);
            if ($field === 'country' && is_string($mine) && is_string($theirs)) [$mine, $theirs] = [strtoupper($mine), strtoupper($theirs)];
            if ($field === 'vat_registered') [$mine, $theirs] = [(bool)$mine, (bool)$theirs];
            if ($mine !== $theirs) $overrides[$field] = [$own[$field], $seller[$field] ?? null];
        }
        return $overrides;
    }

    /**
     * The legal block a store sells under (checkout VAT, shipping home zone, IBAN, legal pages, e-mails): effective() of
     * its seller. A legal.seller naming no seller (missing store, loop) falls back to the seller a store without a legal
     * block gets, so the storefront is never priced as a Slovak seller's by accident; issuing an invoice still refuses
     * such a store (numberingConflicts()). [] when no store has a seller identity.
     */
    public static function forStore(array $root, string $storeCode, ?string $currency = null): array {
        $stores = self::storeMap($root);
        $currency ??= isset($stores[$storeCode]) ? strtolower((string)($stores[$storeCode]['currency'] ?? '')) : null;
        $seller = self::sellerCode($root, $storeCode, $currency);
        if ($seller === null && isset($stores[$storeCode])) $seller = self::fallbackSeller($root, $currency);
        if ($seller !== null) return self::effective($root, $storeCode, $seller);
        $own = isset($stores[$storeCode]) ? (self::legalBlock($stores[$storeCode]) ?? []) : [];
        unset($own['seller']);
        return $own;
    }

    /** forStore() of a store in the installed stores.json (Bootstrap::stores()), $store standing in for its entry when given. */
    public static function ofStore(string $storeCode, ?array $store = null): array {
        $root = class_exists('Bootstrap') ? Bootstrap::stores() : [];
        if ($store !== null) $root['stores'] = [$storeCode => $store] + (is_array($root['stores'] ?? null) ? $root['stores'] : []);
        return self::forStore($root, $storeCode);
    }

    /** ISO country of a resolved legal block, upper case; FALLBACK_COUNTRY when it names none. */
    public static function country(array $legal): string {
        $country = strtoupper(trim((string)($legal['country'] ?? '')));
        return $country !== '' ? $country : self::FALLBACK_COUNTRY;
    }

    /** @return array<string,array<mixed>> */
    public static function storeMap(array $root): array {
        $stores = [];
        foreach ((array)($root['stores'] ?? []) as $code => $store) {
            if (is_array($store)) $stores[(string)$code] = $store;
        }
        return $stores;
    }

    public static function legalBlock(array $store): ?array {
        return is_array($store['legal'] ?? null) && $store['legal'] !== [] ? $store['legal'] : null;
    }

    /** True when the store is a seller of its own (own legal block, no legal.seller naming another store). */
    public static function ownsSeries(array $stores, string $code): bool {
        $legal = isset($stores[$code]) ? self::legalBlock($stores[$code]) : null;
        if ($legal === null) return false;
        $target = trim((string)($legal['seller'] ?? ''));
        return $target === '' || $target === $code;
    }

    public static function defaultSeller(array $root): ?string {
        $default = (string)($root['default_store'] ?? '');
        $stores = self::storeMap($root);
        if ($default === '' || !isset($stores[$default]) || self::legalBlock($stores[$default]) === null) return null;
        return self::sellerCode($root, $default);
    }

    private static function fallbackSeller(array $root, ?string $currency): ?string {
        $stores = self::storeMap($root);
        if ($currency !== null && $currency !== '') {
            foreach ($stores as $code => $store) {
                if (self::legalBlock($store) === null || strtolower((string)($store['currency'] ?? '')) !== strtolower($currency)) continue;
                $seller = self::sellerCode($root, $code);
                if ($seller !== null) return $seller;
            }
        }
        $default = self::defaultSeller($root);
        if ($default !== null) return $default;
        foreach (array_keys($stores) as $code) {
            if (self::ownsSeries($stores, $code)) return $code;
        }
        return null;
    }
}
