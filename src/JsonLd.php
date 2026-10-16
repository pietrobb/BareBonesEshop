<?php
/**
 * JsonLd — schema.org structured-data builders.
 *
 * Pure array builders (string in, array out) plus a small render() helper that
 * wraps a payload in a CSP-nonce'd <script type="application/ld+json"> tag, so
 * the schema can be unit-tested without an HTTP round-trip.
 *
 *   organization()    Organization (name, url, contact, postal address) of one store
 *   webSite()         WebSite + SearchAction (sitelinks searchbox)
 *   breadcrumbList()  BreadcrumbList (ordered ListItem trail)
 */
class JsonLd {
    /**
     * Wrap a JSON-LD payload in a <script> tag. When $nonce is non-empty the
     * tag carries it, satisfying the P4.2 CSP-nonce policy.
     */
    public static function render(array $data, string $nonce = ''): string {
        $attr = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';
        return '<script type="application/ld+json"' . $attr . '>'
             . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
             . '</script>';
    }

    /**
     * Organization schema for the store $storeId (the page's store; default store when null).
     * $base is the absolute origin (scheme + host, no trailing slash); '' yields a
     * root-relative url. The url is that store's home, including its url_prefix.
     */
    public static function organization(string $base, array $storesCfg, ?string $storeId = null): array {
        $store = self::store($storesCfg, $storeId);
        $legal = $store['legal'] ?? [];

        $org = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => $legal['name'] ?? ($store['name'] ?? 'BareBones Shop'),
            'url'      => self::homeUrl($base, $store),
        ];
        if (!empty($legal['email'])) $org['email']     = $legal['email'];
        if (!empty($legal['phone'])) $org['telephone'] = $legal['phone'];

        $addr = array_filter([
            'streetAddress'   => $legal['street']  ?? '',
            'addressLocality' => $legal['city']    ?? '',
            'postalCode'      => $legal['zip']     ?? '',
            'addressCountry'  => $legal['country'] ?? '',
        ], static fn($v): bool => $v !== '');
        if ($addr) {
            $org['address'] = ['@type' => 'PostalAddress'] + $addr;
        }
        return $org;
    }

    /**
     * WebSite schema with a SearchAction (Google sitelinks searchbox), pointed
     * at the storefront search route ?page=search&q={search_term_string}.
     */
    public static function webSite(string $base, array $storesCfg, ?string $storeId = null): array {
        $store = self::store($storesCfg, $storeId);
        $url   = self::homeUrl($base, $store);

        return [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => $store['legal']['name'] ?? ($store['name'] ?? 'BareBones Shop'),
            'url'      => $url,
            'potentialAction' => [
                '@type'  => 'SearchAction',
                'target' => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => $url . '?page=search&q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * BreadcrumbList from an ordered list of ['name' => string, 'url' => path].
     * Items without a 'url' (e.g. the current leaf) omit the 'item' field.
     * $base is prefixed to each path to produce absolute item URLs.
     */
    public static function breadcrumbList(array $items, string $base = ''): array {
        $base = rtrim($base, '/');
        $list = [];
        $pos  = 1;
        foreach ($items as $it) {
            $entry = [
                '@type'    => 'ListItem',
                'position' => $pos++,
                'name'     => $it['name'] ?? '',
            ];
            if (!empty($it['url'])) {
                $entry['item'] = $base . $it['url'];
            }
            $list[] = $entry;
        }
        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $list,
        ];
    }

    /** The named store config, else the default store, falling back to the first store, then []. */
    private static function store(array $storesCfg, ?string $storeId): array {
        $stores = $storesCfg['stores'] ?? [];
        if ($storeId !== null && isset($stores[$storeId]) && is_array($stores[$storeId])) return $stores[$storeId];
        $key    = $storesCfg['default_store'] ?? '';
        if ($key !== '' && isset($stores[$key])) return $stores[$key];
        return $stores ? (array)reset($stores) : [];
    }

    /** Store home: $base plus the store's url_prefix, always ending in '/'. */
    private static function homeUrl(string $base, array $store): string {
        $prefix = rtrim((string)($store['url_prefix'] ?? ''), '/');
        if ($prefix !== '' && (!str_starts_with($prefix, '/') || str_contains($prefix, '..'))) $prefix = '';
        return rtrim($base, '/') . $prefix . '/';
    }
}
