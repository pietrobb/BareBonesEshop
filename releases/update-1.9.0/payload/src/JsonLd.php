<?php
/**
 * JsonLd — schema.org structured-data builders.
 *
 * Pure array builders (string in, array out) plus a small render() helper that
 * wraps a payload in a CSP-nonce'd <script type="application/ld+json"> tag, so
 * the schema can be unit-tested without an HTTP round-trip.
 *
 *   organization()    Organization (name, url, contact, postal address)
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
     * Organization schema for the default store. $base is the absolute origin
     * (scheme + host, no trailing slash); '' yields a root-relative '/' url.
     */
    public static function organization(string $base, array $storesCfg): array {
        $store = self::defaultStore($storesCfg);
        $legal = $store['legal'] ?? [];
        $base  = rtrim($base, '/');

        $org = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => $legal['name'] ?? ($store['name'] ?? 'BareBones Shop'),
            'url'      => $base !== '' ? $base . '/' : '/',
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
    public static function webSite(string $base, array $storesCfg): array {
        $store = self::defaultStore($storesCfg);
        $base  = rtrim($base, '/');
        $url   = $base !== '' ? $base . '/' : '/';

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

    /** The default store config, falling back to the first store, then []. */
    private static function defaultStore(array $storesCfg): array {
        $stores = $storesCfg['stores'] ?? [];
        $key    = $storesCfg['default_store'] ?? '';
        if ($key !== '' && isset($stores[$key])) return $stores[$key];
        return $stores ? (array)reset($stores) : [];
    }
}
